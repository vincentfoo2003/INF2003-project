<?php
require_once __DIR__ . '/config.php';

$user = require_login();
$id   = strtolower((string)($_GET['id'] ?? ''));
if (!preg_match('/^[a-f0-9]{24}$/', $id)) { header('Location: dashboard.php'); exit; }

$loan = active_loan((int)$user['user_id'], $id);
if ($loan === null) {
    flash('You need an active rental to watch that movie.', 'error');
    header('Location: movie.php?id=' . urlencode($id)); exit;
}

try {
    $movie = mongo_find_one(['_id' => new MongoDB\BSON\ObjectId($id)]);
} catch (Throwable $ex) {
    error_log('watch.php: ' . $ex->getMessage());
    $movie = null;
}

$title    = (string)($movie['title'] ?? $loan['movie_title']);
$daysLeft = (new DateTime('today'))->diff(new DateTime((string)$loan['end_date']))->days;
$secondsLeft = max(0, loan_ends_at($loan) - time());

// Other movies this user can watch right now
$today = date('Y-m-d');
$stmt  = db()->prepare('SELECT movie_id, movie_title, MAX(end_date) AS end_date FROM loans
                        WHERE user_id = ? AND start_date <= ? AND end_date >= ? AND movie_id <> ?
                        GROUP BY movie_id, movie_title ORDER BY movie_title ASC');
$stmt->execute([(int)$user['user_id'], $today, $today, $id]);
$others = $stmt->fetchAll();

page_header('Watch ' . $title);
?>
<a class="back-link" href="dashboard.php">&larr; My dashboard</a>

<div class="watch-layout">
    <section>
        <div class="player-wrap">
            <video id="player" class="player" controls preload="metadata" playsinline
                   src="stream.php?id=<?= e($id) ?>">
                Your browser does not support HTML5 video.
            </video>
        </div>

        <h1 class="watch-title"><?= e($title) ?></h1>
        <p class="detail-meta">
            <?php if ($movie !== null): ?>
                <span class="rating">&#9733; <?= e(number_format((float)($movie['rating'] ?? 0), 1)) ?></span>
                <span class="dot">&middot;</span>
                <span><?= e($movie['releaseDate'] ?? 'N/A') ?></span>
                <span class="dot">&middot;</span>
                <span><?= (int)($movie['runtime'] ?? 0) ?> min</span>
                <span class="dot">&middot;</span>
            <?php endif; ?>
            <span class="badge badge-active">
                <?= $daysLeft === 0 ? 'Last day of rental' : 'Rental ends ' . e(date('j M Y', strtotime((string)$loan['end_date']))) . ' (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)' ?>
            </span>
        </p>
        <?php if (!empty($movie['overview'])): ?>
            <p class="overview"><?= e($movie['overview']) ?></p>
        <?php endif; ?>
    </section>

    <aside class="panel">
        <h2>Also on your list</h2>
        <?php if (!$others): ?>
            <p class="muted">No other active rentals. <a href="index.php">Find something else to watch</a>.</p>
        <?php else: ?>
            <ul class="up-next">
                <?php foreach ($others as $o): ?>
                    <li>
                        <a href="watch.php?id=<?= e($o['movie_id']) ?>">&#9654; <?= e($o['movie_title']) ?></a>
                        <span class="muted">until <?= e(date('j M', strtotime((string)$o['end_date']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>

<div id="rental-ended" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rental-ended-title" hidden>
    <div class="modal panel">
        <h2 id="rental-ended-title">Your rental has ended</h2>
        <p>Your rental of <strong><?= e($title) ?></strong> ended at midnight on <?= e(date('j M Y', strtotime((string)$loan['end_date']))) ?>. Rent it again to keep watching.</p>
        <a id="rent-again" class="btn btn-block" href="movie.php?id=<?= e($id) ?>&amp;expired=1">Rent again</a>
    </div>
</div>

<script>
(function () {
    var video = document.getElementById('player');
    var key   = 'watch-pos:<?= e($id) ?>';

    // Deadline is relative to page load, so a wrong clock on the viewer's device doesn't matter
    var deadline = Date.now() + <?= (int)$secondsLeft ?> * 1000;
    var ended    = false;
    function endRental() {
        if (ended) return;
        ended = true;
        clearInterval(timer);
        video.pause();
        video.removeAttribute('src');   // drop anything already buffered
        video.load();
        document.getElementById('rental-ended').hidden = false;
        document.getElementById('rent-again').focus();
    }
    // Poll instead of one long setTimeout: survives laptop sleep and rentals longer than ~24 days
    var timer = setInterval(function () { if (Date.now() >= deadline) endRental(); }, 5000);
    // stream.php refuses or cuts off the video once the rental ends
    video.addEventListener('error', function () { if (Date.now() >= deadline - 60000) endRental(); });

    fetch('poster.php?id=<?= e($id) ?>', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data && data.poster && !video.poster) video.poster = data.poster; })
        .catch(function () {});

    // Resume where the viewer left off
    video.addEventListener('loadedmetadata', function () {
        try {
            var pos = parseFloat(localStorage.getItem(key) || '0');
            if (pos > 5 && pos < video.duration - 10) video.currentTime = pos;
        } catch (e) {}
    });
    var last = 0;
    video.addEventListener('timeupdate', function () {
        if (Math.abs(video.currentTime - last) < 5) return;
        last = video.currentTime;
        try { localStorage.setItem(key, String(last)); } catch (e) {}
    });
    video.addEventListener('ended', function () {
        try { localStorage.removeItem(key); } catch (e) {}
    });
})();
</script>
<?php page_footer(); ?>
