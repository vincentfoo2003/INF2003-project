<?php
require_once __DIR__ . '/config.php';

if (current_user() !== null) { header('Location: index.php'); exit; }

$errors = []; $name = ''; $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name     = trim((string)($_POST['name'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if ($name === '' || text_length($name) > 100) $errors[] = 'Please enter your name (maximum 100 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($email) > 190) $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 8) $errors[] = 'Your password must be at least 8 characters long.';
    if ($password !== $confirm) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        try {
            // 1. Check if email exists
            $check = db()->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
            $check->execute([$email]);
            
            if ($check->fetch() !== false) {
                $errors[] = 'An account with that email address already exists.';
            } else {
                // 2. Insert new user
                $stmt = db()->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                
                // 3. Login user
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)db()->lastInsertId();
                unset($_SESSION['csrf']);
                flash('Welcome to ' . SITE_NAME . ', ' . $name . '!');
                header('Location: index.php'); 
                exit;
            }
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'An account with that email address already exists.';
            } else {
                // This will print the exact database error to help you debug
                $errors[] = 'Database error: ' . $ex->getMessage(); 
            }
        } catch (Throwable $ex) {
            $errors[] = 'Unexpected error: ' . $ex->getMessage();
        }
    }
}
page_header('Register');
?>
<div class="panel narrow">
    <h1>Create an account</h1>
    <?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="register.php" class="form">
        <?= csrf_field() ?>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($name) ?>" maxlength="100" autocomplete="name" required>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="email" required>
        <label for="password">Password (min. 8 characters)</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
        <label for="password_confirm">Confirm password</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
        <button class="btn btn-block" type="submit">Register</button>
    </form>
    <p class="muted">Already registered? <a href="login.php">Log in</a>.</p>
</div>
<?php page_footer(); ?>