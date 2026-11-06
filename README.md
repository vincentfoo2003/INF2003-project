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

### Notes / possible follow-ups

- Deep `$skip` pagination is bounded by the index but still linear in the skip
  distance; range-based pagination (`releaseDateSortable < lastSeen`) would make
  very deep pages constant-time, at the cost of a different pagination UI.
- `index.php` search (regex `$or` across title/overview/genres) still scans to
  match, since a regex filter cannot use these sort indexes. A MongoDB text
  index (or Atlas Search) would be the enhancement there.
