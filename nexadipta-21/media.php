<?php
// Public, read-only bridge for legacy uploads stored one level above the website document root.
// Only paths under uploads/ are allowed.
$relative = isset($_GET['file']) ? rawurldecode((string)$_GET['file']) : '';
$relative = str_replace('\\', '/', $relative);
$relative = ltrim($relative, '/');
if ($relative === '' || strpos($relative, 'uploads/') !== 0 || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
    http_response_code(400);
    exit('Invalid media path');
}
$root = realpath(__DIR__ . '/..');
$file = realpath(__DIR__ . '/../' . $relative);
$uploadsRoot = realpath(__DIR__ . '/../uploads');
if (!$root || !$file || !$uploadsRoot || strpos($file, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Media not found');
}
$mime = function_exists('mime_content_type') ? mime_content_type($file) : '';
$allowed = [
    'image/jpeg' => 'image/jpeg',
    'image/png' => 'image/png',
    'image/webp' => 'image/webp',
    'image/gif' => 'image/gif',
    'image/svg+xml' => 'image/svg+xml',
];
if (!isset($allowed[$mime])) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'][$ext] ?? '';
}
if ($mime === '') { http_response_code(415); exit('Unsupported media type'); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Cache-Control: public, max-age=86400');
readfile($file);
exit;
