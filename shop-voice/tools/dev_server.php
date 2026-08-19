<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server, for local development only.
 *
 *   php -S 127.0.0.1:8099 -t public_html tools/dev_server.php
 *
 * Production runs on Apache under cPanel, where public_html/.htaccess does this
 * job. Nothing here ships.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public_html' . $path;

// Let the built-in server handle real static files itself.
if ($path !== '/' && is_file($file)) {
    return false;
}

// Directory index. Apache does this under cPanel via DirectoryIndex; the
// built-in server does not, and /bay/ is the client's entry point.
if (is_dir($file) && is_file(rtrim($file, '/') . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(rtrim($file, '/') . '/index.html');
    return true;
}

require __DIR__ . '/../public_html/index.php';
