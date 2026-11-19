# MovieShare

A PHP movie-rental demo. Movie catalogue data lives in MongoDB
(`inf2003-moviedb.movies`); users, loans, and payments live in MySQL.

The MongoDB connection string is read from the `MONGODB_URI` environment
variable (set via Apache `SetEnv` for the web app). To run a CLI script that
needs it, pass it inline or export it in your shell:

```bash
export MONGODB_URI="mongodb+srv://USER:PASS@<cluster>/"
php <script>.php
```

## MongoDB Indexing & Query Optimization

The `movies` collection (~122k documents) is sorted on every page load of
`index.php`. Those sorts used to run as aggregation pipelines that computed the
sort key per document at query time, which no index can serve — so every page
load did a full-collection in-memory sort (`COLLSCAN`). To fix this we store the
sort keys as real fields and index them.

### Derived fields

Two fields are precomputed and stored on each movie document:

| Field | Type | Meaning |
|---|---|---|
| `releaseDateSortable` | BSON `Date` | Parsed from the `releaseDate` string (`"D/M/Y"`). Null / `"N/A"` / malformed dates fall back to `1970-01-01`. |
| `weighted_rating` | `double` | Bayesian weighted rating: `(v/(v+500))*R + (500/(v+500))*6.5`, where `v = voteCount`, `R = rating` (0 when `v = 0`). |

> Note: the source field for vote counts is **`voteCount`** (camelCase). The
> original pipeline read `vote_count` (snake_case), which does not exist, so the
> old "Highest rating" sort was effectively sorting every movie as 0. Storing
> `weighted_rating` from `voteCount` both enables the index and fixes that bug.

### Indexes

| Index | Backs |
|---|---|
| `{ weighted_rating: -1, _id: 1 }` | `index.php` default "Highest rating (Weighted)" sort |
| `{ releaseDateSortable: 1, _id: 1 }` | `index.php` "Newest" and "Oldest" release-date sorts (both directions) |
| `{ status: 1 }` | `api.php` `{ status: 'Released' }` filter |

The default `_id_` index already covers `movie.php` / `poster.php` (findOne by
`_id`) and the cart (`$in` on `_id`), so no extra index is needed there.

**Sort-direction gotcha:** a single ascending index serves *both* release-date
directions only if the `_id` tie-breaker direction matches the date direction.
`index.php` uses `{ releaseDateSortable: 1, _id: 1 }` for "oldest" (forward walk)
and `{ releaseDateSortable: -1, _id: -1 }` for "newest" (full reverse walk of the
same index). Using `_id: 1` with a descending date is **not** a pure reverse of
the index and forces an in-memory sort.

### Run order (after any data load / re-seed)

```bash
php tools/migrate_movie_fields.php --verify   # backfill derived fields (idempotent)
php tools/create_indexes.php                  # create the three indexes (idempotent)
```

Both scripts are safe to re-run; they recompute / no-op rather than duplicate.

### Keeping new data in sync

`releaseDateSortable` and `weighted_rating` are **derived** values. Any ingestion
that inserts new movies or changes `releaseDate`, `rating`, or `voteCount` must
also populate these two fields — or simply re-run `migrate_movie_fields.php`
afterward (it recomputes every document). The app's only live MongoDB write is
the poster-cache update, which does not touch these fields.

### Benchmarking

`benchmark.php` measures the real query workloads via both server-side
`explain('executionStats')` and averaged end-to-end wall-clock. It auto-detects
whether the derived fields exist, so it benchmarks the original pipelines before
migration and the indexed `find()` sorts after.

```bash
# before indexing (original data/queries):
BENCHMARK_OUT=~/benchmark_results.md php tools/benchmark.php before

# after migrate + create_indexes + index.php switch:
BENCHMARK_OUT=~/benchmark_results.md php tools/benchmark.php after
```

Results append to the file named by `BENCHMARK_OUT` (defaults to a writable
path; `/var/www/html` is not writable by the CLI user, so set this explicitly).

#### Measured result (~122k documents)

| Workload | Before | After | Docs examined |
|---|---|---|---|
| Weighted rating — page 1 | COLLSCAN, ~224 ms | IXSCAN, ~0 ms | 121,908 → 24 |
| Weighted rating — page 500 (deep) | COLLSCAN, ~224 ms | IXSCAN, ~5 ms | 121,908 → 24 |
| Release date (newest) — page 1 | COLLSCAN, ~260 ms | IXSCAN, ~0 ms | 121,908 → 24 |
| `status='Released'` | COLLSCAN | IXSCAN | plan improved |
| findOne by `_id` | IDHACK (already indexed) | unchanged | 1 → 1 |

Server-side execution time for the sorts dropped from ~220–260 ms to 0–5 ms, and
documents examined fell from the full collection to the page size. The remaining
end-to-end time is network round-trip to the Atlas cluster, not query cost.

### Projections (returning only needed fields)

Per the MongoDB query-optimization guidance, `index.php` now projects each movie
card down to the four fields it renders (`_id`, `title`, `rating`,
`releaseDate`). Previously every result shipped the full document — including the
large `overview`, `keywords`, and `production_companies` strings that the list
view never displays. A projection does not change the query plan (it is still an
`IXSCAN`); the gain is reduced payload size and serialization, i.e. less data
over the network per page.

`benchmark.php` prints a "Projection effect" block comparing the same indexed
query with full documents vs projected, reporting BSON payload bytes and
wall-clock for a 24-doc page.

#### Measured projection effect (24-doc page)

Measured on the ~122k-document collection (Atlas, 20 runs):

| Variant | Payload bytes | wall median (ms) |
|---|---:|---:|
| Full document | 26,415 | 157.06 |
| Projected (`_id`, `title`, `rating`, `releaseDate`) | 2,329 | 155.72 |
| **Reduction** | **91.2%** | ~1 ms (within noise) |

The payload dropped by 91% because the full documents carry large
`overview`, `keywords`, and `production_companies` text that the card view
never displays. Wall-clock barely changed: at 24 docs per page the ~24 KB saved
is small next to the ~155 ms Atlas round-trip, so the projection's benefit here
is **bandwidth, not latency**. The saving scales with page size, result count,
slower client networks, and request volume.

### Movie reviews (second collection) + compound index

The movie detail page (`movie.php`) shows reviews from a separate MongoDB
collection, `movie_reviews` (~427,000 documents). Each review references its
movie by the `title` string, so `movie.php` runs:

```php
$reviewsCol->find(['title' => $title], ['sort' => ['date' => -1], 'limit' => 20]);
```

That is an equality filter on `title` plus a sort on `date` — a textbook case
for a compound index following the ESR rule (Equality, then Sort). The index
`movie_reviews { title: 1, date: -1 }` (added by `tools/create_indexes.php`)
serves both the filter and the sort from the index.

#### Measured result (990,586 reviews, most-reviewed title)

| | Before (no index) | After (`title_1_date_-1`) |
|---|---|---|
| Plan | COLLSCAN + in-memory SORT | IXSCAN |
| Documents examined | 990,586 (whole collection) | 20 |
| Server exec time | ~344 ms | ~0 ms |
| Wall-clock median | ~557 ms | ~154 ms |

This is the clearest indexing win in the project. Because the collection is
large (~990k docs), the improvement shows at **both** layers: server execution
dropped from ~344 ms to effectively 0 (the full collection scan + in-memory sort
disappeared, examining 20 documents instead of 990,586), and the
network-dominated wall-clock dropped ~3.6x — the ~344 ms of real query work was
removed, leaving only the Atlas round-trip. Unlike the smaller before/after
cases where timing was noise, here the dataset is big enough that the latency
win is large end-to-end.

> The reviews block in `movie.php` is wrapped in its own try/catch, so a reviews
> query failure degrades to "no reviews" rather than breaking the movie page.
> Reviews are matched by exact `title` string (the only link the data provides),
> so the match is case- and whitespace-sensitive.

### Genre filter (multikey index on an array field)

`index.php` has a genre tickbox filter that shows movies matching **all** the
ticked genres. The `genres` field is stored as a comma-separated **string**
(e.g. "Action, Science Fiction, Adventure"), which cannot support "match all"
semantics or a useful index. So `tools/migrate_genres.php` backfills a parallel
`genresList` **array** field (trimmed genre names), and the filter queries it
with `$all`:

```php
['genresList' => ['$all' => $selectedGenres]]   // e.g. ["Drama", "Crime"]
```

An index on an array field is a **multikey index** — MongoDB stores one index
entry per array element — so `genresList_1` ({ genresList: 1 }) serves the
`$all` filter. The same script also reports the 30 most common genres, which are
hardcoded as the tickbox options (the dataset has 19 distinct genres).

#### Measured result

Measured with `explain('executionStats')` on a deliberately **rare** genre
combination with no `limit`, so a collection scan cannot terminate early and
must examine the whole collection — the fair test of the index:

| | Before (no index) | After (`genresList_1`) |
|---|---|---|
| Plan | COLLSCAN | IXSCAN → FETCH |
| Documents examined | 121,908 (whole collection) | 3,447 |
| Server exec time | ~397 ms | ~21 ms |

A ~19x server-side improvement. Without the index MongoDB scanned all 121,908
documents; the multikey index narrowed the candidates to the ~3,447 tagged with
the first `$all` term, then filtered those for the full match.

> Measurement caveat: a *common* genre combination with a small `limit` (e.g.
> `["Drama","Crime"]` limit 24) is NOT a fair test — the scan finds 24 matches
> after ~300 docs and stops, so it looks as fast as the index. The index's value
> shows when the query is selective enough that a scan must traverse most of the
> collection.

### Measuring a real record: server-side vs client-side

The two layers are measured with different tools, because a CLI script has no
browser and a browser cannot see server-internal query time.

**Server-side** (query + driver round-trip) — from `benchmark.php`, or inline
around a specific lookup. Example for one real movie detail load
(`movie.php?id=<id>` queries `findOne({_id})`):

```php
// quick inline timing of a single-record load
$t0 = hrtime(true);
$movie = mongo_find_one(['_id' => new MongoDB\BSON\ObjectId($id)]);
$serverMs = (hrtime(true) - $t0) / 1e6;
error_log("findOne by _id: {$serverMs} ms");
```

**Client-side** (browser render) — paste this in the browser DevTools console
after the page loads. It reads the Navigation Timing API, so the numbers are the
browser's own measurements, not a stopwatch:

```js
const t = performance.getEntriesByType('navigation')[0];
console.table({
  'TTFB (ms)':        Math.round(t.responseStart - t.requestStart),
  'Response (ms)':    Math.round(t.responseEnd - t.responseStart),
  'DOM ready (ms)':   Math.round(t.domContentLoadedEventEnd - t.startTime),
  'Full load (ms)':   Math.round(t.loadEventEnd - t.startTime),
});
```

#### Example: real record before/after

> Pick one real movie (record its `_id` and title) and fill in both columns.
> Capture "before" by temporarily reverting `index.php` to the original
> pipelines (or using `hint()` to force a COLLSCAN), and "after" with the
> indexes in place.

Measured on `index.php` (default weighted-rating sort, page 1). Server-side
figures come from `benchmark.php`; client-side figures from the browser
Navigation Timing API (warm poster cache).

Before was captured against the original computed-field pipeline (full COLLSCAN
+ in-memory sort); after against the indexed path. Both on a warm poster cache.

| Measurement | Layer | Before (pipelines, no index) | After (indexed + projection) | Change |
|---|---|---:|---:|---:|
| List page query (weighted sort, page 1) | server | ~224 ms, COLLSCAN, 121,908 docs examined | ~0 ms, IXSCAN, 24 docs examined | −224 ms |
| TTFB (time to first byte) | client | 1091 ms | 730 ms | −361 ms (−33%) |
| Response transfer | client | 3 ms | 2 ms | ~same |
| DOM ready | client | 1479 ms | 1114 ms | −365 ms (−25%) |
| Full page load | client | 1511 ms | 1150 ms | −361 ms (−24%) |

Reading the numbers honestly:

- The **TTFB drop of ~361 ms is the indexing win**, and it matches the
  server-side measurement: the old pipeline spent ~220–360 ms on the
  full-collection scan and in-memory sort, which is exactly what disappeared
  from the time-to-first-byte.
- The DOM-ready and full-load improvements are the **same saved time carried
  downstream** — once the server responds ~361 ms sooner, every later milestone
  shifts earlier by roughly the same amount. Browser parsing and poster loading
  themselves did not change, which is expected.
- The residual ~730 ms TTFB is network round-trip to the Atlas cluster and the
  VM plus PHP startup — not query cost, and not something indexing can remove.

> Caveat for the client-side rows: movie cards lazy-load posters via
> `poster.php`, which on a cache miss calls the external OMDb API. Those
> requests add large, variable latency unrelated to indexing, so these numbers
> were taken on a warm poster cache (page loaded once first). TTFB and DOM-ready
> are the most reliable rows since they complete before posters finish loading.
> The "before" figures were captured with a temporary `?legacy=1` toggle that
> forced the original pipeline on the same deployed file; that toggle has since
> been removed.

### Notes / possible follow-ups

- Deep `$skip` pagination is bounded by the index but still linear in the skip
  distance; range-based pagination (`releaseDateSortable < lastSeen`) would make
  very deep pages constant-time, at the cost of a different pagination UI.
- `index.php` search (regex `$or` across title/overview/genres) still scans to
  match, since a regex filter cannot use these sort indexes. A MongoDB text
  index (or Atlas Search) would be the enhancement there.

---

## MySQL Indexing & Pagination

The relational side (`users`, `loans`, `payments`) stores accounts and rental
records. Unlike the movie catalogue, most useful single-column indexes already
existed — InnoDB auto-creates them for the foreign keys, and `users.email` is
already `UNIQUE`. So MySQL optimization here is about two specific things a plain
FK index does not cover: a **composite index** for a filter-plus-sort query, and
**pagination** for queries that return everything.

Benchmarked on ~50,000 loans / 50,000 payments spread across ~1,000 users
(seeded via `seed_data.php`). Measurement mirrors the MongoDB side:
`EXPLAIN FORMAT=JSON` for the plan (`mysql_benchmark.php`) plus browser
Navigation Timing for page-level numbers.

### Already-optimal (no change)

| Query | Why it is already fine |
|---|---|
| `login` / `register`: `users WHERE email = ?` | `email` is a `UNIQUE` index |
| `current_user`: `users WHERE user_id = ?` | primary key |
| `receipt`: `loans WHERE payment_id = ?` | FK index `idx_loans_payment`; EXPLAIN shows a 1-row lookup, so no composite index was added |
| JOINs on `payment_id` / `user_id` | FK indexes already present |

### Optimization 1 — composite index for the user dashboard

`dashboard.php` runs:

```sql
SELECT ... FROM loans l JOIN payments p ON l.payment_id = p.payment_id
WHERE l.user_id = ? ORDER BY l.start_date DESC
```

The existing `idx_loans_user` filters by user but does **not** cover the
`ORDER BY start_date`, so MySQL did a separate filesort. The composite index
`loans(user_id, start_date)` (created by `create_mysql_indexes.php`) covers both.

Measured with `EXPLAIN ANALYZE` on a user with **30,000 loans** (so the sort has
real work), which reports actual server-side execution time per plan step —
isolating query cost from the ~140 ms network round-trip that otherwise hides it
in end-to-end wall-clock:

| | Before (no composite index) | After (composite index) |
|---|---|---|
| Plan | Index lookup on `idx_loans_user`, then a `Sort` node | Covering index scan on `idx_loans_user_startdate` (reverse) |
| Filesort | Yes (`Sort: l.start_date DESC`) | None |
| Server-side actual time | **~54 ms** | **~15 ms** |
| Rows | 30,000 | 30,000 |

A real **~3.5x** server-side improvement. The `EXPLAIN ANALYZE` plans show why:

```
BEFORE:
-> Sort: l.start_date DESC  (actual time=52.3..54 rows=30000)
    -> Index lookup on l using idx_loans_user (user_id=1694)  (actual time=0.245..41.2 rows=30000)

AFTER:
-> Covering index lookup on l using idx_loans_user_startdate (user_id=1694) (reverse)
   (actual time=0.0858..15.3 rows=30000)
```

Two effects combine:

1. **Filesort eliminated.** The before-plan has an explicit `Sort` node (the
   filesort) that pushes total time to ~54 ms; the after-plan has none, because
   the composite index already stores rows in `(user_id, start_date)` order and
   is read in reverse for `start_date DESC`.
2. **Covering index.** The after-plan is a *covering* index scan — the query
   needs only `loan_id` and `start_date`, both available from the index (plus
   the implicit PK), so MySQL answers entirely from the index without reading
   table rows.

Note on measurement: the end-to-end benchmark (`mysql_benchmark.php`) showed
~143 ms for this query in both states, because that wall-clock is dominated by
the network round-trip and the cost of transferring 30k rows to PHP — the ~40 ms
of sort work is invisible against it. `EXPLAIN ANALYZE` strips that away and
exposes the actual difference. At the typical ~50 loans per user the filesort is
sub-millisecond either way, so this gain scales with how many loans a single
user has.

> MySQL stores the index `start_date` ascending but serves the `DESC` sort via a
> backward range scan, so one index covers both sort directions.

### Optimization 2 — pagination (the bigger win)

`admin_dashboard.php` showed **all** users, loans, and payments with no `LIMIT`.
With 50k rows this is a problem no index can fix — the query returns everything,
so there is nothing to narrow. The fix is `LIMIT`-based pagination (25 rows per
list, independent page params). Pagination was also added to the user dashboard
(20 per page).

Admin dashboard, before (`?nolimit=1` toggle) vs after (paginated):

| Measurement | Before (no LIMIT) | After (LIMIT 25) |
|---|---:|---:|
| Server query work (COUNT + page query) | ~169 ms | **13.9 ms** |
| TTFB (client) | 647 ms | 218 ms |
| DOM ready (client) | never completed | 596 ms |
| Full load (client) | never completed | 727 ms |
| Rows rendered | ~99,000 (loans + payments) | 50 |

The "before" page **never finished rendering** — the server responded, but the
browser could not build a DOM of ~99k table rows (each with inline edit forms)
in any usable time. Pagination turned an unusable page into one that loads in
well under a second. This is the clearest MySQL win: indexes could not help a
full-table read, but bounding the result set with `LIMIT` did.

### Scripts & run order (MySQL)

```bash
php tools/seed_data.php                                   # seed benchmarkable data (one-time)
BENCHMARK_OUT=~/mysql_benchmark_results.md php tools/mysql_benchmark.php before
php tools/create_mysql_indexes.php                        # add the composite index
BENCHMARK_OUT=~/mysql_benchmark_results.md php tools/mysql_benchmark.php after
```

`create_mysql_indexes.php` is idempotent (checks before creating). It requires a
DB account with the `INDEX` privilege — the app user may not have it, so the
index may need to be created by an admin account; the script then just reports
it already exists.

### Notes / possible follow-ups (MySQL)

- The dashboard search (`movie_title LIKE '%term%'`) has a leading wildcard, so
  it cannot use a B-tree index — the same limitation as the MongoDB regex
  search. A `FULLTEXT` index on `movie_title` would be the enhancement.
- `COUNT(*)` for page totals is ~14 ms on a 50k table here; at much larger scale
  a cached or approximate count would avoid counting on every page load.
- Deep `OFFSET` pagination (e.g. page 2000) is linear in the offset; keyset
  pagination (`WHERE loan_id < lastSeen`) would keep deep pages fast.
- Seed accounts are hashed with bcrypt at a reduced cost (`SEED_BCRYPT_COST`)
  purely so seeding is fast; real accounts via `register.php` use PHP's default
  (stronger) cost.

---

## Video Streaming from Google Cloud Storage

Rented movies are watched on `watch.php`, which embeds a `<video>` player
pointed at `stream.php`. Videos live in a **private** GCS bucket
(`inf2003-bucket`); `stream.php` serves them via short-lived **signed URLs**
rather than proxying the bytes through the web server.

### How it works

1. `stream.php` checks the user has an **active rental** for the movie
   (`active_loan()`), exactly as before — this is the access gate.
2. It then generates a V4 **signed URL** for the object in GCS
   (`gcs_signed_video_url()` in `config.php`) and 302-redirects the player to
   it. The browser streams directly from Google (range requests / seeking
   handled natively by GCS), so no video bytes pass through the VM.
3. The signed URL's lifetime is capped at the rental end:
   `min(GCS_URL_TTL, time until end_date)`, so a URL can never outlive the loan.

Config constants (in `config.php`): `GCS_BUCKET`, `GCS_VIDEO_OBJECT` (currently
every movie plays the shared `placeholder.mp4`), `GCS_URL_TTL`.

### Authentication (keyless, via the VM service account)

Signing uses Application Default Credentials — the GCP VM's attached service
account — with **no key file** (the project enforces
`constraints/iam.disableServiceAccountKeyCreation`, so key files are not an
option). Keyless V4 signing delegates to the IAM Credentials `signBlob` API.
Setup that was required:

- `google/cloud-storage` installed via Composer into the app's `vendor/`.
- Enable the IAM Service Account Credentials API:
  `gcloud services enable iamcredentials.googleapis.com`.
- Grant the VM service account the ability to sign on its own behalf:
  `roles/iam.serviceAccountTokenCreator` on itself.
- Grant it read on the bucket: `roles/storage.objectViewer` on
  `gs://inf2003-bucket`.

No `GOOGLE_APPLICATION_CREDENTIALS` is set — on a GCP VM the metadata server
supplies the service-account credentials to the PHP process automatically.

Quick signing test (CLI):

```bash
php -r 'require "config.php"; var_dump(gcs_signed_video_url(GCS_VIDEO_OBJECT, time()+600));'
```

### Design notes / tradeoffs

- **Why signed URLs (not proxying):** the server does no bandwidth/CPU work per
  stream, GCS handles seeking, and the bucket stays private. This is how real
  streaming services serve protected media.
- **Rental cutoff is softer than local streaming.** The previous local-file
  path killed the stream per-chunk the instant the rental clock passed. With a
  signed URL, once issued it is valid until expiry — capped at the rental end,
  so worst case a viewer keeps playing until `end_date +1 day` midnight. The
  `watch.php` client-side timer still stops playback at the deadline. This is the
  inherent tradeoff of the signed-URL approach (scalability) vs proxying (tight,
  immediate control).
- **Fallbacks:** if signing fails, `stream.php` falls back to a local
  `VIDEO_DIR/<id>.mp4` file, then to `DEMO_VIDEO_URL`, so playback still works in
  development without GCS configured.
- **Per-movie videos:** every movie currently streams `placeholder.mp4`. To use
  real per-movie files, name objects by movie id and build `GCS_VIDEO_OBJECT`
  from the id instead of the constant.

---

## Account Deletion

Users and admins can delete accounts. Deletion is a **hard delete**: the
`loans.user_id` and `payments.user_id` foreign keys use `ON DELETE CASCADE`, so
removing a user also permanently removes all of that user's loans and payments.
This is irreversible, so every path requires an explicit confirmation.

### Who can delete whom

| Actor | Target | Allowed? |
|---|---|---|
| Regular user | own account | yes |
| Regular user | any other account | no |
| Admin | any non-admin user | yes (from the admin dashboard) |
| Admin | another admin's account | no |
| Admin | own admin account | yes, **from their own dashboard only**, and **only if not the last admin** |
| Last remaining admin | own account | no — rejected |

Self-deletion (including an admin's own account) happens on the personal
dashboard (`delete_account.php`). The admin dashboard's user table only deletes
**other** users; it never shows or accepts a delete of the acting admin's own
row.

### Where the rules are enforced

The logic lives in one place, `config.php`:

- `admin_count()` — number of admin accounts.
- `can_delete_user($requesterId, $targetId)` — a side-effect-free check of all
  the rules above. The UI calls it **before** rendering a delete control, so a
  forbidden deletion (e.g. the last admin deleting themselves) never even
  reaches a confirmation dialog.
- `delete_user_account($requesterId, $targetId)` — re-runs the same checks and
  re-counts admins inside the request, then performs the delete. This is the
  real security boundary: a forged POST that bypasses the UI is still rejected,
  and the admin re-count guards against a race where two admins delete at once.

The "last admin" count is checked both before showing the button and again at
delete time, so the system can never be left with zero admins.
