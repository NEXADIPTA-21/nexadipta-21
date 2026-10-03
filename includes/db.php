<?php
require_once __DIR__ . '/../config.php';

/**
 * Mengembalikan koneksi PDO tunggal ke Supabase PostgreSQL.
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_pgsql')) {
        http_response_code(500);
        die('Driver PostgreSQL PHP tidak tersedia.');
    }
    if (!DB_PASS) {
        http_response_code(500);
        die('Konfigurasi database belum lengkap.');
    }

    try {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=require';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulated prepares are friendlier to Supabase/PgBouncer transaction pooling.
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        die('Koneksi database gagal. Silakan hubungi admin.');
    }
}

/** PostgreSQL equivalent for MySQL PDO::lastInsertId(). */
function db_last_insert_id(PDO $pdo): int
{
    return (int)$pdo->query('SELECT LASTVAL()')->fetchColumn();
}
