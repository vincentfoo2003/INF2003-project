<?php
/**
 * create_mysql_indexes.php — Add the composite index the dashboard needs.
 *
 * Index created (idempotent — checked before CREATE, so safe to re-run):
 *
 *   loans(user_id, start_date)   name: idx_loans_user_startdate
 *     Backs dashboard.php's  WHERE user_id = ? ORDER BY start_date DESC.
 *     The existing single-column idx_loans_user filters by user but forces a
 *     separate filesort on start_date (confirmed via EXPLAIN: "Using
 *     filesort"). The composite index lets MySQL read the matching rows
 *     already ordered by start_date, removing the filesort.
 *
 * NOT added (and why):
 *   - loans(payment_id, movie_title): the receipt query is a 1-row lookup per
 *     payment (EXPLAIN showed rows=1), so a composite index adds write cost
 *     for no measurable read benefit.
 *   - Any users/payments index: users.email is already UNIQUE, and the FK
 *     indexes on loans/payments.user_id already exist.
 *
 * Note: MySQL keeps start_date ASC in the index; it serves both ASC and DESC
 * via a forward or backward range scan, so one index covers the DESC sort too.
 *
 * SAFE: one CREATE INDEX, guarded by an existence check. No data changes.
 *
 * Usage: php create_mysql_indexes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "MySQL connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$indexName = 'idx_loans_user_startdate';

echo str_repeat('=', 60) . "\n";
echo "MovieShare — MySQL Index Creation\n";
echo str_repeat('=', 60) . "\n";

try {
    $exists = $pdo->query(
        "SHOW INDEX FROM loans WHERE Key_name = " . $pdo->quote($indexName)
    )->fetchAll();

    if (count($exists) > 0) {
        echo "Index $indexName already exists — nothing to do.\n";
    } else {
        echo "Creating $indexName on loans(user_id, start_date)...\n";
        $start = hrtime(true);
        $pdo->exec("CREATE INDEX `$indexName` ON `loans` (`user_id`, `start_date`)");
        printf("  -> created (%.0f ms)\n", (hrtime(true) - $start) / 1e6);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Index creation failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo str_repeat('-', 60) . "\n";
echo "Indexes now on loans:\n";
$seen = [];
foreach ($pdo->query("SHOW INDEX FROM loans")->fetchAll() as $i) {
    $key = $i['Key_name'];
    $seen[$key][] = $i['Column_name'];
}
foreach ($seen as $name => $cols) {
    printf("  %-28s (%s)\n", $name, implode(', ', $cols));
}

echo str_repeat('=', 60) . "\n";
echo "Done. Re-run the benchmark: BENCHMARK_OUT=~/mysql_benchmark_results.md php mysql_benchmark.php after\n";
