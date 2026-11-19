<?php
require_once __DIR__ . '/config.php';

/*
 * Video streaming endpoint (Option A: redirect to a GCS signed URL).
 *
 * Access control stays here: only a user with an ACTIVE rental for this movie
 * gets a URL. We then issue a short-lived V4 signed URL to the object in the
 * private GCS bucket and redirect the player to it, so the video streams
 * straight from Google (range/seek handled by GCS, no bytes proxied through
 * this server). The signed URL's lifetime is capped at the rental end, so a
 * URL can never outlive the loan.
 *
 * Fallbacks (so playback still works in dev / misconfig):
 *   1. a local VIDEO_DIR/<id>.mp4 file, if present → stream it with range support
 *   2. DEMO_VIDEO_URL, if neither GCS nor a local file is available
 */

$user = current_user();
$id   = strtolower((string)($_GET['id'] ?? ''));
$loan = ($user !== null && preg_match('/^[a-f0-9]{24}$/', $id)) ? active_loan((int)$user['user_id'], $id) : null;
if ($loan === null) {
    http_response_code(403); exit('No active rental for this movie.');
}
$endsAt = loan_ends_at($loan);

// Don't hold the session lock while we work
session_write_close();

/* ---- Primary path: redirect to a GCS signed URL (expiry capped at rental end) ---- */
$signed = gcs_signed_video_url(GCS_VIDEO_OBJECT, $endsAt);
if ($signed !== null) {
    header('Cache-Control: private, no-store');
    header('Location: ' . $signed, true, 302);
    exit;
}

/* ---- Fallback 1: local file with byte-range support (original behaviour) ---- */
$file = VIDEO_DIR . '/' . $id . '.mp4';
if (!is_file($file)) {
    // ---- Fallback 2: public demo clip ----
    header('Location: ' . DEMO_VIDEO_URL);
    exit;
}

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
