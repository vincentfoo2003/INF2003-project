<?php
require_once __DIR__ . '/config.php';

$sortOptions = [
    'rating'       => 'Highest rating',
    'release_desc' => 'Newest release date',
    'release_asc'  => 'Oldest release date',
];

$sort = (string)($_GET['sort'] ?? 'rating');
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'rating';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 24;
$skip = ($page - 1) * $limit;

try {
    $total = mongo_count();
    $totalPages = max(1, ceil($total / $limit));

    if ($sort === 'release_desc' || $sort === 'release_asc') {
        $order = $sort === 'release_desc' ? -1 : 1;
        
        // Safely parse dates by splitting the string and using $convert with onError fallbacks
        $pipeline = [
            ['$addFields' => [
                'dateParts' => ['$split' => [['$ifNull' => ['$releaseDate', '1/1/1970']], '/']]
            ]],
            ['$addFields' => [
                'parsedYear' => [
                    '$convert' => [
                        'input' => ['$arrayElemAt' => ['$dateParts', 2]],
                        'to' => 'int',
                        'onError' => 1970,
                        'onNull' => 1970
                    ]
                ],
                'parsedMonth' => [
                    '$convert' => [
                        'input' => ['$arrayElemAt' => ['$dateParts', 1]],
                        'to' => 'int',
                        'onError' => 1,
                        'onNull' => 1
                    ]
                ],
                'parsedDay' => [
                    '$convert' => [
                        'input' => ['$arrayElemAt' => ['$dateParts', 0]],
                        'to' => 'int',
                        'onError' => 1,
                        'onNull' => 1
                    ]
                ]
            ]],
            ['$sort' => [
                'parsedYear' => $order,
                'parsedMonth' => $order,
                'parsedDay' => $order
            ]],
            ['$skip' => $skip],
            ['$limit' => $limit]
        ];
        $movies = mongo_aggregate($pipeline);
    } else {
        // Default: Sort by rating
        $options = [
            'sort' => ['rating' => -1],
            'limit' => $limit,
            'skip' => $skip
        ];
        $movies = mongo_find([], $options);
    }
} catch (Throwable $e) {
    error_log('MongoDB error: ' . $e->getMessage());
    die('Database error: ' . $e->getMessage()); 
}

$placeholder = placeholder_poster();

page_header('Movies');
?>
<section class="toolbar">
    <h1>Browse movies</h1>
    <form method="get" action="index.php" class="filter-form">
        <label for="sort">Sort by</label>
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
    <p class="empty">No movies found in the database.</p>
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
            <?php if ($page > 1): ?>
                <a href="?sort=<?= e($sort) ?>&page=<?= $page - 1 ?>" class="btn btn-ghost">&laquo; Previous</a>
            <?php endif; ?>
            <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="?sort=<?= e($sort) ?>&page=<?= $page + 1 ?>" class="btn btn-ghost">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php poster_loader_script(); ?>
<?php page_footer(); ?>