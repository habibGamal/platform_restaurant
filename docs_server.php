<?php

/**
 * Local documentation router for PHP built-in web server.
 * Usage: php -S 127.0.0.1:3333 docs_server.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$root = __DIR__;
$docsDir = $root.DIRECTORY_SEPARATOR.'docs';

// Strip leading /docs if present
if (str_starts_with($uri, '/docs/')) {
    $uri = substr($uri, 5);
}

// Any request for _sidebar.md always serves the root docs/_sidebar.md
if (str_ends_with($uri, '_sidebar.md')) {
    header('Content-Type: text/markdown; charset=UTF-8');
    readfile($docsDir.DIRECTORY_SEPARATOR.'_sidebar.md');
    exit;
}

// Root changelog mapping
if ($uri === '/CHANGELOG.md' || $uri === 'CHANGELOG.md') {
    header('Content-Type: text/markdown; charset=UTF-8');
    readfile($root.DIRECTORY_SEPARATOR.'CHANGELOG.md');
    exit;
}

// Default root request
if ($uri === '/' || $uri === '' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile($docsDir.DIRECTORY_SEPARATOR.'index.html');
    exit;
}

// Check if file exists under docs/
$targetFile = $docsDir.str_replace('/', DIRECTORY_SEPARATOR, $uri);

if (file_exists($targetFile) && ! is_dir($targetFile)) {
    $ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $mimes = [
        'md' => 'text/markdown; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: '.$mimes[$ext]);
        readfile($targetFile);
        exit;
    }

    return false; // Let PHP built-in server serve other static types
}

// Directory with README.md
if (is_dir($targetFile) && file_exists($targetFile.DIRECTORY_SEPARATOR.'README.md')) {
    header('Content-Type: text/markdown; charset=UTF-8');
    readfile($targetFile.DIRECTORY_SEPARATOR.'README.md');
    exit;
}

// If a markdown file is requested but not found, return 404 rather than HTML
if (str_ends_with($uri, '.md')) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

// Fallback to docs/index.html for client-side routing
header('Content-Type: text/html; charset=UTF-8');
readfile($docsDir.DIRECTORY_SEPARATOR.'index.html');
exit;
