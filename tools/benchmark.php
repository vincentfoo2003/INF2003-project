<?php
/**
 * benchmark.php — Read-only performance benchmark for the movies collection.
 *
 * Measures loading time and record retrieval for the app's real query
 * workloads, both BEFORE and AFTER indexing, using two independent signals:
 *
 *   1. Server-side explain('executionStats'): executionTimeMillis,
 *      totalDocsExamined, totalKeysExamined, and the winning plan stage
 *      (COLLSCAN / in-memory SORT  vs  IXSCAN). This isolates the database
 *      from PHP and network noise and is the most trustworthy signal.
 *   2. End-to-end wall-clock via hrtime(), averaged over several runs
 *      (warm-up discarded), reporting min / median / mean milliseconds.
 *
 * The script AUTO-DETECTS whether the derived fields (weighted_rating,
 * releaseDateSortable) exist. If they do, it benchmarks the fast indexed
 * find() sorts; if not, it benchmarks the original computed-field
 * aggregation pipelines. So the SAME script works for both phases:
 *
 *   php benchmark.php before      # run against original data/queries
 *   php benchmark.php after       # run after migrate + create_indexes
 *
 * Results are printed and appended to benchmark_results.md with the label.
 *
 * SAFE: performs reads and explains only. No writes, no index changes.
 *
 * Usage:
 *   php benchmark.php [label] [runs] [deepPage]
 *     label    : phase label for the report (default: auto "before"/"after")
 *     runs     : timed iterations per workload (default: 20)
 *     deepPage : page number for the deep-pagination test (default: 500)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/* ----------------------------- CLI args ------------------------------ */
$argLabel    = $argv[1] ?? null;
$RUNS        = isset($argv[2]) ? max(1, (int)$argv[2]) : 20;
$DEEP_PAGE   = isset($argv[3]) ? max(1, (int)$argv[3]) : 500;
$PAGE_SIZE   = 24;

/* --------------------------- small helpers --------------------------- */
function ms(float $ns): float { return $ns / 1e6; }

function median(array $xs): float {
    sort($xs);
    $n = count($xs);
    if ($n === 0) return 0.0;
    $mid = intdiv($n, 2);
    return ($n % 2) ? $xs[$mid] : (($xs[$mid - 1] + $xs[$mid]) / 2.0);
}

/**
 * Run a callable $RUNS times (plus one discarded warm-up) and return timing.
 * $fn must return the number of docs retrieved so we can sanity-check output.
 */
function time_runs(callable $fn, int $runs): array {
    $fn(); // warm-up, discarded (avoids cold-cache skew)
    $samples = [];
    $docCount = 0;
    for ($i = 0; $i < $runs; $i++) {
        $t0 = hrtime(true);
        $docCount = $fn();
        $samples[] = ms((float)(hrtime(true) - $t0));
    }
    return [
        'runs'   => $runs,
        'docs'   => $docCount,
        'min'    => min($samples),
        'median' => median($samples),
        'mean'   => array_sum($samples) / count($samples),
        'max'    => max($samples),
    ];
}

/**
 * Pull executionStats out of a command cursor returned by explain.
 * Works for both find and aggregate explains across server versions.
 */
function summarize_explain($explain): array {
    $doc = json_decode(json_encode($explain), true);

    $execStats = $doc['executionStats']
        ?? ($doc['stages'][0]['$cursor']['executionStats'] ?? null);

    // Aggregation explains can nest executionStats differently; search for it.
    if ($execStats === null) {
        $found = null;
        $walk = function ($node) use (&$walk, &$found) {
            if ($found !== null || !is_array($node)) return;
            if (isset($node['executionTimeMillis']) && isset($node['totalDocsExamined'])) {
                $found = $node; return;
            }
            foreach ($node as $v) { if (is_array($v)) $walk($v); }
        };
        $walk($doc);
        $execStats = $found;
    }

    // Determine the dominant stage by scanning for stage names.
    $stage = 'UNKNOWN';
    $flat = json_encode($doc);
    if (strpos($flat, '"IXSCAN"') !== false)        $stage = 'IXSCAN';
    elseif (strpos($flat, '"COLLSCAN"') !== false)  $stage = 'COLLSCAN';
    $hasInMemorySort = (strpos($flat, '"SORT"') !== false);

    return [
        'timeMs'       => $execStats['executionTimeMillis'] ?? null,
        'docsExamined' => $execStats['totalDocsExamined'] ?? null,
        'keysExamined' => $execStats['totalKeysExamined'] ?? null,
        'nReturned'    => $execStats['nReturned'] ?? null,
        'stage'        => $stage,
        'inMemorySort' => $hasInMemorySort,
    ];
}

/* ------------------- pipeline builders (original form) ------------------- */
function weighted_rating_pipeline(int $skip, int $limit): array {
    return [
        ['$addFields' => [
            'v' => ['$convert' => ['input' => ['$ifNull' => ['$vote_count', 0]], 'to' => 'double', 'onError' => 0, 'onNull' => 0]],
            'R' => ['$convert' => ['input' => ['$ifNull' => ['$rating', 0]], 'to' => 'double', 'onError' => 0, 'onNull' => 0]],
        ]],
        ['$addFields' => [
            'weighted_rating' => ['$cond' => [
                'if' => ['$gt' => ['$v', 0]],
                'then' => ['$add' => [
                    ['$multiply' => [['$divide' => ['$v', ['$add' => ['$v', 500]]]], '$R']],
                    ['$multiply' => [['$divide' => [500, ['$add' => ['$v', 500]]]], 6.5]],
                ]],
                'else' => 0,
            ]],
        ]],
        ['$sort' => ['weighted_rating' => -1, '_id' => 1]],
        ['$skip' => $skip],
        ['$limit' => $limit],
    ];
}

function release_date_pipeline(int $order, int $skip, int $limit): array {
    return [
        ['$addFields' => ['dateParts' => ['$split' => [['$ifNull' => ['$releaseDate', '1/1/1970']], '/']]]],
        ['$addFields' => [
            'parsedYear'  => ['$convert' => ['input' => ['$arrayElemAt' => ['$dateParts', 2]], 'to' => 'int', 'onError' => 1970, 'onNull' => 1970]],
            'parsedMonth' => ['$convert' => ['input' => ['$arrayElemAt' => ['$dateParts', 1]], 'to' => 'int', 'onError' => 1, 'onNull' => 1]],
            'parsedDay'   => ['$convert' => ['input' => ['$arrayElemAt' => ['$dateParts', 0]], 'to' => 'int', 'onError' => 1, 'onNull' => 1]],
        ]],
        ['$sort' => ['parsedYear' => $order, 'parsedMonth' => $order, 'parsedDay' => $order, '_id' => 1]],
        ['$skip' => $skip],
        ['$limit' => $limit],
    ];
}

/* ============================== main ================================= */

$coll = mongo_collection();

/* --- connection sanity check (fail fast with a clear message) --- */
try {
    $t0 = hrtime(true);
    $build = getMongoClient()->getManager()->executeCommand('admin', new MongoDB\Driver\Command(['buildInfo' => 1]));
    $pingMs = ms((float)(hrtime(true) - $t0));
    $version = $build->toArray()[0]->version ?? 'unknown';
    $total = mongo_count();
} catch (Throwable $e) {
    fwrite(STDERR, "Connection check failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Verify MONGODB_URI and DNS before benchmarking.\n");
    exit(1);
}

/* --- detect phase: are the derived/indexed fields present? --- */
$hasWeighted = mongo_count(['weighted_rating' => ['$exists' => true]]) > 0;
$hasSortable = mongo_count(['releaseDateSortable' => ['$exists' => true]]) > 0;
$indexedMode = $hasWeighted && $hasSortable;

$label = $argLabel ?? ($indexedMode ? 'after' : 'before');
$totalPages = max(1, (int)ceil($total / $PAGE_SIZE));
$deepPage   = min($DEEP_PAGE, $totalPages);
$deepSkip   = ($deepPage - 1) * $PAGE_SIZE;

echo str_repeat('=', 68) . "\n";
echo "MovieShare — MongoDB Benchmark\n";
echo str_repeat('=', 68) . "\n";
printf("Server version   : %s  (buildInfo ping %.1f ms)\n", $version, $pingMs);
printf("Documents        : %s\n", number_format($total));
printf("Query mode       : %s\n", $indexedMode ? 'INDEXED find() sorts' : 'ORIGINAL aggregation pipelines');
printf("Phase label      : %s\n", $label);
printf("Timed runs       : %d (plus 1 warm-up, discarded)\n", $RUNS);
printf("Deep page tested : %d of %d (skip %s)\n", $deepPage, $totalPages, number_format($deepSkip));
echo str_repeat('-', 68) . "\n";

$rows = []; // collected for the report

/* ---- Workload 1: weighted-rating sort, first page ---- */
if ($indexedMode) {
    $explain = $coll->explain(new MongoDB\Operation\Find(
        'inf2003-moviedb', 'movies', [],
        ['sort' => ['weighted_rating' => -1, '_id' => 1], 'skip' => 0, 'limit' => $PAGE_SIZE]
    ), ['verbosity' => 'executionStats']);
    $run = fn() => count(mongo_find([], ['sort' => ['weighted_rating' => -1, '_id' => 1], 'skip' => 0, 'limit' => $PAGE_SIZE]));
} else {
    $pipeline = weighted_rating_pipeline(0, $PAGE_SIZE);
    $explain = $coll->explain(new MongoDB\Operation\Aggregate('inf2003-moviedb', 'movies', $pipeline, ['allowDiskUse' => true]), ['verbosity' => 'executionStats']);
    $run = fn() => count(mongo_aggregate(weighted_rating_pipeline(0, $PAGE_SIZE)));
}
$rows[] = ['Weighted rating — page 1', summarize_explain($explain), time_runs($run, $RUNS)];

/* ---- Workload 2: weighted-rating sort, DEEP page ---- */
if ($indexedMode) {
    $explain = $coll->explain(new MongoDB\Operation\Find(
        'inf2003-moviedb', 'movies', [],
        ['sort' => ['weighted_rating' => -1, '_id' => 1], 'skip' => $deepSkip, 'limit' => $PAGE_SIZE]
    ), ['verbosity' => 'executionStats']);
    $run = fn() => count(mongo_find([], ['sort' => ['weighted_rating' => -1, '_id' => 1], 'skip' => $deepSkip, 'limit' => $PAGE_SIZE]));
} else {
    $explain = $coll->explain(new MongoDB\Operation\Aggregate('inf2003-moviedb', 'movies', weighted_rating_pipeline($deepSkip, $PAGE_SIZE), ['allowDiskUse' => true]), ['verbosity' => 'executionStats']);
    $run = fn() => count(mongo_aggregate(weighted_rating_pipeline($deepSkip, $PAGE_SIZE)));
}
$rows[] = ["Weighted rating — page $deepPage (deep)", summarize_explain($explain), time_runs($run, $RUNS)];

/* ---- Workload 3: release-date sort (newest first), first page ---- */
if ($indexedMode) {
    // _id tie-breaker direction matches the date direction so the single
    // ascending index serves this reverse (newest-first) walk — same spec
    // index.php uses for release_desc.
    $explain = $coll->explain(new MongoDB\Operation\Find(
        'inf2003-moviedb', 'movies', [],
        ['sort' => ['releaseDateSortable' => -1, '_id' => -1], 'skip' => 0, 'limit' => $PAGE_SIZE]
    ), ['verbosity' => 'executionStats']);
    $run = fn() => count(mongo_find([], ['sort' => ['releaseDateSortable' => -1, '_id' => -1], 'skip' => 0, 'limit' => $PAGE_SIZE]));
} else {
    $explain = $coll->explain(new MongoDB\Operation\Aggregate('inf2003-moviedb', 'movies', release_date_pipeline(-1, 0, $PAGE_SIZE), ['allowDiskUse' => true]), ['verbosity' => 'executionStats']);
    $run = fn() => count(mongo_aggregate(release_date_pipeline(-1, 0, $PAGE_SIZE)));
}
$rows[] = ['Release date desc — page 1', summarize_explain($explain), time_runs($run, $RUNS)];

/* ---- Workload 4: status = 'Released' filter (api.php) ---- */
$explain = $coll->explain(new MongoDB\Operation\Find(
    'inf2003-moviedb', 'movies', ['status' => 'Released'], ['limit' => 50]
), ['verbosity' => 'executionStats']);
$run = fn() => count(mongo_find(['status' => 'Released'], ['limit' => 50]));
$rows[] = ["status='Released' (limit 50)", summarize_explain($explain), time_runs($run, $RUNS)];

/* ---- Workload 5: single-record lookup by _id (movie.php / poster.php) ---- */
$sample = $coll->findOne([], ['projection' => ['_id' => 1]]);
if ($sample !== null) {
    $sid = $sample['_id'];
    $explain = $coll->explain(new MongoDB\Operation\Find(
        'inf2003-moviedb', 'movies', ['_id' => $sid], []
    ), ['verbosity' => 'executionStats']);
    $run = fn() => (mongo_find_one(['_id' => $sid]) !== null) ? 1 : 0;
    $rows[] = ['findOne by _id', summarize_explain($explain), time_runs($run, $RUNS)];
}

/*
 * Projection comparison: same indexed sort query, FULL document vs PROJECTED
 * to the four fields index.php renders. Projections don't change the query
 * plan (still IXSCAN), so the win is wire size + serialization, not docs
 * examined. We measure payload bytes (the real signal) and wall-clock.
 */
function payload_bytes(array $docs): int {
    // Measure serialized payload size — the real signal for projections.
    // Prefer BSON byte size (what crosses the wire); if a document shape
    // trips BSON reconstruction on this driver version, fall back to the
    // JSON-encoded length, which scales with the same field content and is
    // still a fair relative measure for full-vs-projected comparison.
    $total = 0;
    foreach ($docs as $d) {
        try {
            $total += strlen((string) MongoDB\BSON\Document::fromPHP($d));
        } catch (Throwable $e) {
            $total += strlen(json_encode($d));
        }
    }
    return $total;
}

$projSort = ['weighted_rating' => -1, '_id' => 1];
$projFields = ['title' => 1, 'rating' => 1, 'releaseDate' => 1];

$fullDocs = mongo_find([], ['sort' => $projSort, 'limit' => $PAGE_SIZE]);
$projDocs = mongo_find([], ['sort' => $projSort, 'limit' => $PAGE_SIZE, 'projection' => $projFields]);

$fullBytes = payload_bytes($fullDocs);
$projBytes = payload_bytes($projDocs);

$fullTime = time_runs(fn() => count(mongo_find([], ['sort' => $projSort, 'limit' => $PAGE_SIZE])), $RUNS);
$projTime = time_runs(fn() => count(mongo_find([], ['sort' => $projSort, 'limit' => $PAGE_SIZE, 'projection' => $projFields])), $RUNS);

$projectionResult = [
    'fullBytes'  => $fullBytes,
    'projBytes'  => $projBytes,
    'fullWall'   => $fullTime['median'],
    'projWall'   => $projTime['median'],
    'pageSize'   => $PAGE_SIZE,
];

/* --------------------------- print table ----------------------------- */
printf("%-34s %-9s %12s %10s %10s\n", 'Workload', 'Stage', 'docsExam', 'exec(ms)', 'wall-med');
echo str_repeat('-', 68) . "\n";
foreach ($rows as [$name, $ex, $tm]) {
    printf(
        "%-34s %-9s %12s %10s %9.2f\n",
        substr($name, 0, 34),
        $ex['stage'] . ($ex['inMemorySort'] ? '+SORT' : ''),
        $ex['docsExamined'] !== null ? number_format((int)$ex['docsExamined']) : '-',
        $ex['timeMs'] !== null ? (string)$ex['timeMs'] : '-',
        $tm['median']
    );
}
echo str_repeat('=', 68) . "\n";
echo "Columns: Stage=winning plan (IXSCAN good, COLLSCAN+SORT = full scan),\n";
echo "         docsExam=totalDocsExamined (lower is better),\n";
echo "         exec(ms)=server executionTimeMillis, wall-med=median end-to-end ms.\n";

/* ---- projection comparison summary ---- */
$pr = $projectionResult;
$bytesCut = $pr['fullBytes'] > 0 ? (1 - $pr['projBytes'] / $pr['fullBytes']) * 100 : 0;
echo "\n" . str_repeat('-', 68) . "\n";
echo "Projection effect (same IXSCAN query, {$pr['pageSize']} docs/page):\n";
printf("  Full document : %8s bytes  | wall median %7.2f ms\n", number_format($pr['fullBytes']), $pr['fullWall']);
printf("  Projected (4) : %8s bytes  | wall median %7.2f ms\n", number_format($pr['projBytes']), $pr['projWall']);
printf("  Payload reduced by %.1f%% (fields: _id, title, rating, releaseDate)\n", $bytesCut);
echo str_repeat('-', 68) . "\n";

/* --------------------------- append report --------------------------- */
/*
 * Choose a writable location for the report. Priority:
 *   1. $BENCHMARK_OUT env var, if set (lets you point anywhere writable)
 *   2. the project dir next to this script, if writable
 *   3. the system temp dir as a last resort
 * This avoids the "Permission denied" that occurs when /var/www/html is
 * owned by root/www-data but the CLI runs as your SSH user.
 */
function pick_report_path(): string {
    $envPath = getenv('BENCHMARK_OUT');
    if (is_string($envPath) && $envPath !== '') {
        return $envPath;
    }
    $local = __DIR__ . '/benchmark_results.md';
    if (is_writable(__DIR__) || (file_exists($local) && is_writable($local))) {
        return $local;
    }
    return rtrim(sys_get_temp_dir(), '/\\') . '/benchmark_results.md';
}

$report = pick_report_path();
$lines = [];
$lines[] = "";
$lines[] = "## Phase: `$label`  —  " . date('Y-m-d H:i:s');
$lines[] = "";
$lines[] = "- Server: `$version`  |  Documents: " . number_format($total)
         . "  |  Mode: " . ($indexedMode ? 'indexed find()' : 'original pipelines')
         . "  |  Runs: $RUNS  |  Deep page: $deepPage";
$lines[] = "";
$lines[] = "| Workload | Stage | docsExamined | keysExamined | exec ms | wall min | wall median | wall mean |";
$lines[] = "|---|---|---:|---:|---:|---:|---:|---:|";
foreach ($rows as [$name, $ex, $tm]) {
    $lines[] = sprintf(
        "| %s | %s | %s | %s | %s | %.2f | %.2f | %.2f |",
        $name,
        $ex['stage'] . ($ex['inMemorySort'] ? ' + in-mem SORT' : ''),
        $ex['docsExamined'] !== null ? number_format((int)$ex['docsExamined']) : '-',
        $ex['keysExamined'] !== null ? number_format((int)$ex['keysExamined']) : '-',
        $ex['timeMs'] !== null ? (string)$ex['timeMs'] : '-',
        $tm['min'], $tm['median'], $tm['mean']
    );
}
$lines[] = "";

/* projection comparison block in the report */
$pr = $projectionResult;
$bytesCut = $pr['fullBytes'] > 0 ? (1 - $pr['projBytes'] / $pr['fullBytes']) * 100 : 0;
$lines[] = "**Projection effect** (same IXSCAN query, {$pr['pageSize']} docs/page):";
$lines[] = "";
$lines[] = "| Variant | Payload bytes | wall median ms |";
$lines[] = "|---|---:|---:|";
$lines[] = sprintf("| Full document | %s | %.2f |", number_format($pr['fullBytes']), $pr['fullWall']);
$lines[] = sprintf("| Projected (_id, title, rating, releaseDate) | %s | %.2f |", number_format($pr['projBytes']), $pr['projWall']);
$lines[] = sprintf("| **Reduction** | **%.1f%%** | — |", $bytesCut);
$lines[] = "";

$header = "# MongoDB Indexing — Benchmark Results\n";
$payload = (file_exists($report) ? '' : $header) . implode("\n", $lines) . "\n";

$ok = @file_put_contents($report, $payload, FILE_APPEND);
if ($ok === false) {
    fwrite(STDERR, "\nCould not write report to: $report\n");
    fwrite(STDERR, "The results table above is still valid. To save it, either:\n");
    fwrite(STDERR, "  - set a writable path:  BENCHMARK_OUT=~/benchmark_results.md php benchmark.php $label\n");
    fwrite(STDERR, "  - or copy the printed table into your report manually.\n");
} else {
    echo "\nAppended results to: $report  (phase: $label)\n";
}
