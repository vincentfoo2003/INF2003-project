<?php
require_once __DIR__ . '/config.php';

try {
    // Use the helper function defined in config.php
    $collection = mongo_collection();

    // Find multiple movies (e.g., highly rated ones)
    $cursor = $collection->find(
        ['vote_average' => ['$gte' => 8.0]], // Filter for TMDB data
        ['sort' => ['release_date' => -1], 'limit' => 20]
    );

    echo "--- Top Rated Movies ---\n";
    foreach ($cursor as $doc) {
        // TMDB dataset uses 'title' instead of 'name'
        $title = $doc['title'] ?? 'Unknown Title';
        $rating = $doc['vote_average'] ?? 'N/A';
        echo $title . ' — Rating: ' . $rating . PHP_EOL;
    }

    echo "\n--- Find Specific Movie ---\n";
    // Find one document by movie title
    $one = $collection->findOne(['title' => 'Inception']);
    if ($one) {
        echo 'Found: ' . $one['title'] . ' - Released: ' . ($one['release_date'] ?? 'N/A') . PHP_EOL;
    }

    echo "\n--- Count ---\n";
    // Count total highly rated movies
    echo 'Highly Rated Movies: ' . $collection->countDocuments(['vote_average' => ['$gte' => 8.0]]) . PHP_EOL;

} catch (Throwable $e) {
    // Catches both MongoDB driver exceptions and general PHP errors
    error_log('MongoDB error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Database error.';
}