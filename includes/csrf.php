<?php

/** Ambil (atau buat) token CSRF untuk session saat ini. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Cetak input hidden CSRF untuk dipakai di dalam <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validasi token CSRF dari request POST. Menghentikan request jika tidak valid. */
function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('Permintaan tidak valid (CSRF token salah). Silakan kembali dan coba lagi.');
    }
}
