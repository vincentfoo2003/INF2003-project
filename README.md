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
php migrate_movie_fields.php --verify   # backfill derived fields (idempotent)
php create_indexes.php                  # create the three indexes (idempotent)
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
BENCHMARK_OUT=~/benchmark_results.md php benchmark.php before

# after migrate + create_indexes + index.php switch:
BENCHMARK_OUT=~/benchmark_results.md php benchmark.php after
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
