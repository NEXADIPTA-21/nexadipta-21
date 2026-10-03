<?php

/**
 * SKANEXA V12.1
 * Database-backed PHP sessions untuk Vercel/serverless.
 *
 * Session PHP tidak disimpan di filesystem lokal Vercel karena
 * filesystem function bersifat ephemeral.
 */
class SkanexaDatabaseSessionHandler implements SessionHandlerInterface
{
    private ?PDO $pdo = null;

    private function db(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = 'pgsql:host=' . DB_HOST
            . ';port=' . DB_PORT
            . ';dbname=' . DB_NAME
            . ';sslmode=require';

        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);

        return $this->pdo;
    }

    public function open(string $path, string $name): bool
    {
        try {
            $this->db()->exec("
                CREATE TABLE IF NOT EXISTS app_sessions (
                    session_id TEXT PRIMARY KEY,
                    session_data TEXT NOT NULL DEFAULT '',
                    expires_at TIMESTAMPTZ NOT NULL
                )
            ");

            return true;
        } catch (Throwable $e) {
            error_log(
                'Session storage init failed: ' . $e->getMessage()
            );

            return false;
        }
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db()->prepare(
                'SELECT session_data
                 FROM app_sessions
                 WHERE session_id = ?
                   AND expires_at > NOW()
                 LIMIT 1'
            );

            $stmt->execute([$id]);

            $data = $stmt->fetchColumn();

            return $data === false ? '' : (string)$data;
        } catch (Throwable $e) {
            error_log(
                'Session read failed: ' . $e->getMessage()
            );

            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $stmt = $this->db()->prepare(
                "INSERT INTO app_sessions
                    (session_id, session_data, expires_at)
                 VALUES
                    (?, ?, NOW() + INTERVAL '8 hours')
                 ON CONFLICT (session_id)
                 DO UPDATE SET
                    session_data = EXCLUDED.session_data,
                    expires_at = EXCLUDED.expires_at"
            );

            $stmt->execute([$id, $data]);

            return true;
        } catch (Throwable $e) {
            error_log(
                'Session write failed: ' . $e->getMessage()
            );

            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->db()->prepare(
                'DELETE FROM app_sessions
                 WHERE session_id = ?'
            );

            $stmt->execute([$id]);

            return true;
        } catch (Throwable $e) {
            error_log(
                'Session destroy failed: ' . $e->getMessage()
            );

            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db()->query(
                'DELETE FROM app_sessions
                 WHERE expires_at <= NOW()'
            );

            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log(
                'Session GC failed: ' . $e->getMessage()
            );

            return false;
        }
    }
}
