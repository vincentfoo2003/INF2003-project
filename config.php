<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;

/* ---------------- App ---------------- */
const SITE_NAME    = 'MovieShare';
const MOVIE_PRICE  = 1.00;
const OMDB_API_KEY = '27c6bf31';
const OMDB_URL     = 'https://www.omdbapi.com/';

/* Streaming: put <movie_id>.mp4 files in VIDEO_DIR; movies without a file play DEMO_VIDEO_URL.
   DEMO_VIDEO_URL is a placeholder until the real movie is hardcoded. */
const VIDEO_DIR      = __DIR__ . '/videos';
const DEMO_VIDEO_URL = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4';

/* Google Cloud Storage: videos stream from this bucket via short-lived signed
   URLs. GCS_VIDEO_OBJECT is used for every movie until per-movie objects exist.
   Auth uses Application Default Credentials (the VM's service account, or a key
   file pointed to by GOOGLE_APPLICATION_CREDENTIALS). */
const GCS_BUCKET        = 'inf2003-bucket';
const GCS_VIDEO_OBJECT  = 'placeholder.mp4';
const GCS_URL_TTL       = 3600; // signed-URL lifetime in seconds (capped by rental end)

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

/* =========================================================
   MySQL Helpers
   ========================================================= */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $configPath = '/var/www/private/db-config.ini';
        
        if (file_exists($configPath)) {
            $config = parse_ini_file($configPath);
        } else {
            $config = [
                'servername' => '127.0.0.1',
                'username'   => 'inf1005-sqldev',
                'password'   => 'YourStrongPassword',
                'dbname'     => 'movieshare'
            ];
        }

        $dsn = "mysql:host={$config['servername']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/* =========================================================
   MongoDB Helpers
   ========================================================= */
function getMongoClient(): Client
{
    $uri = $_SERVER['MONGODB_URI'] ?? getenv('MONGODB_URI') ?: null;

    if (!$uri) {
        throw new RuntimeException('MONGODB_URI is not set.');
    }

    return new Client($uri);
}

/** Return any collection in the movie database, cached per name. */
function mongo_db_collection(string $name): \MongoDB\Collection
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $cache[$name] = getMongoClient()->selectDatabase('inf2003-moviedb')->selectCollection($name);
    }
    return $cache[$name];
}

function mongo_collection(): \MongoDB\Collection
{
    return mongo_db_collection('movies');
}

function mongo_find(array $filter, array $options = []): array
{
    return mongo_collection()->find($filter, $options)->toArray();
}

function mongo_find_one(array $filter): ?object
{
    return mongo_collection()->findOne($filter);
}

function mongo_count(array $filter = []): int
{
    return mongo_collection()->countDocuments($filter);
}

function mongo_update_one(array $filter, array $update)
{
    mongo_collection()->updateOne($filter, $update);
}

function mongo_aggregate(array $pipeline): array
{
    // allowDiskUse prevents crashes when sorting 500k+ documents
    return mongo_collection()->aggregate($pipeline, ['allowDiskUse' => true])->toArray();
}

/* =========================================================
   Utilities
   ========================================================= */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/*
 * Render a movie_reviews `date` value uniformly, whatever format it is stored
 * in. Existing/imported reviews use "D/M/Y" (e.g. 22/11/1998); reviews created
 * by the app store ISO "Y-m-d" (which also sorts chronologically as a string).
 * Both are normalised to a single human format, e.g. "22 Nov 1998".
 *
 * Unrecognised/empty values are returned as-is (escaped by the caller), so a
 * bad date never breaks the page.
 */
function format_review_date(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    // ISO: YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    // D/M/Y: day/month/year
    } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
    } else {
        return $raw; // unknown shape — show the raw string rather than guess
    }

    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return $raw;

    $ts = mktime(0, 0, 0, $mo, $d, $y);
    return $ts === false ? $raw : date('j M Y', $ts); // e.g. 22 Nov 1998
}

/* CSRF */
function csrf_token(): string {
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419); exit('Invalid or expired form token. Please reload.');
    }
}

/* Flash */
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = ['msg' => $message, 'type' => $type]; }
function take_flash(): ?array {
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_array($f) ? $f : null;
}

/* Auth */
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT user_id, name, email, role FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row === false) { unset($_SESSION['user_id']); return null; }
        $user = $row;
    }
    return $user;
}

function require_login(): array {
    $user = current_user();
    if ($user === null) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        flash('Please log in to continue.', 'error');
        header('Location: login.php'); exit;
    }
    return $user;
}

/* Number of admin accounts (used to protect the last admin). */
function admin_count(): int {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

/*
 * Decide whether $requesterId may delete the account $targetId, WITHOUT
 * performing the delete. Used by the UI to decide whether to even offer a
 * confirmation. Rules:
 *   - A regular user may delete only their own account.
 *   - An admin may delete any NON-admin account.
 *   - An admin may delete their OWN admin account, but only if they are not
 *     the last admin left.
 *   - No one may delete someone else's admin account.
 *
 * Returns [allowed(bool), reason(string)]. reason explains a denial.
 */
function can_delete_user(int $requesterId, int $targetId): array {
    if ($requesterId <= 0 || $targetId <= 0) {
        return [false, 'Invalid user.'];
    }
    $pdo = db();

    $stmt = $pdo->prepare('SELECT user_id, role FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if ($target === false) {
        return [false, 'That account no longer exists.'];
    }

    $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$requesterId]);
    $requesterRole = $stmt->fetchColumn();
    if ($requesterRole === false) {
        return [false, 'Invalid request.'];
    }

    $isSelf        = ($requesterId === $targetId);
    $targetIsAdmin = ($target['role'] === 'admin');

    // Authorisation: who may even attempt this target?
    if ($requesterRole !== 'admin' && !$isSelf) {
        return [false, 'You can only delete your own account.'];
    }
    if ($targetIsAdmin && !$isSelf) {
        // Admins cannot delete OTHER admin accounts.
        return [false, 'You cannot delete another admin account.'];
    }
    if ($targetIsAdmin && $isSelf && admin_count() <= 1) {
        // The last admin cannot delete themselves.
        return [false, 'You are the last admin and cannot delete your own account.'];
    }
    return [true, ''];
}

/*
 * Delete a user account. $requesterId is who is performing the delete, so the
 * self-vs-other and last-admin rules can be enforced here (the real security
 * boundary — the UI pre-check is only convenience). Re-checks everything
 * can_delete_user() does, inside the same request, before deleting.
 *
 * WARNING: destructive. loans.user_id and payments.user_id use ON DELETE
 * CASCADE, so removing a user also removes all of that user's loans and
 * payments. Irreversible.
 *
 * Returns [ok(bool), message(string)].
 */
function delete_user_account(int $requesterId, int $targetId): array {
    [$allowed, $reason] = can_delete_user($requesterId, $targetId);
    if (!$allowed) {
        return [false, $reason];
    }

    $pdo = db();

    // Guard against a race where the target became the last admin between the
    // check and the delete: only delete admins when more than one exists.
    $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $role = $stmt->fetchColumn();
    if ($role === false) {
        return [false, 'That account no longer exists.'];
    }
    if ($role === 'admin' && admin_count() <= 1) {
        return [false, 'Cannot delete the last admin account.'];
    }

    $del = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
    $del->execute([$targetId]);

    if ($del->rowCount() === 0) {
        return [false, 'Account could not be deleted.'];
    }
    return [true, 'Account deleted.'];
}

/* Cart */
function cart_ids(): array {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) return [];
    $ids = [];
    foreach (array_keys($_SESSION['cart']) as $key) {
        if (is_string($key) && preg_match('/^[a-f0-9]{24}$/i', $key)) $ids[] = $key;
    }
    return $ids;
}

function cart_add(string $movieId): void {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
    $_SESSION['cart'][$movieId] = 1;
}

function cart_remove(string $movieId): void {
    if (isset($_SESSION['cart'][$movieId])) unset($_SESSION['cart'][$movieId]);
}

function cart_movies(): array {
    $ids = cart_ids();
    if (!$ids) return [];
    
    $objectIds = [];
    foreach ($ids as $id) {
        $objectIds[] = new MongoDB\BSON\ObjectId($id);
    }
    
    $filter = ['_id' => ['$in' => $objectIds]];
    $results = mongo_find($filter);
    
    $movies = [];
    foreach ($results as $doc) {
        $movies[] = [
            'movie_id'     => (string)$doc['_id'],
            'title'        => $doc['title'] ?? '',
            'rating'       => $doc['rating'] ?? 0,
            'release_date' => $doc['releaseDate'] ?? '',
            'poster'       => $doc['poster'] ?? null
        ];
    }
    usort($movies, fn($a, $b) => strcmp($a['title'], $b['title']));
    return $movies;
}

function cart_total(array $movies): float {
    return round(count($movies) * MOVIE_PRICE, 2);
}

/* Loans */
function loan_status(array $loan): string {
    $today = date('Y-m-d');
    if ((string)$loan['start_date'] > $today) return 'upcoming';
    if ((string)$loan['end_date'] < $today) return 'expired';
    return 'active';
}

// Loans run through the whole of end_date, so access stops at the following midnight
function loan_ends_at(array $loan): int {
    return (int)strtotime((string)$loan['end_date'] . ' +1 day');
}

function active_loan(int $userId, string $movieId): ?array {
    $today = date('Y-m-d');
    $stmt  = db()->prepare('SELECT loan_id, movie_id, movie_title, start_date, end_date FROM loans
                            WHERE user_id = ? AND movie_id = ? AND start_date <= ? AND end_date >= ?
                            ORDER BY end_date DESC LIMIT 1');
    $stmt->execute([$userId, $movieId, $today, $today]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/* =========================================================
   Google Cloud Storage (signed video URLs)
   ========================================================= */
/**
 * Build a short-lived V4 signed URL for a video object in GCS.
 *
 * Auth uses Application Default Credentials (the GCP VM's attached service
 * account, or a key file via GOOGLE_APPLICATION_CREDENTIALS). With the VM's
 * service account and no key file, signing is delegated to the IAM
 * signBlob API, so the SA needs roles/iam.serviceAccountTokenCreator on
 * itself plus read access to the bucket.
 *
 * $maxTimestamp caps the expiry so a URL can never outlive the rental: the
 * effective lifetime is min(GCS_URL_TTL, time until rental end).
 *
 * Returns the signed URL string, or null on any failure (caller should fall
 * back to DEMO_VIDEO_URL so playback still works in dev).
 */
function gcs_signed_video_url(string $object, ?int $maxTimestamp = null): ?string {
    static $bucket = null;
    try {
        if ($bucket === null) {
            // keyFilePath is honoured automatically if GOOGLE_APPLICATION_CREDENTIALS is set
            $storage = new \Google\Cloud\Storage\StorageClient();
            $bucket  = $storage->bucket(GCS_BUCKET);
        }

        // Effective TTL: never longer than the time left on the rental.
        $ttl = GCS_URL_TTL;
        if ($maxTimestamp !== null) {
            $remaining = $maxTimestamp - time();
            if ($remaining <= 0) return null;          // rental already over
            $ttl = min($ttl, $remaining);
        }

        $url = $bucket->object($object)->signedUrl(
            new \DateTime("+{$ttl} seconds"),
            ['version' => 'v4']
        );
        return (string)$url;
    } catch (Throwable $e) {
        error_log('gcs_signed_video_url: ' . $e->getMessage());
        return null;
    }
}

/* =========================================================
   Posters (OMDb)
   ========================================================= */
function http_get(string $url, int $timeout = 6): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'MovieShare/1.0',
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code >= 400) return null;
        return $raw;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'MovieShare/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : $raw;
}

function omdb_lookup(string $title): array {
    $url = OMDB_URL . '?apikey=' . rawurlencode(OMDB_API_KEY) . '&t=' . rawurlencode($title);
    $raw = http_get($url);
    if ($raw === null) return ['status' => 'error', 'poster' => null];
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['Response'] ?? '') !== 'True') return ['status' => 'ok', 'poster' => null];
    $poster = $data['Poster'] ?? null;
    if (!is_string($poster) || $poster === '' || $poster === 'N/A') return ['status' => 'ok', 'poster' => null];
    return ['status' => 'ok', 'poster' => $poster];
}

function poster_for(object $movie): ?string {
    if (isset($movie['poster']) && $movie['poster'] !== null) {
        return $movie['poster'] !== '' ? (string)$movie['poster'] : null;
    }
    $result = omdb_lookup((string)$movie['title']);
    if ($result['status'] === 'ok') {
        try {
            mongo_update_one(['_id' => $movie['_id']], ['$set' => ['poster' => $result['poster'] ?? '']]);
        } catch (Throwable $ex) {
            error_log('poster cache write failed: ' . $ex->getMessage());
        }
    }
    return $result['poster'];
}

function placeholder_poster(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="450">'
         . '<rect width="100%" height="100%" fill="#dbe2ea"/>'
         . '<text x="50%" y="50%" font-family="Arial" font-size="18" fill="#8b98a7"'
         . ' text-anchor="middle" dominant-baseline="middle">No poster</text></svg>';
    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

function poster_loader_script(): void {
    ?>
<script>
(function () {
    var imgs = document.querySelectorAll('img[data-movie-id]');
    Array.prototype.forEach.call(imgs, function (img) {
        var id = img.getAttribute('data-movie-id');
        if (!id) return;
        fetch('poster.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.poster) {
                    img.src = data.poster;
                    img.removeAttribute('data-movie-id');
                }
            }).catch(function () {});
    });
})();
</script>
    <?php
}

/* =========================================================
   Layout
   ========================================================= */
function page_header(string $title): void {
    $user      = current_user();
    $cartCount = count(cart_ids());
    $flash     = take_flash();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> &middot; <?= e(SITE_NAME) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="index.php">&#127916; <?= e(SITE_NAME) ?></a>
        <nav class="nav">
            <a href="index.php">Movies</a>
            <a href="checkout.php">Cart <span class="pill"><?= $cartCount ?></span></a>
            <?php if ($user !== null): ?>
                <a href="dashboard.php">Dashboard</a>
                <?php if (($user['role'] ?? 'user') === 'admin'): ?>
                    <a href="admin_dashboard.php" style="color: #dc3545; font-weight: bold;">Admin</a>
                <?php endif; ?>
                <span class="who">Hi, <?= e($user['name']) ?></span>
                <form method="post" action="logout.php" class="inline-form">
                    <?= csrf_field() ?><button type="submit" class="link-button">Log out</button>
                </form>
            <?php else: ?>
                <a href="login.php">Log in</a>
                <a class="btn btn-small" href="register.php">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="wrap">
<?php if ($flash !== null): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>
    <?php
}

function page_footer(): void {
    ?>
</main>
<footer class="site-footer">
    <div class="wrap"><p>MovieShare demo &middot; every rental is $<?= number_format(MOVIE_PRICE, 2) ?> &middot; posters via OMDb API</p></div>
</footer>
</body></html>
    <?php
}