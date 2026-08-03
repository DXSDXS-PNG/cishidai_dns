<?php

$proxied = require __DIR__ . '/proxy.php';
if ($proxied === true) {
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

if (strpos($uri, '/api/') === 0) {
    require __DIR__ . '/api.php';
    exit;
}

if ($uri === '/admin' || strpos($uri, '/admin/') === 0) {
    require __DIR__ . '/admin.html';
    exit;
}

if ($uri === '/' || $uri === '/portal' || $uri === '/home') {
    require __DIR__ . '/home.html';
    exit;
}

require __DIR__ . '/user.html';
