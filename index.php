<?php
declare(strict_types=1);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Serve login directly with HTTP 200 OK so Chrome PWA start_url check always succeeds without 302 redirect
if (empty($_SESSION['authenticated'])) {
    require __DIR__ . '/login.html';
    exit;
}

require __DIR__ . '/app.html';
