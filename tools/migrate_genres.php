<?php
/**
 * migrate_genres.php — Genre discovery + array backfill for the movies collection.
 *
 * The `genres` field is stored as a comma-separated STRING
 * (e.g. "Action, Science Fiction, Adventure"). That can't be filtered with a
 * multikey index or "match all" semantics. This script:
 *
 *   1. DISCOVERY: reports the 30 most common genres (split on ",", trimmed),
 *      which you then hardcode into index.php's tickbox list.
 *   2. BACKFILL: sets a parallel `genresList` ARRAY field on every movie
 *      (trimmed genre names), so the filter can use a multikey index and $all.
 *
 * Both steps run server-side. The backfill is idempotent (re-running recomputes
 * the same array). Run create_indexes.php afterwards to build the index.
 *
 * Usage:
 *   php migrate_genres.php            # discovery + backfill
 *   php migrate_genres.php --report   # discovery only, no writes
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$REPORT_ONLY = in_array('--report', $argv, true);

try {
    $coll  = mongo_collection();
    $total = mongo_count();
} catch (Throwable $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo str_repeat('=', 60) . "\n";
echo "Genre discovery + genresList backfill\n";
echo str_repeat('=', 60) . "\n";
printf("Movies: %s\n", number_format($total));
echo str_repeat('-', 60) . "\n";

/* ---------------- Step 1: discover the 30 most common genres ---------------- */
/*
 * Split the comma string into parts, trim whitespace off each, drop empties,
 * unwind to one row per genre, group+count, sort desc, take 30.
 */
echo "Top 30 genres:\n";
$pipeline = [
    ['$match' => ['genres' => ['$type' => 'string', '$ne' => '']]],
    ['$project' => [
        'parts' => ['$split' => ['$genres', ',']],
    ]],
    ['$unwind' => '$parts'],
    ['$project' => ['g' => ['$trim' => ['input' => '$parts']]]],
    ['$match' => ['g' => ['$ne' => '']]],
    ['$group' => ['_id' => '$g', 'n' => ['$sum' => 1]]],
    ['$sort' => ['n' => -1, '_id' => 1]],
    ['$limit' => 30],
];

$top = $coll->aggregate($pipeline, ['allowDiskUse' => true])->toArray();

$genreNames = [];
foreach ($top as $i => $row) {
    $name = (string)$row['_id'];
    $genreNames[] = $name;
    printf("  %2d. %-24s %s\n", $i + 1, $name, number_format((int)$row['n']));
}

// Emit a ready-to-paste PHP array for index.php.
echo "\nPHP array for index.php (\$GENRE_OPTIONS):\n";
echo "\$GENRE_OPTIONS = [\n";
foreach ($genreNames as $g) {
    echo "    '" . str_replace("'", "\\'", $g) . "',\n";
}
echo "];\n";

if ($REPORT_ONLY) {
    echo "\n(--report: no changes written)\n";
    exit(0);
}

/* ---------------- Step 2: backfill genresList array on every movie ---------------- */
echo str_repeat('-', 60) . "\n";
echo "Backfilling genresList array (server-side updateMany)...\n";

/*
 * genresList = for each movie, split `genres` on "," and trim each element,
 * dropping empties. Movies with no/!string genres get an empty array.
 */
$update = [[
    '$set' => [
        'genresList' => [
            '$let' => [
                'vars' => [
                    'raw' => [
                        '$cond' => [
                            'if'   => ['$eq' => [['$type' => '$genres'], 'string']],
                            'then' => '$genres',
                            'else' => '',
                        ]
                    ]
                ],
                'in' => [
                    '$filter' => [
                        'input' => [
                            '$map' => [
                                'input' => ['$split' => ['$$raw', ',']],
                                'as'    => 'p',
                                'in'    => ['$trim' => ['input' => '$$p']],
                            ]
                        ],
                        'as'   => 'g',
                        'cond' => ['$ne' => ['$$g', '']],
                    ]
                ]
            ]
        ]
    ]
]];

$start = hrtime(true);
try {
    $res = $coll->updateMany([], $update);
} catch (Throwable $e) {
    fwrite(STDERR, "Backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}
printf("Matched %s, modified %s (%.0f ms)\n",
    number_format($res->getMatchedCount()),
    number_format($res->getModifiedCount()),
    (hrtime(true) - $start) / 1e6
);

$withList = mongo_count(['genresList' => ['$exists' => true]]);
printf("Movies with genresList: %s / %s\n", number_format($withList), number_format($total));

echo str_repeat('=', 60) . "\n";
echo "Done. Next: add the genresList multikey index (create_indexes.php),\n";
echo "then paste the \$GENRE_OPTIONS array above into index.php.\n";
