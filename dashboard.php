<?php
require_once __DIR__ . '/config.php';

$user = require_login();
$pdo = db();

$search = trim((string)($_GET['q'] ?? ''));

// Fetch loans and associated payment methods
$sql = "
    SELECT l.loan_id, l.movie_title, l.start_date, l.end_date, l.price, p.payment_type 
    FROM loans l
    JOIN payments p ON l.payment_id = p.payment_id
    WHERE l.user_id = ?
";
$params = [$user['user_id']];

if ($search !== '') {
    $sql .= " AND l.movie_title LIKE ?";
    $params[] = '%' . $search . '%'; // Wildcards for partial matches
}

$sql .= " ORDER BY l.start_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();

page_header('My Dashboard');
?>

<div class="panel">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 20px;">
        <h1 style="margin: 0;">My Loans & Payments</h1>
        
        <form method="get" action="dashboard.php" class="filter-form" style="display: flex; gap: 10px;">
            <input type="search" name="q" placeholder="Search your movies..." value="<?= e($search) ?>" style="padding: 8px 10px; border: 1px solid var(--line); border-radius: var(--radius);">
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($search !== ''): ?>
                <a href="dashboard.php" class="btn btn-small btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($loans)): ?>
        <p class="empty">
            <?= $search !== '' ? 'No loans found matching your search.' : 'You have no active loans or past payments.' ?>
        </p>
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