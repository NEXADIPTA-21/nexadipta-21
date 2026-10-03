<?php

/**
 * SKANEXA - Vercel PHP Router
 *
 * Semua halaman PHP diarahkan melalui file ini.
 */

declare(strict_types=1);

// Ambil path yang dikirim oleh Vercel.
$path = (string)($_GET['path'] ?? '/');

// Bersihkan path.
$path = rawurldecode($path);
$path = '/' . ltrim($path, '/');
$path = preg_replace('#/+#', '/', $path);

// Hilangkan query string jika ikut terbawa.
$path = strtok($path, '?') ?: '/';

// Halaman utama.
if ($path === '/' || $path === '') {
    $path = '/index.php';
}

// Hanya izinkan file PHP.
if (!preg_match('/\.php$/i', $path)) {
    http_response_code(404);
    exit('Not Found');
}

// Tentukan lokasi file PHP sebenarnya.
$root = realpath(__DIR__ . '/..');
$target = realpath($root . $path);

// Pastikan file berada di dalam project.
if (
    $root === false ||
    $target === false ||
    !str_starts_with($target, $root . DIRECTORY_SEPARATOR) ||
    !is_file($target)
) {
    http_response_code(404);
    exit('PHP file not found');
}

// Pastikan yang dijalankan memang PHP.
if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'php') {
    http_response_code(404);
    exit('Invalid file type');
}

// Beri tahu aplikasi PHP file/path yang sedang dipanggil.
$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['SCRIPT_FILENAME'] = $target;

// Jalankan file PHP asli.
require $target;
