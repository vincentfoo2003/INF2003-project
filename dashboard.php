<?php
require_once __DIR__ . '/config.php';

$user = require_login();
$pdo = db();

// Fetch loans and associated payment methods
$stmt = $pdo->prepare("
    SELECT l.loan_id, l.movie_title, l.start_date, l.end_date, l.price, p.payment_type 
    FROM loans l
    JOIN payments p ON l.payment_id = p.payment_id
    WHERE l.user_id = ?
    ORDER BY l.start_date DESC
");
$stmt->execute([$user['user_id']]);
$loans = $stmt->fetchAll();

page_header('My Dashboard');
?>

<div class="panel">
    <h1>My Loans & Payments</h1>
    <?php if (empty($loans)): ?>
        <p class="empty">You have no active loans or past payments.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Loan ID</th>
                    <th>Movie Title</th>
                    <th>Rental Period</th>
                    <th>Price Paid</th>
                    <th>Payment Method</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loans as $loan): ?>
                    <tr>
                        <td><?= e($loan['loan_id']) ?></td>
                        <td><?= e($loan['movie_title']) ?></td>
                        <td><?= e($loan['start_date']) ?> to <?= e($loan['end_date']) ?></td>
                        <td>$<?= number_format($loan['price'], 2) ?></td>
                        <td><?= e(ucfirst($loan['payment_type'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php page_footer(); ?>