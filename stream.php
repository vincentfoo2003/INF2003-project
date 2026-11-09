<?php
require_once __DIR__ . '/config.php';

$user = current_user();
$id   = strtolower((string)($_GET['id'] ?? ''));
$loan = ($user !== null && preg_match('/^[a-f0-9]{24}$/', $id)) ? active_loan((int)$user['user_id'], $id) : null;
if ($loan === null) {
    http_response_code(403); exit('No active rental for this movie.');
}
$endsAt = loan_ends_at($loan);
// Don't hold the session lock while a long video is being sent
session_write_close();

$file = VIDEO_DIR . '/' . $id . '.mp4';
if (!is_file($file)) { header('Location: ' . DEMO_VIDEO_URL); exit; }

$size  = filesize($file);
$start = 0;
$end   = $size - 1;

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store');

// Honour single byte-range requests so the player can seek
if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', (string)$_SERVER['HTTP_RANGE'], $m) || ($m[1] === '' && $m[2] === '')) {
        http_response_code(416); header("Content-Range: bytes */$size"); exit;
    }
    if ($m[1] === '') {
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min($end, (int)$m[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416); header("Content-Range: bytes */$size"); exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();

$fp = fopen($file, 'rb');
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    // The end time can't change mid-request, so check the clock rather than re-query the DB per chunk
    if (time() >= $endsAt) break;
    $chunk = fread($fp, min(262144, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($fp);
