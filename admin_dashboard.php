<?php
require_once __DIR__ . '/config.php';

$user = require_login();
if (($user['role'] ?? 'user') !== 'admin') {
    flash('Access denied. Admin privileges required.', 'error');
    header('Location: index.php');
    exit;
}

$pdo = db();

// Handle Form Submissions for Updates and Deletions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_role') {
        $updateRole = $_POST['role'] ?? 'user';
        $updateId = (int)($_POST['target_user_id'] ?? 0);
        
        if (in_array($updateRole, ['user', 'admin']) && $updateId > 0) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?");
            $stmt->execute([$updateRole, $updateId]);
            flash("User ID $updateId role updated to $updateRole.");
        }
    } elseif ($action === 'update_loan') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        
        if ($loanId > 0 && $startDate && $endDate) {
            $stmt = $pdo->prepare("UPDATE loans SET start_date = ?, end_date = ?, price = ? WHERE loan_id = ?");
            $stmt->execute([$startDate, $endDate, $price, $loanId]);
            flash("Loan ID $loanId updated successfully.");
        }
    } elseif ($action === 'delete_loan') {
        $deleteId = (int)($_POST['loan_id'] ?? 0);
        
        if ($deleteId > 0) {
            $stmt = $pdo->prepare("DELETE FROM loans WHERE loan_id = ?");
            $stmt->execute([$deleteId]);
            flash("Loan ID $deleteId deleted successfully.");
        }
    }
    
    header('Location: admin_dashboard.php');
    exit;
}

// Fetch all data for the dashboard with JOINs for user names
$users = $pdo->query("SELECT user_id, name, email, role FROM users ORDER BY user_id ASC")->fetchAll();

$loans = $pdo->query("
    SELECT l.loan_id, u.name AS user_name, l.movie_title, l.start_date, l.end_date, l.price 
    FROM loans l
    LEFT JOIN users u ON l.user_id = u.user_id
    ORDER BY l.loan_id DESC
")->fetchAll();

$payments = $pdo->query("
    SELECT p.payment_id, u.name AS user_name, p.cost, p.payment_type, p.created_at 
    FROM payments p
    LEFT JOIN users u ON p.user_id = u.user_id
    ORDER BY p.payment_id DESC
")->fetchAll();

page_header('Admin Management');
?>

<div class="panel">
    <h1>Admin Dashboard</h1>

    <h2>Manage Users</h2>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['user_id']) ?></td>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['role']) ?></td>
                    <td>
                        <form method="POST" class="inline-form" style="display:flex; gap:0.5rem; align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="target_user_id" value="<?= e($u['user_id']) ?>">
                            <select name="role" style="padding: 4px; border: 1px solid var(--line); border-radius: 4px;">
                                <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <button type="submit" class="btn btn-small">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top: 2rem;">Manage Loans</h2>
    <table class="table" style="font-size: 0.9rem;">
        <thead>
            <tr>
                <th>Loan ID</th>
                <th>User Name</th>
                <th>Movie</th>
                <th>Inline Edit Dates & Price</th>
                <th>Delete</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($loans as $l): ?>
                <tr>
                    <td><?= e($l['loan_id']) ?></td>
                    <td><?= e($l['user_name'] ?? 'Unknown User') ?></td>
                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?= e($l['movie_title']) ?>
                    </td>
                    <td>
                        <form method="POST" class="inline-form" style="display:flex; gap:0.4rem; align-items:center; margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_loan">
                            <input type="hidden" name="loan_id" value="<?= e($l['loan_id']) ?>">
                            
                            <input type="date" name="start_date" value="<?= e($l['start_date']) ?>" required style="padding: 4px; border: 1px solid var(--line); border-radius: 4px; font-size: 0.85rem;">
                            <span>to</span>
                            <input type="date" name="end_date" value="<?= e($l['end_date']) ?>" required style="padding: 4px; border: 1px solid var(--line); border-radius: 4px; font-size: 0.85rem;">
                            
                            <span style="margin-left: 0.5rem;">$</span>
                            <input type="number" step="0.01" name="price" value="<?= e($l['price']) ?>" required style="padding: 4px; width: 70px; border: 1px solid var(--line); border-radius: 4px; font-size: 0.85rem;">
                            
                            <button type="submit" class="btn btn-small" style="padding: 4px 10px;">Update</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this loan?');" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_loan">
                            <input type="hidden" name="loan_id" value="<?= e($l['loan_id']) ?>">
                            <button type="submit" class="btn btn-small btn-ghost" style="padding: 4px 10px; color: var(--danger); border-color: var(--danger);">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top: 2rem;">Payment Records</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Payment ID</th>
                <th>User Name</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($payments)): ?>
                <tr><td colspan="5" class="empty">No payments found.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= e($p['payment_id']) ?></td>
                        <td><?= e($p['user_name'] ?? 'Unknown User') ?></td>
                        <td>$<?= number_format((float)$p['cost'], 2) ?></td>
                        <td><?= e(ucfirst($p['payment_type'])) ?></td>
                        <td><?= e(date('j M Y H:i', strtotime((string)$p['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php page_footer(); ?>