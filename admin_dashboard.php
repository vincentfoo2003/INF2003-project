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

/* -------- Pagination --------
 * The admin lists are unfiltered (they show everything), so with ~50k loans
 * the old unpaginated queries read and rendered the entire table in one page.
 * No index can fix a full read — the correct fix is LIMIT-based pagination.
 * Each list has its own page param (up/lp/pp) so they page independently.
 * ORDER BY is on each table's PRIMARY KEY, so the sort itself is already free;
 * LIMIT is what bounds the work and the rendered row count.
 */
$perPage = 25;

$userPage    = max(1, (int)($_GET['up'] ?? 1));
$loanPage    = max(1, (int)($_GET['lp'] ?? 1));
$paymentPage = max(1, (int)($_GET['pp'] ?? 1));

$userOffset    = ($userPage - 1) * $perPage;
$loanOffset    = ($loanPage - 1) * $perPage;
$paymentOffset = ($paymentPage - 1) * $perPage;

/* Per-list search terms (independent). */
$userSearch    = trim((string)($_GET['uq'] ?? ''));
$loanSearch    = trim((string)($_GET['lq'] ?? ''));
$paymentSearch = trim((string)($_GET['pq'] ?? ''));

/* Build WHERE clause + params for each list from its search term.
 * LIKE with a leading wildcard cannot use a B-tree index, so these scan —
 * acceptable for an admin tool, and the same limitation noted elsewhere. */
$userWhere = ''; $userParams = [];
if ($userSearch !== '') {
    $userWhere = 'WHERE u.name LIKE ? OR u.email LIKE ?';
    $like = '%' . $userSearch . '%';
    $userParams = [$like, $like];
}

$loanWhere = ''; $loanParams = [];
if ($loanSearch !== '') {
    $loanWhere = 'WHERE l.movie_title LIKE ? OR u.name LIKE ?';
    $like = '%' . $loanSearch . '%';
    $loanParams = [$like, $like];
}

$paymentWhere = ''; $paymentParams = [];
if ($paymentSearch !== '') {
    $paymentWhere = 'WHERE u.name LIKE ? OR p.payment_type LIKE ?';
    $like = '%' . $paymentSearch . '%';
    $paymentParams = [$like, $like];
}

/* Totals for page counts (respect the active search). */
$uc = $pdo->prepare("SELECT COUNT(*) FROM users u $userWhere");
$uc->execute($userParams); $userTotal = (int)$uc->fetchColumn();

$lc = $pdo->prepare("SELECT COUNT(*) FROM loans l LEFT JOIN users u ON l.user_id = u.user_id $loanWhere");
$lc->execute($loanParams); $loanTotal = (int)$lc->fetchColumn();

$pc = $pdo->prepare("SELECT COUNT(*) FROM payments p LEFT JOIN users u ON p.user_id = u.user_id $paymentWhere");
$pc->execute($paymentParams); $paymentTotal = (int)$pc->fetchColumn();

$userPages    = max(1, (int)ceil($userTotal / $perPage));
$loanPages    = max(1, (int)ceil($loanTotal / $perPage));
$paymentPages = max(1, (int)ceil($paymentTotal / $perPage));

// Fetch one page of each list (LIMIT/OFFSET are cast ints, not user text).
$userLimit    = "LIMIT $perPage OFFSET $userOffset";
$loanLimit    = "LIMIT $perPage OFFSET $loanOffset";
$paymentLimit = "LIMIT $perPage OFFSET $paymentOffset";

/* Users: admins first (role = 'admin' sorts before 'user'), then by id.
 * "role = 'admin'" yields 1 for admins, 0 for users; DESC puts admins on top. */
$us = $pdo->prepare("
    SELECT u.user_id, u.name, u.email, u.role
    FROM users u
    $userWhere
    ORDER BY (u.role = 'admin') DESC, u.user_id ASC
    $userLimit
");
$us->execute($userParams);
$users = $us->fetchAll();

$ls = $pdo->prepare("
    SELECT l.loan_id, u.name AS user_name, l.movie_title, l.start_date, l.end_date, l.price 
    FROM loans l
    LEFT JOIN users u ON l.user_id = u.user_id
    $loanWhere
    ORDER BY l.loan_id DESC
    $loanLimit
");
$ls->execute($loanParams);
$loans = $ls->fetchAll();

$ps = $pdo->prepare("
    SELECT p.payment_id, u.name AS user_name, p.cost, p.payment_type, p.created_at 
    FROM payments p
    LEFT JOIN users u ON p.user_id = u.user_id
    $paymentWhere
    ORDER BY p.payment_id DESC
    $paymentLimit
");
$ps->execute($paymentParams);
$payments = $ps->fetchAll();

/* Helper: render a pager that preserves the OTHER lists' page params AND all
 * three search terms, so navigating or searching one list leaves the rest. */
function admin_pager(string $param, int $cur, int $pages, array $others): string {
    if ($pages <= 1) return '';
    // carry current search terms through pagination links
    foreach (['uq', 'lq', 'pq'] as $sq) {
        if (isset($_GET[$sq]) && $_GET[$sq] !== '') $others[$sq] = (string)$_GET[$sq];
    }
    $base = function (int $p) use ($param, $others) {
        return '?' . http_build_query(array_merge($others, [$param => $p]));
    };
    $html = '<div class="pagination">';
    if ($cur > 1)      $html .= '<a class="btn btn-ghost btn-small" href="' . e($base($cur - 1)) . '">&laquo; Prev</a>';
    $html .= '<span class="muted">Page ' . $cur . ' of ' . $pages . '</span>';
    if ($cur < $pages) $html .= '<a class="btn btn-ghost btn-small" href="' . e($base($cur + 1)) . '">Next &raquo;</a>';
    $html .= '</div>';
    return $html;
}

/* Helper: a small search form for one list. Preserves other lists' search
 * terms as hidden fields so searching one doesn't clear the others. */
function admin_search_form(string $param, string $value, string $placeholder): string {
    $hidden = '';
    foreach (['uq', 'lq', 'pq'] as $sq) {
        if ($sq !== $param && isset($_GET[$sq]) && $_GET[$sq] !== '') {
            $hidden .= '<input type="hidden" name="' . $sq . '" value="' . e((string)$_GET[$sq]) . '">';
        }
    }
    $clear = $value !== ''
        ? '<a class="btn btn-small btn-ghost" href="?' . e(http_build_query(array_filter([
              'uq' => $param === 'uq' ? '' : ($_GET['uq'] ?? ''),
              'lq' => $param === 'lq' ? '' : ($_GET['lq'] ?? ''),
              'pq' => $param === 'pq' ? '' : ($_GET['pq'] ?? ''),
          ]))) . '">Clear</a>'
        : '';
    return '<form method="get" action="admin_dashboard.php" class="filter-form" style="display:flex; gap:8px; margin:0 0 10px;">'
         . $hidden
         . '<input type="search" name="' . $param . '" value="' . e($value) . '" placeholder="' . e($placeholder) . '" style="padding:8px 10px; border:1px solid var(--line); border-radius:var(--radius); min-width:240px;">'
         . '<button type="submit" class="btn btn-small">Search</button>'
         . $clear
         . '</form>';
}

page_header('Admin Management');
?>

<div class="panel">
    <h1>Admin Dashboard</h1>

    <h2>Manage Users</h2>
    <?= admin_search_form('uq', $userSearch, 'Search name or email...') ?>
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
            <?php if (empty($users)): ?>
                <tr><td colspan="5" class="empty">No users match your search.</td></tr>
            <?php endif; ?>
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
    <p class="muted"><?= number_format($userTotal) ?> users total</p>
    <?= admin_pager('up', $userPage, $userPages, ['lp' => $loanPage, 'pp' => $paymentPage]) ?>

    <h2 style="margin-top: 2rem;">Manage Loans</h2>
    <?= admin_search_form('lq', $loanSearch, 'Search movie title or user...') ?>
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
            <?php if (empty($loans)): ?>
                <tr><td colspan="5" class="empty">No loans match your search.</td></tr>
            <?php endif; ?>
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
    <p class="muted"><?= number_format($loanTotal) ?> loans total</p>
    <?= admin_pager('lp', $loanPage, $loanPages, ['up' => $userPage, 'pp' => $paymentPage]) ?>

    <h2 style="margin-top: 2rem;">Payment Records</h2>
    <?= admin_search_form('pq', $paymentSearch, 'Search user or payment method...') ?>
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
    <p class="muted"><?= number_format($paymentTotal) ?> payments total</p>
    <?= admin_pager('pp', $paymentPage, $paymentPages, ['up' => $userPage, 'lp' => $loanPage]) ?>
</div>

<?php page_footer(); ?>