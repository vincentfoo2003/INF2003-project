<?php
/**
 * migrate_movie_fields.php — Backfill derived, index-friendly fields.
 *
 * Adds two stored fields to every document in the movies collection so the
 * app's hot sorts can be served by an index instead of a full-collection
 * in-memory sort:
 *
 *   releaseDateSortable : BSON Date parsed from the "D/M/Y" releaseDate string.
 *                         Enables an indexed sort by release date.
 *   weighted_rating     : double, the Bayesian weighted rating currently
 *                         computed on the fly in index.php. Precomputed here so
 *                         it can be indexed and sorted directly.
 *
 * The weighted rating formula matches index.php exactly:
 *   v = voteCount (>=0), R = rating
 *   if v > 0:  (v/(v+500))*R + (500/(v+500))*6.5
 *   else:      0
 *
 * Date parsing mirrors index.php's $split on "/", interpreting parts as
 * Day / Month / Year. Null, "N/A", or malformed dates fall back to the epoch
 * (1970-01-01) so they sort to the bottom of an ascending sort, matching the
 * pipeline's '1/1/1970' fallback.
 *
 * SAFE & IDEMPOTENT: this is a single server-side updateMany using an
 * aggregation pipeline, so documents are NOT pulled into PHP. Re-running it
 * simply recomputes the same values. It does not drop or rename anything.
 *
 * Usage:
 *   php migrate_movie_fields.php            # migrate all documents
 *   php migrate_movie_fields.php --verify   # migrate, then print sample docs
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$VERIFY = in_array('--verify', $argv, true);

/* ----------------------- connection sanity check ---------------------- */
try {
    $t0 = hrtime(true);
    $build = getMongoClient()->getManager()->executeCommand(
        'admin',
        new MongoDB\Driver\Command(['buildInfo' => 1])
    );
    $version = $build->toArray()[0]->version ?? 'unknown';
    $pingMs  = (hrtime(true) - $t0) / 1e6;
    $coll    = mongo_collection();
    $total   = mongo_count();
} catch (Throwable $e) {
    fwrite(STDERR, "Connection check failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Verify MONGODB_URI and DNS before migrating.\n");
    exit(1);
}

echo str_repeat('=', 60) . "\n";
echo "MovieShare — Field Migration (releaseDateSortable, weighted_rating)\n";
echo str_repeat('=', 60) . "\n";
printf("Server   : %s  (ping %.1f ms)\n", $version, $pingMs);
printf("Documents: %s\n", number_format($total));
echo str_repeat('-', 60) . "\n";

/* ------------------------- the update pipeline ------------------------ */
/*
 * $set stage computes both fields per document, server-side.
 * $let scopes intermediate values so the stage stays readable.
 */
$pipeline = [[
    '$set' => [
        /* ---- releaseDateSortable ---- */
        'releaseDateSortable' => [
            '$let' => [
                'vars' => [
                    'parts' => ['$split' => [
                        ['$ifNull' => ['$releaseDate', '1/1/1970']],
                        '/'
                    ]]
                ],
                'in' => [
                    '$dateFromParts' => [
                        'year' => ['$convert' => [
                            'input'   => ['$arrayElemAt' => ['$$parts', 2]],
                            'to'      => 'int',
                            'onError' => 1970,
                            'onNull'  => 1970,
                        ]],
                        'month' => ['$convert' => [
                            'input'   => ['$arrayElemAt' => ['$$parts', 1]],
                            'to'      => 'int',
                            'onError' => 1,
                            'onNull'  => 1,
                        ]],
                        'day' => ['$convert' => [
                            'input'   => ['$arrayElemAt' => ['$$parts', 0]],
                            'to'      => 'int',
                            'onError' => 1,
                            'onNull'  => 1,
                        ]],
                    ]
                ]
            ]
        ],

        /* ---- weighted_rating (Bayesian, matches index.php) ---- */
        'weighted_rating' => [
            '$let' => [
                'vars' => [
                    'v' => ['$convert' => [
                        'input'   => ['$ifNull' => ['$voteCount', 0]],
                        'to'      => 'double',
                        'onError' => 0,
                        'onNull'  => 0,
                    ]],
                    'r' => ['$convert' => [
                        'input'   => ['$ifNull' => ['$rating', 0]],
                        'to'      => 'double',
                        'onError' => 0,
                        'onNull'  => 0,
                    ]],
                ],
                'in' => [
                    '$cond' => [
                        'if'   => ['$gt' => ['$$v', 0]],
                        'then' => [
                            '$add' => [
                                ['$multiply' => [
                                    ['$divide' => ['$$v', ['$add' => ['$$v', 500]]]],
                                    '$$r'
                                ]],
                                ['$multiply' => [
                                    ['$divide' => [500, ['$add' => ['$$v', 500]]]],
                                    6.5
                                ]],
                            ]
                        ],
                        'else' => 0,
                    ]
                ]
            ]
        ],
    ]
]];

/* ------------------------------ run it -------------------------------- */
echo "Backfilling derived fields (server-side updateMany)...\n";
$start = hrtime(true);
try {
    $result = $coll->updateMany([], $pipeline);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
$elapsed = (hrtime(true) - $start) / 1e6;

printf("Matched  : %s\n", number_format($result->getMatchedCount()));
printf("Modified : %s\n", number_format($result->getModifiedCount()));
printf("Elapsed  : %.0f ms\n", $elapsed);
echo str_repeat('-', 60) . "\n";

/* ------------------------- post-run coverage -------------------------- */
$withSortable = mongo_count(['releaseDateSortable' => ['$exists' => true]]);
$withWeighted = mongo_count(['weighted_rating' => ['$exists' => true]]);
printf("Docs with releaseDateSortable: %s / %s\n", number_format($withSortable), number_format($total));
printf("Docs with weighted_rating    : %s / %s\n", number_format($withWeighted), number_format($total));

if ($withSortable !== $total || $withWeighted !== $total) {
    fwrite(STDERR, "WARNING: not all documents have both fields. Investigate before indexing.\n");
} else {
    echo "All documents carry both derived fields.\n";
}

/* ------------------------------ verify -------------------------------- */
if ($VERIFY) {
    echo str_repeat('-', 60) . "\n";
    echo "Sample documents (sanity check):\n\n";

    $samples = mongo_find(
        [],
        ['limit' => 5, 'projection' => [
            'title' => 1, 'releaseDate' => 1, 'releaseDateSortable' => 1,
            'rating' => 1, 'vote_count' => 1, 'weighted_rating' => 1,
        ]]
    );
    foreach ($samples as $d) {
        $rds = $d['releaseDateSortable'] ?? null;
        $rdsStr = $rds instanceof MongoDB\BSON\UTCDateTime
            ? $rds->toDateTime()->format('Y-m-d')
            : 'n/a';
        printf(
            "  %-40s rawDate=%-12s sortable=%-12s rating=%-5s votes=%-6s weighted=%.4f\n",
            substr((string)($d['title'] ?? 'Unknown'), 0, 40),
            (string)($d['releaseDate'] ?? '-'),
            $rdsStr,
            (string)($d['rating'] ?? '-'),
            (string)($d['vote_count'] ?? '-'),
            (float)($d['weighted_rating'] ?? 0)
        );
    }
    echo "\nCheck that sortable dates match the raw D/M/Y values and\n";
    echo "weighted values look sane (high votes + high rating => higher).\n";
}

echo str_repeat('=', 60) . "\n";
echo "Migration complete. Next: php create_indexes.php\n";
