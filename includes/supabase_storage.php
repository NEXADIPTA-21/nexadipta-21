<?php

/**
 * SKANEXA V12.1
 * Supabase Storage helper.
 */

function supabase_storage_upload(
    string $localFile,
    string $storagePath,
    string $contentType
): string {
    if (!is_file($localFile)) {
        throw new RuntimeException(
            'File upload sementara tidak ditemukan.'
        );
    }

    if (SUPABASE_SERVICE_ROLE_KEY === '') {
        throw new RuntimeException(
            'SUPABASE_SERVICE_ROLE_KEY belum dikonfigurasi di Vercel.'
        );
    }

    $storagePath = ltrim($storagePath, '/');

    $url = SUPABASE_URL
        . '/storage/v1/object/'
        . rawurlencode(SUPABASE_STORAGE_BUCKET)
        . '/'
        . str_replace('%2F', '/', rawurlencode($storagePath));

    $body = file_get_contents($localFile);

    if ($body === false) {
        throw new RuntimeException(
            'File upload tidak dapat dibaca.'
        );
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
            'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
            'Content-Type: ' . $contentType,
            'Cache-Control: 3600',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException(
            'Upload ke Supabase Storage gagal: ' . $curlError
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Upload ke Supabase Storage gagal (HTTP '
            . $httpCode
            . '): '
            . $response
        );
    }

    return SUPABASE_URL
        . '/storage/v1/object/public/'
        . rawurlencode(SUPABASE_STORAGE_BUCKET)
        . '/'
        . str_replace('%2F', '/', rawurlencode($storagePath));
}
