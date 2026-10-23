<?php
require_once __DIR__ . '/config.php';

$user      = require_login();
$paymentId = filter_input(INPUT_GET, 'payment_id', FILTER_VALIDATE_INT);

if (!$paymentId) { header('Location: index.php'); exit; }

$stmt = db()->prepare('SELECT payment_id, cost, payment_type, created_at FROM payments WHERE payment_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$paymentId, (int)$user['user_id']]);
$payment = $stmt->fetch();

if ($payment === false) {
    http_response_code(404);
    page_header('Receipt');
    echo '<p class="empty">Receipt not found. <a href="index.php">Back to movies</a>.</p>';
    page_footer(); exit;
}

$stmt = db()->prepare('SELECT loan_id, movie_id, movie_title, start_date, end_date, price FROM loans WHERE payment_id = ? ORDER BY movie_title ASC');
$stmt->execute([$paymentId]);
$loans = $stmt->fetchAll();

page_header('Receipt');
?>
<div class="panel">
    <h1>Thank you!</h1>
    <p>Payment <strong>#<?= (int)$payment['payment_id'] ?></strong> &middot; <strong>$<?= number_format((float)$payment['cost'], 2) ?></strong> &middot; <?= e(strtoupper((string)$payment['payment_type'])) ?> &middot; <?= e(date('j M Y H:i', strtotime((string)$payment['created_at']))) ?></p>

    <h2>Your rentals</h2>
    <table class="table">
        <thead>
            <tr><th>Movie</th><th>Loan start</th><th>Loan end</th><th>Price</th></tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $loan): ?>
            <tr>
                <td><a href="movie.php?id=<?= e($loan['movie_id']) ?>"><?= e($loan['movie_title']) ?></a></td>
                <td><?= e(date('j M Y', strtotime((string)$loan['start_date']))) ?></td>
                <td><?= e(date('j M Y', strtotime((string)$loan['end_date']))) ?></td>
                <td>$<?= number_format((float)$loan['price'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><th colspan="3">Total paid</th><th>$<?= number_format((float)$payment['cost'], 2) ?></th></tr>
        </tfoot>
    </table>
    <div class="actions" style="justify-content:flex-start"><a class="btn" href="index.php">Rent more movies</a></div>
</div>
<?php page_footer(); ?>