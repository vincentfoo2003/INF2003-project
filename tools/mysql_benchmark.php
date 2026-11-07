<?php
/**
 * mysql_benchmark.php — Read-only MySQL performance benchmark.
 *
 * Mirrors benchmark.php (the MongoDB one) for the relational side. For each
 * workload it captures two signals:
 *
 *   1. EXPLAIN (FORMAT=JSON) — the server's query plan: access type
 *      (ALL = full scan, ref/range = index use), estimated rows examined, and
 *      whether a filesort / temporary table is used. This is the low-noise,
 *      hardware-independent signal.
 *   2. Averaged wall-clock via hrtime() over N runs (warm-up discarded).
 *
 * The script AUTO-DETECTS whether the composite index
 * idx_loans_user_startdate exists, labelling the phase before/after so the
 * same script works for both captures:
 *
 *   php mysql_benchmark.php before
 *   php mysql_benchmark.php after
 *
 * SAFE: EXPLAIN + SELECT only. No writes, no DDL.
 *
 * Usage: php mysql_benchmark.php [label] [runs]
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

$argLabel = $argv[1] ?? null;
$RUNS     = isset($argv[2]) ? max(1, (int)$argv[2]) : 20;

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "MySQL connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

function ms(float $ns): float { return $ns / 1e6; }
function median(array $xs): float {
    sort($xs); $n = count($xs); if ($n === 0) return 0.0;
    $m = intdiv($n, 2); return ($n % 2) ? $xs[$m] : (($xs[$m-1] + $xs[$m]) / 2.0);
}

/** Time a prepared statement $runs times (plus discarded warm-up). */
function time_query(PDO $pdo, string $sql, array $params, int $runs): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params); $stmt->fetchAll(); // warm-up
    $samples = []; $rows = 0;
    for ($i = 0; $i < $runs; $i++) {
        $t0 = hrtime(true);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        $samples[] = ms((float)(hrtime(true) - $t0));
        $rows = count($data);
    }
    return [
        'rows'   => $rows,
        'min'    => min($samples),
        'median' => median($samples),
        'mean'   => array_sum($samples) / count($samples),
    ];
}

/** Pull plan summary from EXPLAIN FORMAT=JSON. */
function explain_summary(PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare('EXPLAIN FORMAT=JSON ' . $sql);
    $stmt->execute($params);
    $json = $stmt->fetchColumn();
    $plan = json_decode((string)$json, true);

    // Walk the plan tree for the first table's access info.
    $accessType = 'unknown';
    $key = null;
    $rows = null;
    $filesort = false;
    $temp = false;

    // Match the JSON booleans regardless of spacing/nesting the server emits.
    $flat = json_encode($plan);
    if (preg_match('/"using_filesort"\s*:\s*true/', $flat)) $filesort = true;
    if (preg_match('/"using_temporary_table"\s*:\s*true/', $flat)) $temp = true;

    $walk = function ($node) use (&$walk, &$accessType, &$key, &$rows) {
        if (!is_array($node)) return;
        if (isset($node['table']) && is_array($node['table'])) {
            $t = $node['table'];
            if ($accessType === 'unknown' && isset($t['access_type'])) {
                $accessType = $t['access_type'];
                $key  = $t['key'] ?? null;
                $rows = $t['rows_examined_per_scan'] ?? ($t['rows'] ?? null);
            }
        }
        foreach ($node as $v) { if (is_array($v)) $walk($v); }
    };
    $walk($plan);

    return [
        'access'   => $accessType,          // ALL / ref / range / eq_ref / index
        'key'      => $key ?? '(none)',     // index chosen
        'rows'     => $rows,                // est. rows examined
        'filesort' => $filesort,
        'temp'     => $temp,
    ];
}

/* ---- detect phase: does the composite index exist? ---- */
$idxExists = false;
try {
    $r = $pdo->query("SHOW INDEX FROM loans WHERE Key_name = 'idx_loans_user_startdate'")->fetchAll();
    $idxExists = count($r) > 0;
} catch (Throwable $e) { /* ignore */ }

$label = $argLabel ?? ($idxExists ? 'after' : 'before');

/* pick a representative, well-populated user for the dashboard query */
$targetUser = (int)($pdo->query("SELECT user_id FROM loans GROUP BY user_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn() ?: 1);
$loanTotal  = (int)$pdo->query("SELECT COUNT(*) FROM loans")->fetchColumn();
$ver        = $pdo->query('SELECT VERSION()')->fetchColumn();

echo str_repeat('=', 72) . "\n";
echo "MovieShare — MySQL Benchmark\n";
echo str_repeat('=', 72) . "\n";
printf("Server          : %s\n", $ver);
printf("loans rows      : %s\n", number_format($loanTotal));
printf("Composite index : %s\n", $idxExists ? 'PRESENT (idx_loans_user_startdate)' : 'absent');
printf("Phase label     : %s\n", $label);
printf("Target user_id  : %d (heaviest, for selective filter)\n", $targetUser);
printf("Timed runs      : %d\n", $RUNS);
echo str_repeat('-', 72) . "\n";

/* ----------------------------- workloads ------------------------------ */
$workloads = [];

// 1. User dashboard: filter by user + sort by start_date (the key query)
$workloads[] = [
    'name'   => 'Dashboard: loans WHERE user_id ORDER BY start_date DESC',
    'sql'    => 'SELECT l.loan_id, l.movie_title, l.start_date, l.end_date, l.price, p.payment_type
                 FROM loans l JOIN payments p ON l.payment_id = p.payment_id
                 WHERE l.user_id = ? ORDER BY l.start_date DESC',
    'params' => [$targetUser],
];

// 2. Receipt: loans by payment_id sorted by title
$somePayment = (int)($pdo->query("SELECT payment_id FROM loans ORDER BY loan_id LIMIT 1")->fetchColumn() ?: 1);
$workloads[] = [
    'name'   => 'Receipt: loans WHERE payment_id ORDER BY movie_title',
    'sql'    => 'SELECT loan_id, movie_id, movie_title, start_date, end_date, price
                 FROM loans WHERE payment_id = ? ORDER BY movie_title ASC',
    'params' => [$somePayment],
];

// 3. Admin: ALL loans (no filter) — shows indexing can't help a full read
$workloads[] = [
    'name'   => 'Admin: ALL loans LEFT JOIN users ORDER BY loan_id DESC',
    'sql'    => 'SELECT l.loan_id, u.name, l.movie_title, l.start_date, l.end_date, l.price
                 FROM loans l LEFT JOIN users u ON l.user_id = u.user_id
                 ORDER BY l.loan_id DESC',
    'params' => [],
];

$results = [];
foreach ($workloads as $w) {
    $ex = explain_summary($pdo, $w['sql'], $w['params']);
    $tm = time_query($pdo, $w['sql'], $w['params'], $RUNS);
    $results[] = [$w['name'], $ex, $tm];
}

/* ----------------------------- print ---------------------------------- */
printf("%-52s %-6s %-26s %9s %9s\n", 'Workload', 'access', 'key / extra', 'rows', 'wall-med');
echo str_repeat('-', 72) . "\n";
foreach ($results as [$name, $ex, $tm]) {
    $extra = $ex['key'];
    if ($ex['filesort']) $extra .= ' +filesort';
    if ($ex['temp'])     $extra .= ' +temp';
    printf(
        "%-52s %-6s %-26s %9s %8.2f\n",
        substr($name, 0, 52),
        $ex['access'],
        substr($extra, 0, 26),
        $ex['rows'] !== null ? number_format((int)$ex['rows']) : '-',
        $tm['median']
    );
}
echo str_repeat('=', 72) . "\n";
echo "access: ALL=full scan (bad for selective), ref/range=index use, eq_ref=PK.\n";
echo "rows=est. rows examined (lower is better for selective queries).\n";
echo "+filesort = separate sort pass (a composite index can remove it).\n";

/* --------------------------- append report ---------------------------- */
$report = getenv('BENCHMARK_OUT') ?: (is_writable(__DIR__) ? __DIR__ . '/mysql_benchmark_results.md' : rtrim(sys_get_temp_dir(), '/\\') . '/mysql_benchmark_results.md');
$lines = [];
$lines[] = "";
$lines[] = "## MySQL Phase: `$label`  —  " . date('Y-m-d H:i:s');
$lines[] = "";
$lines[] = "- Server: `$ver` | loans: " . number_format($loanTotal)
         . " | composite index: " . ($idxExists ? 'present' : 'absent')
         . " | target user_id: $targetUser | runs: $RUNS";
$lines[] = "";
$lines[] = "| Workload | access | key / extra | est. rows | wall min | wall median | wall mean |";
$lines[] = "|---|---|---|---:|---:|---:|---:|";
foreach ($results as [$name, $ex, $tm]) {
    $extra = $ex['key'];
    if ($ex['filesort']) $extra .= ' +filesort';
    if ($ex['temp'])     $extra .= ' +temp';
    $lines[] = sprintf(
        "| %s | %s | %s | %s | %.2f | %.2f | %.2f |",
        $name, $ex['access'], $extra,
        $ex['rows'] !== null ? number_format((int)$ex['rows']) : '-',
        $tm['min'], $tm['median'], $tm['mean']
    );
}
$lines[] = "";

$hdr = "# MySQL Indexing — Benchmark Results\n";
$ok = @file_put_contents($report, (file_exists($report) ? '' : $hdr) . implode("\n", $lines) . "\n", FILE_APPEND);
if ($ok === false) {
    fwrite(STDERR, "\nCould not write report to: $report (set BENCHMARK_OUT to a writable path)\n");
} else {
    echo "\nAppended results to: $report  (phase: $label)\n";
}
