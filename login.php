<?php
require_once __DIR__ . '/config.php';

if (current_user() !== null) { header('Location: index.php'); exit; }

$errors = []; $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($password === '') $errors[] = 'Please enter your password.';

    if (!$errors) {
        $stmt = db()->prepare('SELECT user_id, name, password FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row !== false && password_verify($password, (string)$row['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['user_id'];
            unset($_SESSION['csrf']);
            $target = 'index.php'; 
            unset($_SESSION['after_login']);
            flash('Welcome back, ' . $row['name'] . '!');
            header('Location: ' . $target); exit;
        }
        $errors[] = 'Incorrect email or password.';
    }
}
page_header('Log in');
?>
<div class="panel narrow">
    <h1>Log in</h1>
    <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="login.php" class="form">
        <?= csrf_field() ?>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button class="btn btn-block" type="submit">Log in</button>
    </form>
    <p class="muted">No account yet? <a href="register.php">Register here</a>.</p>
</div>
<?php page_footer(); ?>