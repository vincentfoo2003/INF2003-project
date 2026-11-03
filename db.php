<?php
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;

function getMongoClient(): Client
{
    // SetEnv populates $_SERVER; getenv() may also work. Check both.
    $uri = $_SERVER['MONGODB_URI'] ?? getenv('MONGODB_URI') ?: null;

    if (!$uri) {
        throw new RuntimeException('MONGODB_URI is not set.');
    }

    return new Client($uri);
}