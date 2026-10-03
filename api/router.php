<?php
// Vercel PHP entrypoint. It dispatches /foo.php to the real PHP file in the project root.
$requested = (string)($_GET['path'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$requested = '/' . ltrim(rawurldecode($requested), '/');
$requested = preg_replace('#/+#', '/', $requested);

// Strip the router's own path if a direct request is made to it.
if ($requested === '/api/router.php' || $requested === '/') {
    $requested = '/index.php';
}

// Only execute PHP files that actually exist inside the project root.
$relative = ltrim($requested, '/');
if (!str_ends_with(strtolower($relative), '.php')) {
    http_response_code(404);
    exit('Not Found');
}

$target = realpath(__DIR__ . '/../' . $relative);
$root = realpath(__DIR__ . '/..');
if ($target === false || $root === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR) || !is_file($target)) {
    http_response_code(404);
    exit('Not Found');
}

// Preserve the original URL for application code that builds relative paths.
$_SERVER['SCRIPT_NAME'] = $requested;
$_SERVER['PHP_SELF'] = $requested;

require $target;
