<?php
require_once __DIR__ . '/config.php';

session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
    echo json_encode(['poster' => null]); exit;
}

try {
    $movie = mongo_find_one(['_id' => new MongoDB\BSON\ObjectId($id)]);
    if ($movie === null) {
        echo json_encode(['poster' => null]); exit;
    }
    echo json_encode(['poster' => poster_for($movie)]);
} catch (Throwable $ex) {
    error_log('poster.php: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode(['poster' => null]);
}