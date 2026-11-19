<?php
/*
 * delete_account.php — a user deletes their OWN account.
 *
 * POST only, CSRF-protected. Deletes the logged-in user's account (admins are
 * blocked by delete_user_account()), clears the session, and logs them out.
 *
 * Destructive: the user's loans and payments cascade-delete with the account.
 * The dashboard button that posts here shows an explicit confirmation first.
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php'); exit;
}
csrf_check();

$user = require_login();
$uid  = (int)$user['user_id'];

// Self-delete: requester and target are the same account. The helper enforces
// the last-admin rule (an admin who is the only admin is rejected here).
[$ok, $message] = delete_user_account($uid, $uid);

if (!$ok) {
    flash($message, 'error');
    header('Location: dashboard.php'); exit;
}

// Account is gone — tear down the session completely and send them home.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

session_start();                 // fresh session just to carry the flash message
flash('Your account and all associated rentals have been deleted.');
header('Location: index.php');
exit;
