<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout.php'); exit; }
csrf_check();

$id = $_POST['movie_id'] ?? '';
if (preg_match('/^[a-f0-9]{24}$/i', $id)) {
    cart_remove($id);
    flash('Movie removed from your cart.');
}

header('Location: checkout.php');
exit;