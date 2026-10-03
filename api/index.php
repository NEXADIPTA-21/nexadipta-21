<?php

declare(strict_types=1);

/**
 * SKANEXA PHP entrypoint for Vercel
 */

// Ambil URL path asli yang diminta browser.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Hilangkan query string.
$path = parse_url($requestUri, PHP_URL_PATH);

if (!is_string($path) || $path === '') {
    $path = '/';
}

// Normalisasi path.
$path = '/' . ltrim(rawurldecode($path), '/');

// Halaman utama.
if ($path === '/') {
    $target = '/index.php';
}
// Website NEXADIPTA 21.
elseif ($path === '/nexadipta-21' || $path === '/nexadipta-21/') {
    $target = '/nexadipta-21/index.php';
}
// Request PHP lainnya.
elseif (preg_match('#\.php$#i', $path)) {
    $target = $path;
}
// Selain PHP tidak dijalankan oleh router.
else {
    http_response_code(404);
    exit('Not Found');
}

// Root project.
$root = realpath(__DIR__ . '/..');

if ($root === false) {
    http_response_code(500);
    exit('Project root not found');
}

// Lokasi file PHP tujuan.
$file = realpath($root . $target);

// Pastikan file ada.
if ($file === false || !is_file($file)) {
    http_response_code(404);
    exit('PHP file not found: ' . htmlspecialchars($target));
}

// Pastikan file tetap berada di dalam project.
if (!str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    exit('Forbidden');
}

// Beri informasi path kepada aplikasi.
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['PHP_SELF'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $file;

// Jalankan PHP asli.
require $file;
