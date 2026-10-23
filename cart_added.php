<?php
require_once __DIR__ . '/config.php';

$movies = cart_movies();
if (!$movies) {
    flash('Your cart is empty.', 'error');
    header('Location: index.php'); exit;
}

$addedId = $_GET['movie'] ?? '';
$added = null;
if ($addedId) {
    foreach ($movies as $m) {
        if ($m['movie_id'] === $addedId) { $added = $m; break; }
    }
}

$count = count($movies);
$total = cart_total($movies);

page_header('Added to cart');
?>
<div class="panel center">
    <h1>Added to your cart</h1>
    <?php if ($added !== null): ?>
        <p><strong><?= e($added['title']) ?></strong> is ready to rent.</p>
    <?php endif; ?>
    <p>You have <?= $count ?> movie<?= $count === 1 ? '' : 's' ?> in your cart
       &mdash; total <strong>$<?= number_format($total, 2) ?></strong>.</p>
    <div class="actions">
        <a class="btn" href="checkout.php">Checkout</a>
        <a class="btn btn-ghost" href="index.php">Add more movies</a>
    </div>
</div>
<?php page_footer(); ?>