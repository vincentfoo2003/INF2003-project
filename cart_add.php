<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_check();

$id = $_POST['movie_id'] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
    flash('Invalid movie selection.', 'error');
    header('Location: index.php'); exit;
}

try {
    $movie = mongo_find_one(['_id' => new MongoDB\BSON\ObjectId($id)]);
} catch (Throwable $e) {
    flash('Database error.', 'error');
    header('Location: index.php'); exit;
}

if (!$movie) {
    flash('That movie does not exist.', 'error');
    header('Location: index.php'); exit;
}

cart_add($id);
header('Location: cart_added.php?movie=' . urlencode($id));
exit;