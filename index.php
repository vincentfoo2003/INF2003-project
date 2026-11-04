<?php
require_once __DIR__ . '/config.php';

$sortOptions = [
    'rating'       => 'Highest rating (Weighted)',
    'release_desc' => 'Newest release date',
    'release_asc'  => 'Oldest release date',
];

$sort = (string)($_GET['sort'] ?? 'rating');
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'rating';
}

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 24;
$skip = ($page - 1) * $limit;

// 1. Build the MongoDB Match Filter for Search
$filter = [];
$matchStage = null;

if ($search !== '') {
    // Case-insensitive regex search across title, overview, and genres
    $regex = new MongoDB\BSON\Regex(preg_quote($search), 'i');
    $filter = [
        '$or' => [
            ['title' => $regex],
            ['overview' => $regex],
            ['genres' => $regex]
        ]
    ];
    $matchStage = ['$match' => $filter];
}

try {
    $total = mongo_count($filter);
    $totalPages = max(1, ceil($total / $limit));
    $pipeline = [];

    // Inject the match stage at the very beginning if a search is active
    if ($matchStage) {
        $pipeline[] = $matchStage;
    }

    if ($sort === 'release_desc' || $sort === 'release_asc') {
        $order = $sort === 'release_desc' ? -1 : 1;
        
        $pipeline = array_merge($pipeline, [
            ['$addFields' => [
                'dateParts' => ['$split' => [['$ifNull' => ['$releaseDate', '1/1/1970']], '/']]
            ]],
            ['$addFields' => [
                'parsedYear' => [
                    '$convert' => ['input' => ['$arrayElemAt' => ['$dateParts', 2]], 'to' => 'int', 'onError' => 1970, 'onNull' => 1970]
                ],
                'parsedMonth' => [
                    '$convert' => ['input' => ['$arrayElemAt' => ['$dateParts', 1]], 'to' => 'int', 'onError' => 1, 'onNull' => 1]
                ],
                'parsedDay' => [
                    '$convert' => ['input' => ['$arrayElemAt' => ['$dateParts', 0]], 'to' => 'int', 'onError' => 1, 'onNull' => 1]
                ]
            ]],
            ['$sort' => [
                'parsedYear' => $order,
                'parsedMonth' => $order,
                'parsedDay' => $order,
                '_id' => 1
            ]],
            ['$skip' => $skip],
            ['$limit' => $limit]
        ]);
    } else {
        // Weighted Rating Pipeline
        $pipeline = array_merge($pipeline, [
            ['$addFields' => [
                'v' => [
                    '$convert' => ['input' => ['$ifNull' => ['$vote_count', 0]], 'to' => 'double', 'onError' => 0, 'onNull' => 0]
                ],
                'R' => [
                    '$convert' => ['input' => ['$ifNull' => ['$rating', 0]], 'to' => 'double', 'onError' => 0, 'onNull' => 0]
                ]
            ]],
            ['$addFields' => [
                'weighted_rating' => [
                    '$cond' => [
                        'if' => ['$gt' => ['$v', 0]],
                        'then' => [
                            '$add' => [
                                ['$multiply' => [['$divide' => ['$v', ['$add' => ['$v', 500]]]], '$R']],
                                ['$multiply' => [['$divide' => [500, ['$add' => ['$v', 500]]]], 6.5]]
                            ]
                        ],
                        'else' => 0
                    ]
                ]
            ]],
            ['$sort' => [
                'weighted_rating' => -1,
                '_id' => 1
            ]],
            ['$skip' => $skip],
            ['$limit' => $limit]
        ]);
    }
    
    $movies = mongo_aggregate($pipeline);
    
} catch (Throwable $e) {
    error_log('MongoDB error: ' . $e->getMessage());
    die('Database error: ' . $e->getMessage()); 
}

$placeholder = placeholder_poster();

page_header('Movies');
?>
<section class="toolbar">
    <h1>Browse movies</h1>
    <form method="get" action="index.php" class="filter-form" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <input type="search" name="q" placeholder="Search title, genre, keyword..." value="<?= e($search) ?>" style="padding: 8px 10px; border: 1px solid var(--line); border-radius: var(--radius); min-width: 250px;">
        
        <label for="sort" style="align-self: center;">Sort by</label>
        <select name="sort" id="sort" onchange="this.form.submit()">
            <?php foreach ($sortOptions as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $value === $sort ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-small">Apply</button></noscript>
    </form>
</section>

<?php if (!$movies): ?>
    <p class="empty">No movies found matching your search.</p>
<?php else: ?>
    <div class="grid">
        <?php foreach ($movies as $movie): ?>
            <a class="card" href="movie.php?id=<?= e((string)$movie['_id']) ?>">
                <div class="poster-wrap">
                    <img class="poster"
                         src="<?= e($placeholder) ?>"
                         data-movie-id="<?= e((string)$movie['_id']) ?>"
                         alt="Poster for <?= e($movie['title'] ?? '') ?>">
                </div>
                <div class="card-body">
                    <h3 class="card-title"><?= e($movie['title'] ?? 'Unknown') ?></h3>
                    <p class="card-meta">
                        <span class="rating">&#9733; <?= e(number_format((float)($movie['rating'] ?? 0), 1)) ?></span>
                        <span class="dot">&middot;</span>
                        <span><?= e($movie['releaseDate'] ?? 'N/A') ?></span>
                    </p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php 
                // Preserve search query in pagination links
                $queryStr = '&sort=' . urlencode($sort) . '&q=' . urlencode($search); 
            ?>
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $queryStr ?>" class="btn btn-ghost">&laquo; Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?><?= $queryStr ?>" class="btn btn-ghost">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php poster_loader_script(); ?>
<?php page_footer(); ?>