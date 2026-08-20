<?php
// Log out the current user.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

respond(['ok' => true, 'message' => 'You have been logged out.']);
