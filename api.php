<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

try {
    $collection = getMongoClient()
        ->selectDatabase('inf2003-moviedb')
        ->selectCollection('movies');

    // Query based on the exact status field found in your database
    $cursor =$collection->find(
        ['status' => 'Released'],
        ['limit' => 50] // Limit to 50 for performance on the frontend
    );

    $results = [];
    foreach ($cursor as$doc) {
        $results[] = iterator_to_array($doc);
    }

    echo json_encode($results);
    
} catch (Throwable $e) {
    http_response_code(500);
    // This now returns the exact system error to the browser for debugging
    echo json_encode(['error' => $e->getMessage()]);
}