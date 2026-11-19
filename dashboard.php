<?php
require_once __DIR__ . '/config.php';

$user = require_login();
$pdo = db();

$search = trim((string)($_GET['q'] ?? ''));

/* -------- Pagination --------
 * Without a LIMIT this loads every loan for the user. For a user with many
 * loans that is a large, slow read and an unwieldy page. We page the results
 * and, crucially, the WHERE user_id + ORDER BY start_date is now served by the
 * composite index idx_loans_user_startdate (no filesort).
 */
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Shared WHERE clause + params for both the COUNT and the data query.
$where  = 'WHERE l.user_id = ?';
$params = [$user['user_id']];
if ($search !== '') {
    $where   .= ' AND l.movie_title LIKE ?';
    $params[] = '%' . $search . '%'; // leading wildcard: cannot use a B-tree index
}

// Total rows (for page count). Counts loans only; no join needed.
$countSql  = "SELECT COUNT(*) FROM loans l $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalLoans = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLoans / $perPage));

// Page of results. LIMIT/OFFSET are cast ints inlined safely (not user text).
$sql = "
    SELECT l.loan_id, l.movie_id, l.movie_title, l.start_date, l.end_date, l.price, p.payment_type
    FROM loans l
    JOIN payments p ON l.payment_id = p.payment_id
    $where
    ORDER BY l.start_date DESC
    LIMIT $perPage OFFSET $offset
";
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
                    <th></th>
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
                        <td>
                            <?php $status = loan_status($loan); ?>
                            <?php if ($status === 'active'): ?>
                                <a class="btn btn-small" href="watch.php?id=<?= e($loan['movie_id']) ?>">&#9654; Watch Movie</a>
                            <?php elseif ($status === 'upcoming'): ?>
                                <span class="badge badge-upcoming">Starts <?= e(date('j M', strtotime((string)$loan['start_date']))) ?></span>
                            <?php else: ?>
                                <span class="badge badge-expired">Rental ended</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
            <?php $qs = $search !== '' ? '&q=' . urlencode($search) : ''; ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $qs ?>" class="btn btn-ghost">&laquo; Previous</a>
                <?php endif; ?>
                <span class="muted">Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalLoans) ?> loans)</span>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $qs ?>" class="btn btn-ghost">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
// Pre-check: may this user delete their own account? A regular user always can;
// an admin can unless they are the last admin. The check decides whether we
// even show the button (so the last admin never reaches a confirm dialog).
[$canSelfDelete, $selfDeleteReason] = can_delete_user((int)$user['user_id'], (int)$user['user_id']);
?>
<div class="panel" style="margin-top: 2rem; border-color: var(--danger);">
    <h2 style="color: var(--danger); margin-top: 0;">Delete account</h2>
    <?php if ($canSelfDelete): ?>
        <p class="muted">
            Permanently delete your account. This also removes <strong>all your
            rentals and payment records</strong> and cannot be undone.
        </p>
        <form method="post" action="delete_account.php"
              onsubmit="return confirm('Delete your account permanently? All your rentals and payment records will be erased. This cannot be undone.');">
            <?= csrf_field() ?>
            <button type="submit" class="btn" style="background: var(--danger); border-color: var(--danger);">
                Delete my account
            </button>
        </form>
    <?php else: ?>
        <p class="muted"><?= e($selfDeleteReason) ?></p>
    <?php endif; ?>
</div>

<?php page_footer(); ?>