<?php

// PHP Built-in Server Router
// Usage: php -S localhost:8080 -t api api/router.php

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Serve static files directly if they exist
$filePath = __DIR__ . $path;
if ($path !== '/' && file_exists($filePath) && is_file($filePath)) {
    return false;
}

// Route everything else to index.php
$_SERVER['REQUEST_URI'] = $uri;
require __DIR__ . '/index.php';
