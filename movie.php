<?php
require_once __DIR__ . '/config.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
    header('Location: index.php'); exit;
}

try {
    $movie = mongo_find_one(['_id' => new MongoDB\BSON\ObjectId($id)]);
} catch (Throwable $e) {
    die('Database error.');
}

if ($movie === null) {
    http_response_code(404);
    page_header('Movie not found');
    echo '<p class="empty">Sorry, that movie could not be found. <a href="index.php">Back to movies</a>.</p>';
    page_footer(); exit;
}

$movieId = (string)$movie['_id'];
$inCart = in_array($movieId, cart_ids(), true);
$user = current_user();
$loan = $user !== null ? active_loan((int)$user['user_id'], $movieId) : null;
$placeholder = placeholder_poster();

const REVIEW_MAX_LEN = 1000;

/*
 * Handle a review submission (POST). Must run before any output so we can
 * redirect. Only logged-in users may post. The review is written to the
 * movie_reviews collection in the same shape as existing documents
 * (reviewid, title, userRating, date, reviewContent); setting `title` links
 * it to this movie and keeps it covered by the { title:1, date:-1 } index.
 */
$reviewErrors = [];
$reviewDraft  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_review') {
    csrf_check();

    if ($user === null) {
        flash('Please log in to write a review.', 'error');
        header('Location: login.php'); exit;
    }

    $rating  = (int)($_POST['rating'] ?? 0);
    $content = trim((string)($_POST['review'] ?? ''));
    $reviewDraft = $content;

    if ($rating < 1 || $rating > 10) {
        $reviewErrors[] = 'Please choose a rating between 1 and 10.';
    }
    if ($content === '') {
        $reviewErrors[] = 'Please write something in your review.';
    } elseif (text_length($content) > REVIEW_MAX_LEN) {
        // Server-side length guard (the real limit; maxlength on the field is only UX).
        $reviewErrors[] = 'Your review must be ' . REVIEW_MAX_LEN . ' characters or fewer.';
    }

    if (!$reviewErrors) {
        try {
            mongo_db_collection('movie_reviews')->insertOne([
                'reviewid'      => 'REV-' . strtoupper(bin2hex(random_bytes(6))),
                'title'         => (string)$movie['title'],
                'userRating'    => $rating,
                'date'          => date('Y-m-d'),
                'reviewContent' => $content,
            ]);
            flash('Thanks! Your review has been posted.');
            // Redirect (PRG) so a refresh doesn't resubmit.
            header('Location: movie.php?id=' . urlencode($movieId)); exit;
        } catch (Throwable $e) {
            error_log('movie.php add_review: ' . $e->getMessage());
            $reviewErrors[] = 'Sorry, your review could not be saved. Please try again.';
        }
    }
}

/*
 * Reviews live in the movie_reviews collection and reference the movie by
 * its title string (there is no movie _id on the review). We fetch the
 * newest reviews for this title. A failure here must not break the page, so
 * it's isolated in its own try/catch.
 */
$reviews = [];
$reviewCount = 0;
$avgUserRating = null;
try {
    $reviewsCol = mongo_db_collection('movie_reviews');
    $title = (string)($movie['title'] ?? '');
    if ($title !== '') {
        $reviewCount = $reviewsCol->countDocuments(['title' => $title]);
        $reviews = $reviewsCol->find(
            ['title' => $title],
            ['sort' => ['date' => -1], 'limit' => 20]
        )->toArray();

        // Average user rating across ALL reviews for this title (not just the page).
        if ($reviewCount > 0) {
            $agg = $reviewsCol->aggregate([
                ['$match' => ['title' => $title]],
                ['$group' => ['_id' => null, 'avg' => ['$avg' => '$userRating']]],
            ])->toArray();
            if (!empty($agg) && isset($agg[0]['avg'])) {
                $avgUserRating = (float)$agg[0]['avg'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('movie.php reviews: ' . $e->getMessage());
    $reviews = [];
}

// Safely parse production companies (TMDB often stores this as a JSON string)
$companies = [];
if (!empty($movie['production_companies'])) {
    $pc = $movie['production_companies'];
    if (is_string($pc)) {
        $jsonStr = str_replace("'", '"', $pc);
        $decoded = json_decode($jsonStr, true);
        if (is_array($decoded)) {
            foreach ($decoded as $c) {
                if (isset($c['name'])) $companies[] = $c['name'];
            }
        } else {
            $companies[] = $pc; 
        }
    } elseif (is_array($pc)) {
        foreach ($pc as $c) {
            if (is_array($c) && isset($c['name'])) $companies[] = $c['name'];
            elseif (is_string($c)) $companies[] = $c;
        }
    }
}
$companiesStr = $companies ? implode(', ', $companies) : 'N/A';

// Format adult flag boolean/string
// Normalize the value so any casing of "false"/"true", booleans, and 0/1 are handled correctly
$adultRaw = $movie['adult'] ?? null;
$adultNormalized = is_string($adultRaw) ? strtolower(trim($adultRaw)) : $adultRaw;
$isAdult = (!empty($adultNormalized) && $adultNormalized !== 'false' && $adultNormalized !== '0') ? 'Yes' : 'No';

// Safely locate the vote count regardless of exact schema naming conventions
$voteCount = $movie['vote_count'] ?? $movie['voteCount'] ?? $movie['numVotes'] ?? $movie['votes'] ?? 0;
$movieRating = (float)($movie['rating'] ?? $movie['vote_average'] ?? 0);

// Map common ISO 639-1 language codes to full names
$languageCodes = [
    'en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German',
    'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese',
    'ru' => 'Russian', 'hi' => 'Hindi', 'pt' => 'Portuguese', 'ar' => 'Arabic',
    'id' => 'Indonesian', 'tr' => 'Turkish', 'nl' => 'Dutch', 'pl' => 'Polish',
    'sv' => 'Swedish', 'da' => 'Danish', 'fi' => 'Finnish', 'no' => 'Norwegian',
    'th' => 'Thai', 'vi' => 'Vietnamese', 'ta' => 'Tamil', 'te' => 'Telugu',
    'cs' => 'Czech', 'el' => 'Greek', 'he' => 'Hebrew', 'hu' => 'Hungarian',
    'ro' => 'Romanian', 'uk' => 'Ukrainian', 'bn' => 'Bengali', 'fa' => 'Persian',
    'tl' => 'Tagalog', 'ur' => 'Urdu', 'ml' => 'Malayalam', 'kn' => 'Kannada',
    'mr' => 'Marathi', 'pa' => 'Punjabi', 'gu' => 'Gujarati', 'cn' => 'Cantonese'
];

$rawLang = strtolower(trim((string)($movie['original_language'] ?? $movie['language'] ?? '')));
$displayLanguage = $languageCodes[$rawLang] ?? ($rawLang ? strtoupper($rawLang) : 'N/A');

page_header((string)$movie['title']);
?>
<a class="back-link" href="index.php">&larr; Back to movies</a>

<div class="detail">
    <div class="detail-poster">
        <img class="poster"
             src="<?= e($placeholder) ?>"
             data-movie-id="<?= e($movieId) ?>"
             alt="Poster for <?= e($movie['title']) ?>">
    </div>

    <div class="detail-body">
        <h1><?= e($movie['title'] ?? 'Unknown') ?></h1>

        <p class="detail-meta">
            <span class="rating">&#9733; <?= e(number_format($movieRating, 1)) ?></span>
            <span class="dot">&middot;</span>
            <span><?= e($movie['releaseDate'] ?? 'N/A') ?></span>
            <span class="dot">&middot;</span>
            <span><?= (int)($movie['runtime'] ?? 0) ?> min</span>
        </p>

        <!-- Genres is stored as a string in your MongoDB document -->
        <p class="detail-meta"><?= e($movie['genres'] ?? '') ?></p>
        <p class="overview"><?= e($movie['overview'] ?? '') ?></p>
        
        <!-- New Detailed Information Box -->
        <div style="background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; margin: 24px 0; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0; margin-bottom: 12px; font-size: 1.1rem; color: var(--brand);">Movie Information</h3>
            <ul style="list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; font-size: 0.95rem;">
                
                <!-- Rating and Vote Count together -->
                <li>
                    <strong style="color: var(--ink);">Rating:</strong> 
                    <?= number_format($movieRating, 1) ?> / 10 
                    <span style="color: var(--muted); font-size: 0.85em;">(<?= number_format((int)$voteCount) ?> votes)</span>
                </li>
                
                <li><strong style="color: var(--ink);">Original Language:</strong> <?= e($displayLanguage) ?></li>
                <li><strong style="color: var(--ink);">Adult Content:</strong> <?= e($isAdult) ?></li>
                <li style="grid-column: 1 / -1;"><strong style="color: var(--ink);">Production Companies:</strong> <?= e($companiesStr) ?></li>
            </ul>
        </div>

        <p class="price">Rental price: <strong>$<?= number_format(MOVIE_PRICE, 2) ?></strong></p>

        <?php if ($loan === null && isset($_GET['expired'])): ?>
            <div class="alert alert-info">Your rental of this movie has ended. Rent it again below to keep watching.</div>
        <?php endif; ?>

        <?php if ($loan !== null): ?>
            <p class="in-cart">You're renting this movie until <?= e(date('j M Y', strtotime((string)$loan['end_date']))) ?>.</p>
            <div class="actions" style="justify-content:flex-start">
                <a class="btn" href="watch.php?id=<?= e($movieId) ?>">&#9654; Watch now</a>
                <a class="btn btn-ghost" href="dashboard.php">My dashboard</a>
            </div>
        <?php elseif ($inCart): ?>
            <p class="in-cart">This movie is already in your cart.</p>
            <div class="actions" style="justify-content:flex-start">
                <a class="btn" href="checkout.php">Go to checkout</a>
                <a class="btn btn-ghost" href="index.php">Add more movies</a>
            </div>
        <?php else: ?>
            <form method="post" action="cart_add.php">
                <?= csrf_field() ?>
                <input type="hidden" name="movie_id" value="<?= e($movieId) ?>">
                <button class="btn" type="submit">Add to cart</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="reviews" style="margin-top: 32px;">
    <h2 style="display:flex; align-items:center; gap:12px;">
        Reviews
        <span class="muted" style="font-size: 0.9rem; font-weight: normal;">
            <?php if ($reviewCount > 0): ?>
                <?= number_format($reviewCount) ?> review<?= $reviewCount === 1 ? '' : 's' ?>
                <?php if ($avgUserRating !== null): ?>
                    &middot; average <?= e(number_format($avgUserRating, 1)) ?>/10
                <?php endif; ?>
            <?php endif; ?>
        </span>
    </h2>

    <?php if ($user !== null): ?>
        <form method="post" action="movie.php?id=<?= e($movieId) ?>" class="panel" style="margin-bottom:20px; padding:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_review">
            <h3 style="margin-top:0;">Write a review</h3>

            <?php if ($reviewErrors): ?>
                <div class="alert alert-error"><ul style="margin:0; padding-left:1.2em;">
                    <?php foreach ($reviewErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <label for="rating">Your rating</label>
            <select id="rating" name="rating" required style="padding:6px 8px; border:1px solid var(--line); border-radius:var(--radius);">
                <option value="">Choose…</option>
                <?php for ($i = 10; $i >= 1; $i--): ?>
                    <option value="<?= $i ?>"<?= (int)($_POST['rating'] ?? 0) === $i ? ' selected' : '' ?>><?= $i ?> / 10</option>
                <?php endfor; ?>
            </select>

            <label for="review" style="display:block; margin-top:12px;">Your review</label>
            <textarea id="review" name="review" rows="4" maxlength="<?= REVIEW_MAX_LEN ?>" required
                      style="width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:var(--radius);"
                      placeholder="Share what you thought (max <?= REVIEW_MAX_LEN ?> characters)."><?= e($reviewDraft) ?></textarea>
            <p class="muted" style="margin:4px 0 12px;">
                <span id="review-count">0</span> / <?= REVIEW_MAX_LEN ?> characters
            </p>

            <button type="submit" class="btn">Post review</button>
        </form>
        <script>
        (function () {
            var ta = document.getElementById('review');
            var out = document.getElementById('review-count');
            if (!ta || !out) return;
            function upd() { out.textContent = ta.value.length; }
            ta.addEventListener('input', upd);
            upd();
        })();
        </script>
    <?php else: ?>
        <p class="muted" style="margin-bottom:20px;">
            <a href="login.php">Log in</a> to write a review.
        </p>
    <?php endif; ?>

    <?php if (empty($reviews)): ?>
        <p class="empty">No reviews yet for this movie.</p>
    <?php else: ?>
        <?php if ($reviewCount > count($reviews)): ?>
            <p class="muted">Showing the <?= count($reviews) ?> most recent of <?= number_format($reviewCount) ?>.</p>
        <?php endif; ?>
        <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:12px;">
            <?php foreach ($reviews as $rev): ?>
                <li style="background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:14px 16px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:8px;">
                        <span class="rating">&#9733; <?= e((string)(int)($rev['userRating'] ?? 0)) ?>/10</span>
                        <span class="muted" style="font-size:0.85rem;"><?= e(format_review_date((string)($rev['date'] ?? ''))) ?></span>
                    </div>
                    <p style="margin:0; line-height:1.5;"><?= e((string)($rev['reviewContent'] ?? '')) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php poster_loader_script(); ?>
<?php page_footer(); ?>