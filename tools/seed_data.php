<?php
/**
 * seed_data.php — Seed realistic MySQL data for benchmarking.
 *
 * Creates:
 *   - USER_COUNT  users with bcrypt-hashed passwords and distinct emails
 *   - LOAN_COUNT  payments + loans, each assigned a RANDOM user and a RANDOM
 *     start_date spread over the last ~2 years
 *
 * Why this shape: optimizations (composite index on loans(user_id, start_date),
 * pagination, FULLTEXT search) only show a measurable effect when user_id is
 * selective (many distinct users) and start_date varies (so ORDER BY has real
 * work). Seeding everything under one user — as the old version did — leaves
 * user_id cardinality at 1 and makes the index useless.
 *
 * Passwords: hashed with bcrypt via password_hash(..., PASSWORD_BCRYPT), the
 * same algorithm register.php uses, so seeded users authenticate normally.
 *
 * SAFETY: this INSERTS a large amount of data. It does NOT drop or truncate
 * anything, so re-running ADDS another batch (emails use a unique run tag to
 * avoid colliding with the UNIQUE(email) constraint). Movie titles/ids are
 * sampled from MongoDB so loans reference real movies.
 *
 * Usage (CLI recommended for 50k rows):
 *   php seed_data.php
 * Browser (heavier, may time out): seed_data.php?confirm=1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/* ----------------------------- config -------------------------------- */
const USER_COUNT    = 1000;    // distinct users to create
const LOAN_COUNT    = 49000;   // payments + loans to ADD (1k already exist → ~50k total)
const MOVIE_SAMPLE  = 1000;    // how many real movies to sample and cycle
const BATCH_SIZE    = 5000;    // commit every N loan/payment pairs
const SEED_PASSWORD = 'Password123!'; // plaintext seeded (hashed before store)
/*
 * bcrypt cost for SEED accounts only. Cost is exponential: each +1 doubles the
 * time. Production (register.php) uses PHP's default (10); these are throwaway
 * test accounts, so a low cost is fine and avoids pinning a constrained VM CPU
 * for many minutes. The result is still a valid bcrypt hash that logs in.
 * Bump this up if you want seed accounts hashed at production strength.
 */
const SEED_BCRYPT_COST = 6;

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !isset($_GET['confirm'])) {
    die('To run this script, use the CLI (php seed_data.php) or add ?confirm=1 in the browser.');
}
$nl = $isCli ? "\n" : "<br>\n";
set_time_limit(0);

function out(string $msg, string $nl): void { echo $msg . $nl; flush(); }

$pdo = db();

/* ---------------- Step 1: sample real movies from MongoDB ------------- */
out("Step 1: sampling " . MOVIE_SAMPLE . " movies from MongoDB...", $nl);
try {
    $collection = mongo_collection();
    $cursor = $collection->aggregate(
        [['$sample' => ['size' => MOVIE_SAMPLE]]],
        ['allowDiskUse' => true]
    );
    $movies = [];
    foreach ($cursor as $doc) {
        $movies[] = [
            'id'    => (string)$doc['_id'],
            'title' => $doc['title'] ?? 'Unknown Movie',
        ];
    }
} catch (Throwable $e) {
    die("MongoDB Error: " . $e->getMessage());
}
if (count($movies) === 0) {
    die("Error: no movies found in MongoDB; cannot seed loans.");
}
out("  sampled " . count($movies) . " movies.", $nl);

/* ---------------- Step 2: create users (bcrypt passwords) ------------- */
/*
 * A per-run tag keeps seeded emails unique across repeated runs so the
 * UNIQUE(email) constraint never collides. bcrypt hashing is intentionally
 * slow, so we hash ONE shared password once per user (1000 hashes).
 */
$runTag = date('ymdHis');
out("Step 2: creating " . USER_COUNT . " users with bcrypt-hashed passwords...", $nl);
out("  (bcrypt is deliberately slow; ~1000 hashes may take a minute)", $nl);

$userIds = [];
try {
    $pdo->beginTransaction();
    $stmtUser = $pdo->prepare(
        'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)'
    );
    for ($i = 1; $i <= USER_COUNT; $i++) {
        $name  = "Seed User {$i}";
        $email = "seed_{$runTag}_{$i}@example.com";
        // bcrypt hash at a low cost for seed accounts (see SEED_BCRYPT_COST)
        $hash  = password_hash(SEED_PASSWORD, PASSWORD_BCRYPT, ['cost' => SEED_BCRYPT_COST]);
        $stmtUser->execute([$name, $email, $hash, 'user']);
        $userIds[] = (int)$pdo->lastInsertId();
        if ($i % 100 === 0) { out("  ... hashed {$i} / " . USER_COUNT . " users", $nl); }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("MySQL Error creating users: " . $e->getMessage());
}
out("  created " . count($userIds) . " users (login password: '" . SEED_PASSWORD . "').", $nl);

/* ---------------- Step 3: create payments + loans -------------------- */
out("Step 3: creating " . LOAN_COUNT . " payments + " . LOAN_COUNT . " loans...", $nl);

$paymentTypes = ['card', 'paypal', 'bank'];
$price        = MOVIE_PRICE;
$twoYears     = 2 * 365 * 24 * 60 * 60;
$made         = 0;

try {
    $stmtPay = $pdo->prepare(
        'INSERT INTO payments (user_id, cost, payment_type, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmtLoan = $pdo->prepare(
        'INSERT INTO loans (payment_id, user_id, movie_id, movie_title, start_date, end_date, price) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    for ($i = 0; $i < LOAN_COUNT; $i++) {
        // random user → selectivity; random date → real sort work
        $userId  = $userIds[array_rand($userIds)];
        $movie   = $movies[$i % count($movies)];
        $payType = $paymentTypes[array_rand($paymentTypes)];

        $ts        = time() - random_int(0, $twoYears);
        $createdAt = date('Y-m-d H:i:s', $ts);
        $startDate = date('Y-m-d', $ts);
        $endDate   = date('Y-m-d', strtotime('+7 days', $ts));

        $stmtPay->execute([$userId, $price, $payType, $createdAt]);
        $paymentId = (int)$pdo->lastInsertId();

        $stmtLoan->execute([
            $paymentId, $userId, $movie['id'], $movie['title'],
            $startDate, $endDate, $price,
        ]);
        $made++;

        // batch commit to bound transaction size / lock time
        if (($i + 1) % BATCH_SIZE === 0) {
            $pdo->commit();
            out("  ... {$made} / " . LOAN_COUNT . " committed", $nl);
            $pdo->beginTransaction();
        }
    }
    if ($pdo->inTransaction()) $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("MySQL Error generating records (after {$made} inserted): " . $e->getMessage());
}

out("", $nl);
out("Done. Created " . count($userIds) . " users, {$made} payments, {$made} loans.", $nl);
out("NOTE: admin_dashboard.php currently fetches ALL loans/payments with no", $nl);
out("LIMIT. With this much data that page will be very heavy until pagination", $nl);
out("is added — hold off opening it, or add a LIMIT first.", $nl);
