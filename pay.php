<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$user = current_user();
if ($user === null) {
    $_SESSION['after_login'] = 'checkout.php';
    flash('Please log in to complete your payment.', 'error');
    header('Location: login.php'); exit;
}

$movies = cart_movies();
if (!$movies) {
    flash('Your cart is empty.', 'error');
    header('Location: index.php'); exit;
}

$startRaw = trim((string)($_POST['start_date'] ?? ''));
$endRaw   = trim((string)($_POST['end_date'] ?? ''));
$type     = (string)($_POST['payment_type'] ?? 'card');

$allowedTypes = ['card', 'paypal', 'bank'];
$errors = [];

$start = DateTime::createFromFormat('Y-m-d', $startRaw);
$end   = DateTime::createFromFormat('Y-m-d', $endRaw);
$today = new DateTime('today');

if (!$start instanceof DateTime || $start->format('Y-m-d') !== $startRaw) $errors[] = 'Please choose a valid loan start date.';
if (!$end instanceof DateTime || $end->format('Y-m-d') !== $endRaw) $errors[] = 'Please choose a valid loan end date.';
if (!in_array($type, $allowedTypes, true)) $errors[] = 'Please choose a valid payment method.';

if (!$errors) {
    if ($start < $today) $errors[] = 'The loan start date cannot be in the past.';
    if ($end < $start) $errors[] = 'The loan end date must be on or after the start date.';
}

if ($errors) {
    foreach ($errors as $err) flash($err, 'error');
    header('Location: checkout.php'); exit;
}

$pdo  = db();
$cost = cart_total($movies);

try {
    $pdo->beginTransaction();

    $insPay = $pdo->prepare('INSERT INTO payments (user_id, cost, payment_type) VALUES (?, ?, ?)');
    $insPay->execute([(int)$user['user_id'], $cost, $type]);
    $paymentId = (int)$pdo->lastInsertId();

    $insLoan = $pdo->prepare('INSERT INTO loans (payment_id, user_id, movie_id, movie_title, start_date, end_date, price) VALUES (?, ?, ?, ?, ?, ?, ?)');
    
    foreach ($movies as $movie) {
        $insLoan->execute([
            $paymentId,
            (int)$user['user_id'],
            $movie['movie_id'],   
            $movie['title'],      
            $startRaw,
            $endRaw,
            MOVIE_PRICE,
        ]);
    }

    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('pay.php: ' . $ex->getMessage());
    flash('Your payment could not be completed. Please try again.', 'error');
    header('Location: checkout.php'); exit;
}

unset($_SESSION['cart']);
flash('Payment successful! Your movies are now on loan.');
header('Location: receipt.php?payment_id=' . $paymentId);
exit;