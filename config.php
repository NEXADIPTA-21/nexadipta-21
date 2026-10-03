<?php
/**
 * SKANEXA V.12.1 - environment based configuration.
 *
 * For Vercel, set these values as Environment Variables. Do not put
 * database passwords or application secrets in source control.
 */

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') return $default;
    return $value;
}

define('DB_HOST', env_value('DB_HOST', 'db.eqagkoebmwrslwbityvu.supabase.co'));
define('DB_PORT', (int)env_value('DB_PORT', '5432'));
define('DB_NAME', env_value('DB_NAME', 'postgres'));
define('DB_USER', env_value('DB_USER', 'postgres'));
define('DB_PASS', env_value('DB_PASS'));

define('APP_TIMEZONE', env_value('APP_TIMEZONE', 'Asia/Jakarta'));
define('APP_NAME', env_value('APP_NAME', 'Polling Foto Angkatan'));
define('APP_SECRET', env_value('APP_SECRET', ''));

// Supabase project URL is public information; service/database secrets are not.
define('SUPABASE_URL', rtrim((string)env_value('SUPABASE_URL', 'https://eqagkoebmwrslwbityvu.supabase.co'), '/'));
define('SUPABASE_STORAGE_BUCKET', env_value('SUPABASE_STORAGE_BUCKET', 'skanexa-media'));

// Upload paths are kept as logical prefixes. Persistent uploads will be moved
// to Supabase Storage in the next deployment step.
define('UPLOAD_DIR', __DIR__ . '/uploads/candidates/');
define('UPLOAD_URL', 'uploads/candidates/');
define('QUESTION_UPLOAD_DIR', __DIR__ . '/uploads/questions/');
define('QUESTION_UPLOAD_URL', 'uploads/questions/');
define('OPTION_UPLOAD_DIR', __DIR__ . '/uploads/options/');
define('OPTION_UPLOAD_URL', 'uploads/options/');
define('MAX_UPLOAD_SIZE', 3 * 1024 * 1024);

date_default_timezone_set(APP_TIMEZONE);

if (APP_SECRET === '') {
    // A deterministic fallback keeps local development possible, but Vercel
    // should always receive a random APP_SECRET environment variable.
    define('EFFECTIVE_APP_SECRET', hash('sha256', SUPABASE_URL . '|skanexa-local'));
} else {
    define('EFFECTIVE_APP_SECRET', APP_SECRET);
}

if (session_status() === PHP_SESSION_NONE) {
    $cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $cookieSecure,
    ]);
    session_start();
}

if (APP_SECRET === '') {
    define(
        'EFFECTIVE_APP_SECRET',
        hash('sha256', SUPABASE_URL . '|skanexa-local')
    );
} else {
    define('EFFECTIVE_APP_SECRET', APP_SECRET);
}

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_handler.php';

    $sessionHandler = new SkanexaDatabaseSessionHandler();

    session_set_save_handler(
        $sessionHandler,
        true
    );

    $cookieSecure = (
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $cookieSecure,
    ]);

    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
