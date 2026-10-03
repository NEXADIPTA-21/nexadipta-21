<?php
require_once __DIR__ . '/../../includes/functions.php';

/** Website angkatan content schema. Safe to call repeatedly. */
function ensure_nexadipta21_schema(PDO $pdo): void
{
    // Website schema is provisioned by database/SKANEXA_supabase_schema.sql.
    // Keep this function as a compatibility hook for existing callers.
}


function nx21_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM website_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function nx21_save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO website_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value=EXCLUDED.setting_value, updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$key, $value]);
}

function nx21_media_url(string $path): string
{
    $path = ltrim(trim($path), '/');
    if ($path === '') return '';
    global $base;
    $prefix = (isset($base) && $base !== '') ? rtrim($base, '/') : '';
    // Public files that live inside the current website folder.
    if (strpos($path, 'uploads/') !== 0) {
        return ($prefix === '' ? '/' : $prefix . '/') . $path;
    }
    // uploads/ lives one level above nexadipta-21, serve via media.php bridge.
    return ($prefix === '' ? '' : $prefix) . '/media.php?file=' . rawurlencode($path);
}

function nx21_upload_image(array $file, string $absoluteDir, string $relativePrefix): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload foto gagal.');
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Ukuran foto maksimal 5 MB.');
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true)) throw new RuntimeException('Folder upload tidak dapat dibuat.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], rtrim($absoluteDir, '/\\') . DIRECTORY_SEPARATOR . $name)) throw new RuntimeException('Foto gagal disimpan.');
    return rtrim($relativePrefix, '/') . '/' . $name;
}

function nx21_delete_upload(?string $relativePath): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/angkatan/') !== 0) return;
    $root = dirname(__DIR__, 2);
    $file = $root . '/' . ltrim($relativePath, '/');
    if (is_file($file)) @unlink($file);
}

function nx21_safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $parts = parse_url($url);
    if ($parts === false) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    return in_array($scheme, ['http','https','mailto'], true) ? $url : '';
}
