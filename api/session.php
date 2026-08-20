<?php
// Return the current session user (used to keep pages in sync).
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user(db());
if ($user === null) {
    respond(['ok' => true, 'user' => null]);
}

respond(['ok' => true, 'user' => public_user($user)]);
