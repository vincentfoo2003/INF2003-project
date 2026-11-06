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
$placeholder = placeholder_poster();

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

        <?php if ($inCart): ?>
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

<?php poster_loader_script(); ?>
<?php page_footer(); ?>