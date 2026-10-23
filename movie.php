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
            <span class="rating">&#9733; <?= e(number_format((float)($movie['rating'] ?? 0), 1)) ?></span>
            <span class="dot">&middot;</span>
            <span><?= e($movie['releaseDate'] ?? 'N/A') ?></span>
            <span class="dot">&middot;</span>
            <span><?= (int)($movie['runtime'] ?? 0) ?> min</span>
        </p>

        <!-- Genres is stored as a string in your MongoDB document -->
        <p class="detail-meta"><?= e($movie['genres'] ?? '') ?></p>
        <p class="overview"><?= e($movie['overview'] ?? '') ?></p>
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