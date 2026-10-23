<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;

/* ---------------- App ---------------- */
const SITE_NAME    = 'MovieShare';
const MOVIE_PRICE  = 1.00;
const OMDB_API_KEY = '27c6bf31';
const OMDB_URL     = 'https://www.omdbapi.com/';

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

function mongo_collection(): \MongoDB\Collection
{
    static $collection = null;
    if ($collection === null) {
        $collection = getMongoClient()->selectDatabase('inf2003-moviedb')->selectCollection('movies');
    }
    return $collection;
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
        $stmt = db()->prepare('SELECT user_id, name, email FROM users WHERE user_id = ? LIMIT 1');
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