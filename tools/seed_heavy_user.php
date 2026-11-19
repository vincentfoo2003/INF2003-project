<?php
/**
 * seed_heavy_user.php — Create ONE user with many loans, for benchmarking the
 * composite index (user_id, start_date) where the filesort cost is visible.
 *
 * The composite index's benefit scales with how many loans a SINGLE user has
 * (that is what the ORDER BY start_date filesort processes). With ~50 loans per
 * user the sort is sub-millisecond and the before/after is noise. This inserts
 * HEAVY_COUNT loans (varied start_date over ~3 years) for one fresh user so the
 * filesort has real work, making the index effect measurable.
 *
 * NON-DESTRUCTIVE: only inserts. Prints the new user_id. Clean up afterwards
 * with --cleanup <user_id> (deletes that user; loans/payments cascade).
 *
 * Usage:
 *   php tools/seed_heavy_user.php               # create heavy user + loans
 *   php tools/seed_heavy_user.php --cleanup 1234 # remove that test user
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

const HEAVY_COUNT = 30000;   // loans to create for the test user
const BATCH       = 5000;

$pdo = db();

/* -------- cleanup mode -------- */
$cleanupIdx = array_search('--cleanup', $argv, true);
if ($cleanupIdx !== false) {
    $uid = (int)($argv[$cleanupIdx + 1] ?? 0);
    if ($uid <= 0) { fwrite(STDERR, "Usage: --cleanup <user_id>\n"); exit(1); }
    // Direct delete (bypasses the admin-protection helper, which is fine for a
    // known throwaway test user). loans + payments cascade via FK.
    $stmt = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
    $stmt->execute([$uid]);
    echo $stmt->rowCount() > 0
        ? "Deleted test user $uid (loans + payments cascaded).\n"
        : "No user $uid found.\n";
    exit(0);
}

/* -------- create the heavy user -------- */
echo "Creating a heavy test user with " . number_format(HEAVY_COUNT) . " loans...\n";

$runTag = date('ymdHis');
$email  = "bench_heavy_{$runTag}@example.com";
$hash   = password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 6]);

$pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)')
    ->execute(['Benchmark Heavy User', $email, $hash, 'user']);
$userId = (int)$pdo->lastInsertId();
echo "  user_id = $userId\n";

/* Sample a few real movies to reference (falls back to synthetic if Mongo is
 * unavailable, so this works even without MONGODB_URI). */
$movies = [];
try {
    $cur = mongo_collection()->aggregate([['$sample' => ['size' => 100]]], ['allowDiskUse' => true]);
    foreach ($cur as $d) $movies[] = ['id' => (string)$d['_id'], 'title' => $d['title'] ?? 'Movie'];
} catch (Throwable $e) {
    echo "  (Mongo unavailable; using synthetic movie refs)\n";
}
if (!$movies) {
    for ($m = 0; $m < 100; $m++) {
        $movies[] = ['id' => str_pad(dechex($m), 24, '0', STR_PAD_LEFT), 'title' => 'Sample Movie ' . $m];
    }
}

$price     = MOVIE_PRICE;
$threeYears = 3 * 365 * 24 * 60 * 60;
$stmtPay  = $pdo->prepare('INSERT INTO payments (user_id, cost, payment_type, created_at) VALUES (?, ?, ?, ?)');
$stmtLoan = $pdo->prepare('INSERT INTO loans (payment_id, user_id, movie_id, movie_title, start_date, end_date, price) VALUES (?, ?, ?, ?, ?, ?, ?)');

$pdo->beginTransaction();
for ($i = 0; $i < HEAVY_COUNT; $i++) {
    $movie = $movies[$i % count($movies)];
    $ts    = time() - random_int(0, $threeYears);   // varied start_date → real sort work
    $start = date('Y-m-d', $ts);
    $end   = date('Y-m-d', strtotime('+7 days', $ts));

    $stmtPay->execute([$userId, $price, 'card', date('Y-m-d H:i:s', $ts)]);
    $pid = (int)$pdo->lastInsertId();
    $stmtLoan->execute([$pid, $userId, $movie['id'], $movie['title'], $start, $end, $price]);

    if (($i + 1) % BATCH === 0) {
        $pdo->commit();
        echo "  ... " . number_format($i + 1) . " / " . number_format(HEAVY_COUNT) . "\n";
        $pdo->beginTransaction();
    }
}
if ($pdo->inTransaction()) $pdo->commit();

echo "Done. Heavy user_id = $userId now has " . number_format(HEAVY_COUNT) . " loans.\n";
echo "Benchmark picks the heaviest user automatically, so now run:\n";
echo "  BENCHMARK_OUT=~/mysql_benchmark_results.md php tools/mysql_benchmark.php after\n";
echo "Then drop the index, run 'before', recreate the index (see steps).\n";
echo "Clean up when done:  php tools/seed_heavy_user.php --cleanup $userId\n";
