<?php
require_once __DIR__ . '/config.php';

$user   = require_login();
$movies = cart_movies();
$total  = cart_total($movies);

$today      = date('Y-m-d');
$defaultEnd = date('Y-m-d', strtotime('+7 days'));
$placeholder = placeholder_poster();

page_header('Checkout');
?>
<h1>Checkout</h1>

<?php if (!$movies): ?>
    <p class="empty">Your cart is empty. <a href="index.php">Browse movies</a>.</p>
<?php else: ?>
<div class="checkout-layout">
    <section class="panel">
        <h2>Your selection</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Poster</th>
                    <th>Movie</th>
                    <th>Rating</th>
                    <th>Price</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($movies as $m): ?>
                <tr>
                    <td>
                        <img class="thumb"
                             src="<?= e($placeholder) ?>"
                             data-movie-id="<?= e($m['movie_id']) ?>"
                             alt="Poster for <?= e($m['title']) ?>">
                    </td>
                    <td><a href="movie.php?id=<?= e($m['movie_id']) ?>"><?= e($m['title']) ?></a></td>
                    <td>&#9733; <?= e(number_format((float)$m['rating'], 1)) ?></td>
                    <td>$<?= number_format(MOVIE_PRICE, 2) ?></td>
                    <td>
                        <form method="post" action="cart_remove.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="movie_id" value="<?= e($m['movie_id']) ?>">
                            <button class="link-button" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">Total</th>
                    <th colspan="2">$<?= number_format($total, 2) ?></th>
                </tr>
            </tfoot>
        </table>
    </section>

    <section class="panel">
        <h2>Loan period &amp; payment</h2>
        <form method="post" action="pay.php" class="form">
            <?= csrf_field() ?>
            <label for="start_date">Loan start date</label>
            <input type="date" id="start_date" name="start_date" value="<?= e($today) ?>" min="<?= e($today) ?>" required>
            
            <label for="end_date">Loan end date</label>
            <input type="date" id="end_date" name="end_date" value="<?= e($defaultEnd) ?>" min="<?= e($today) ?>" required>
            
            <label for="payment_type">Payment method</label>
            <select id="payment_type" name="payment_type">
                <option value="card">Credit / debit card</option>
                <option value="paypal">PayPal</option>
                <option value="bank">Bank transfer</option>
            </select>

            <p class="total-line">Total to pay: <strong>$<?= number_format($total, 2) ?></strong></p>
            <button class="btn btn-block" type="submit">Pay $<?= number_format($total, 2) ?></button>
            <p class="muted">Signed in as <?= e($user['email']) ?>. This is a demo payment.</p>
        </form>
    </section>
</div>

<script>
(function () {
    var start = document.getElementById('start_date');
    var end   = document.getElementById('end_date');
    if (!start || !end) return;
    function sync() {
        end.min = start.value;
        if (end.value && end.value < start.value) end.value = start.value;
    }
    start.addEventListener('change', sync);
    sync();
})();
</script>

<?php poster_loader_script(); ?>
<?php endif; ?>
<?php page_footer(); ?>