<?php
/**
 * create_indexes.php — Create the indexes that back the app's hot queries.
 *
 * Indexes created (idempotent: createIndex is a no-op if an identical index
 * already exists, so this script is safe to re-run):
 *
 *   { weighted_rating: -1, _id: 1 }
 *       Backs index.php's default "Highest rating (Weighted)" sort.
 *       _id is the tie-breaker for stable pagination, matching the sort used
 *       by the app, so the sort is fully covered by the index.
 *
 *   { releaseDateSortable: 1, _id: 1 }
 *       Backs index.php's "Newest / Oldest release date" sorts (both
 *       directions can use this one index; Mongo walks it forward or backward).
 *
 *   { status: 1 }
 *       Backs api.php's  { status: 'Released' }  filter.
 *
 * The default _id_ index already covers movie.php / poster.php (findOne by
 * _id) and cart_movies() ($in on _id), so no action is needed there.
 *
 * PREREQUISITE: run migrate_movie_fields.php first so weighted_rating and
 * releaseDateSortable exist on the documents.
 *
 * Usage: php create_indexes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/* ----------------------- connection sanity check ---------------------- */
try {
    $build = getMongoClient()->getManager()->executeCommand(
        'admin',
        new MongoDB\Driver\Command(['buildInfo' => 1])
    );
    $version = $build->toArray()[0]->version ?? 'unknown';
    $coll    = mongo_collection();
    $total   = mongo_count();
} catch (Throwable $e) {
    fwrite(STDERR, "Connection check failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo str_repeat('=', 60) . "\n";
echo "MovieShare — Index Creation\n";
echo str_repeat('=', 60) . "\n";
printf("Server   : %s\n", $version);
printf("Documents: %s\n", number_format($total));
echo str_repeat('-', 60) . "\n";

/* ----------------------- prerequisite guard --------------------------- */
$missingWeighted = mongo_count(['weighted_rating' => ['$exists' => false]]);
$missingSortable = mongo_count(['releaseDateSortable' => ['$exists' => false]]);
if ($missingWeighted > 0 || $missingSortable > 0) {
    fwrite(STDERR, sprintf(
        "Refusing to index: %s docs missing weighted_rating, %s missing releaseDateSortable.\n",
        number_format($missingWeighted), number_format($missingSortable)
    ));
    fwrite(STDERR, "Run: php migrate_movie_fields.php  first.\n");
    exit(1);
}

/* ----------------------------- the indexes ---------------------------- */
$indexes = [
    [
        'key'  => ['weighted_rating' => -1, '_id' => 1],
        'name' => 'weighted_rating_-1__id_1',
        'why'  => 'index.php default weighted-rating sort',
    ],
    [
        'key'  => ['releaseDateSortable' => 1, '_id' => 1],
        'name' => 'releaseDateSortable_1__id_1',
        'why'  => 'index.php release-date sort (both directions)',
    ],
    [
        'key'  => ['status' => 1],
        'name' => 'status_1',
        'why'  => "api.php status='Released' filter",
    ],
];

foreach ($indexes as $ix) {
    echo "Creating {$ix['name']}  ({$ix['why']})\n";
    $start = hrtime(true);
    try {
        $created = $coll->createIndex($ix['key'], ['name' => $ix['name']]);
        $elapsed = (hrtime(true) - $start) / 1e6;
        printf("  -> ok: %s  (%.0f ms)\n", $created, $elapsed);
    } catch (Throwable $e) {
        fwrite(STDERR, "  -> FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
}

/* --------------------------- list current ----------------------------- */
echo str_repeat('-', 60) . "\n";
echo "Indexes now on the collection:\n";
foreach ($coll->listIndexes() as $index) {
    $key = json_encode($index->getKey(), JSON_UNESCAPED_SLASHES);
    printf("  %-32s key=%s\n", $index->getName(), $key);
}

echo str_repeat('=', 60) . "\n";
echo "Index creation complete. Next: switch index.php to indexed find()\n";
echo "sorts, then re-run: BENCHMARK_OUT=~/benchmark_results.md php benchmark.php after\n";
