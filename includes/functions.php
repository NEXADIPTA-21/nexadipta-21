<?php
require_once __DIR__ . '/db.php';

function e(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function normalize_text(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return mb_strtolower($value, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function hash_ip(string $ip): string
{
    return hash('sha256', $ip . '|' . APP_SECRET);
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $parts = explode(',', $_SERVER[$key]);
            return trim($parts[0]);
        }
    }
    return '0.0.0.0';
}

/**
 * Cek jadwal sebuah polling. Poll hanya dianggap bisa diakses jika status active
 * dan berada di dalam rentang start/end (jika diatur).
 */
function poll_is_open(array $poll): bool
{
    $status = (string)($poll['status'] ?? '');
    // 'scheduled' becomes accessible automatically once its start time is
    // reached. This prevents a valid token from appearing broken simply
    // because the admin selected Scheduled instead of manually switching to
    // Active at the exact start time. Paused/closed/draft remain unavailable.
    if (!in_array($status, ['active', 'scheduled'], true)) return false;

    $now = new DateTime('now', new DateTimeZone(APP_TIMEZONE));

    if (!empty($poll['start_at'])) {
        $start = new DateTime((string)$poll['start_at'], new DateTimeZone(APP_TIMEZONE));
        if ($now < $start) return false;
    }

    if (!empty($poll['end_at'])) {
        $end = new DateTime((string)$poll['end_at'], new DateTimeZone(APP_TIMEZONE));
        if ($now > $end) return false;
    }

    return true;
}

/** Ambil polling berdasarkan ID. */
function get_poll_by_id(int $pollId): ?array
{
    if ($pollId <= 0) {
        return null;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM polls WHERE id = ? LIMIT 1');
    $stmt->execute([$pollId]);
    $poll = $stmt->fetch();

    return $poll ?: null;
}

/**
 * Ambil polling aktif terbaru.
 * Dipertahankan untuk kompatibilitas halaman admin/legacy, tetapi halaman vote
 * multi-polling tidak boleh mengandalkannya sebagai sumber poll session.
 */
function get_active_poll(): ?array
{
    $pdo = get_db();
    $stmt = $pdo->query("SELECT * FROM polls WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $poll = $stmt->fetch();

    if (!$poll || !poll_is_open($poll)) {
        return null;
    }

    return $poll;
}

/** Ambil polling berdasarkan token plaintext tanpa pernah menyimpan token plaintext di DB. */
function normalize_poll_token(string $token): string
{
    // Token yang dibuat aplikasi hanya berisi A-Z dan angka 2-9.
    // Normalisasi membuat copy/paste atau input manual tidak gagal karena
    // huruf kecil, spasi, atau tanda hubung yang tidak sengaja ikut masuk.
    $token = strtoupper(trim($token));
    $token = preg_replace('/[\s\-]+/u', '', $token) ?? '';
    return $token;
}

function get_poll_by_token(string $token): ?array
{
    $token = normalize_poll_token($token);
    if ($token === '' || strlen($token) > 64 || !preg_match('/^[A-Z0-9]+$/', $token)) {
        return null;
    }

    $pdo = get_db();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT * FROM polls WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $poll = $stmt->fetch();

    if (!$poll || !poll_is_open($poll)) {
        return null;
    }

    return $poll;
}

function get_active_classes(): array
{
    $pdo = get_db();
    $stmt = $pdo->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name ASC");
    return $stmt->fetchAll();
}

function get_verified_participant(): ?array
{
    return $_SESSION['participant'] ?? null;
}

function clear_participant_session(): void
{
    unset($_SESSION['participant']);
    unset($_SESSION['pending_candidate_id']);
    unset($_SESSION['poll_id']);
    unset($_SESSION['poll_token_verified']);
    unset($_SESSION['poll_token_at']);
    unset($_SESSION['questionnaire_source_poll_id']);
    unset($_SESSION['candidate_order_seed']);
}

/**
 * Apakah peserta sudah vote pada polling tertentu?
 * Sumber kebenaran untuk multi-polling adalah tabel votes, bukan participants.has_voted.
 */
function participant_has_voted(int $pollId, int $participantId): bool
{
    if ($pollId <= 0 || $participantId <= 0) {
        return false;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT 1 FROM votes WHERE poll_id = ? AND participant_id = ? LIMIT 1');
    $stmt->execute([$pollId, $participantId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Pastikan peserta memang terdaftar untuk polling tersebut melalui poll_participants.
 */
function participant_allowed_for_poll(int $pollId, int $participantId): bool
{
    if ($pollId <= 0 || $participantId <= 0) {
        return false;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT 1 FROM poll_participants pp
         INNER JOIN participants p ON p.id = pp.participant_id
         INNER JOIN classes c ON c.id = p.class_id
         WHERE pp.poll_id = ?
           AND pp.participant_id = ?
           AND pp.status = \'active\'
           AND p.status = \'active\'
           AND c.status = \'active\'
         LIMIT 1'
    );
    $stmt->execute([$pollId, $participantId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Buat token polling acak. Token plaintext hanya dikembalikan sekali ke caller;
 * database hanya menyimpan SHA-256-nya.
 */
function admin_audit(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    string $description = ''
): void {
    static $tableReady = null;

    try {
        $pdo = get_db();

        $tableReady = true;

        $adminId = !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (admin_id, action, entity_type, entity_id, description, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $adminId,
            $action,
            $entityType,
            $entityId,
            $description,
            hash_ip(client_ip()),
        ]);
    } catch (Throwable $e) {
        // Audit failure must never break the operation being audited.
        error_log('Audit log failed: ' . $e->getMessage());
    }
}


function get_poll_settings(int $pollId): array
{
    if ($pollId <= 0) {
        return [
            'min_choices' => 1,
            'max_choices' => 1,
            'allow_change_vote' => 0,
            'show_results' => 0,
            'randomize_candidates' => 0,
        ];
    }
    $stmt = get_db()->prepare('SELECT * FROM poll_settings WHERE poll_id = ? LIMIT 1');
    $stmt->execute([$pollId]);
    $row = $stmt->fetch();
    return $row ?: [
        'min_choices' => 1,
        'max_choices' => 1,
        'allow_change_vote' => 0,
        'show_results' => 0,
        'randomize_candidates' => 0,
    ];
}

function get_public_poll_result_rows(int $pollId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.image_url, c.description, c.status, COUNT(v.id) AS vote_count
         FROM candidates c
         LEFT JOIN votes v ON v.candidate_id = c.id AND v.poll_id = c.poll_id
         WHERE c.poll_id = ?
         GROUP BY c.id, c.name, c.image_url, c.description, c.status
         ORDER BY vote_count DESC, c.sort_order ASC, c.id ASC'
    );
    $stmt->execute([$pollId]);
    return $stmt->fetchAll();
}

function audit_logs_available(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        get_db()->query('SELECT 1 FROM audit_logs LIMIT 1');
        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}


function db_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function db_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_questionnaire_schema(PDO $pdo): void
{
    // The PostgreSQL questionnaire schema is installed by
    // database/SKANEXA_supabase_schema.sql before the application runs.
    // Runtime DDL from the original MySQL/cPanel build is intentionally disabled.
    static $done = false;
    $done = true;
}


function get_questionnaire_photo_targets(PDO $pdo, int $excludePollId = 0): array
{
    $st = $pdo->prepare("SELECT id, title, status, poll_type FROM polls WHERE poll_type IN ('single_choice','image_choice') AND id<>? ORDER BY id DESC");
    $st->execute([$excludePollId]);
    return $st->fetchAll();
}

function participant_can_enter_poll(int $pollId, int $participantId): bool
{
    return participant_allowed_for_poll($pollId, $participantId);
}

function get_poll_questions(int $pollId): array
{
    $stmt = get_db()->prepare(
        'SELECT q.*, (SELECT COUNT(*) FROM poll_question_options o WHERE o.question_id = q.id) AS option_count
         FROM poll_questions q
         WHERE q.poll_id = ? AND q.status = \'active\'
         ORDER BY q.sort_order ASC, q.id ASC'
    );
    $stmt->execute([$pollId]);
    return $stmt->fetchAll();
}

function get_poll_question(int $questionId, int $pollId): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM poll_questions WHERE id = ? AND poll_id = ? LIMIT 1');
    $stmt->execute([$questionId, $pollId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_question_options(int $questionId): array
{
    $stmt = get_db()->prepare('SELECT * FROM poll_question_options WHERE question_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$questionId]);
    return $stmt->fetchAll();
}

function get_or_create_poll_participation(int $pollId, int $participantId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM poll_participations WHERE poll_id = ? AND participant_id = ? LIMIT 1');
    $stmt->execute([$pollId, $participantId]);
    $row = $stmt->fetch();
    if ($row) return $row;
    try {
        $ins = $pdo->prepare('INSERT INTO poll_participations (poll_id, participant_id, status) VALUES (?, ?, \'in_progress\')');
        $ins->execute([$pollId, $participantId]);
    } catch (PDOException $e) {
        // Concurrent requests may both try to create the same unique session.
        // Re-read the row instead of turning a harmless race into an error.
        if ((string)$e->getCode() !== '23000') throw $e;
    }
    $stmt->execute([$pollId, $participantId]);
    return $stmt->fetch() ?: ['poll_id'=>$pollId,'participant_id'=>$participantId,'status'=>'in_progress'];
}

function get_poll_participation(int $pollId, int $participantId): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM poll_participations WHERE poll_id = ? AND participant_id = ? LIMIT 1');
    $stmt->execute([$pollId, $participantId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function questionnaire_complete(int $pollId, int $participantId): bool
{
    $p = get_poll_participation($pollId, $participantId);
    return $p && in_array($p['status'], ['completed','terminated'], true);
}

function get_questionnaire_settings(int $pollId): array
{
    $stmt = get_db()->prepare('SELECT * FROM poll_settings WHERE poll_id = ? LIMIT 1');
    $stmt->execute([$pollId]);
    return $stmt->fetch() ?: ['max_questions' => 0];
}

function get_next_question_for_answer(int $pollId, int $questionId, int $optionId): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT nq.* FROM poll_question_options o
         JOIN poll_questions nq ON nq.id = o.next_question_id AND nq.poll_id = ? AND nq.status = \'active\'
         WHERE o.id = ? AND o.question_id = ? LIMIT 1'
    );
    $stmt->execute([$pollId, $optionId, $questionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function generate_poll_token(int $length = 12): string
{
    $length = max(8, min(64, $length));
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $token = '';

    for ($i = 0; $i < $length; $i++) {
        $token .= $alphabet[random_int(0, $max)];
    }

    return $token;
}


/** Aman dipanggil walau tabel questionnaire belum dibuat. */
function poll_questionnaire_done(int $pollId, int $participantId): bool
{
    try {
        ensure_questionnaire_schema(get_db());
        return questionnaire_complete($pollId, $participantId);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Hasil per pertanyaan untuk polling questionnaire.
 * Menyertakan foto jawaban dan mengurutkan berdasarkan vote terbanyak.
 */
function get_questionnaire_result_rows(int $pollId): array
{
    $pdo = get_db();

    ensure_questionnaire_schema($pdo);

    $qs = $pdo->prepare(
        "SELECT id, question_text
         FROM poll_questions
         WHERE poll_id = ?
           AND status = 'active'
         ORDER BY sort_order, id"
    );

    $qs->execute([$pollId]);

    $out = [];

    $os = $pdo->prepare(
        "SELECT
            o.id,
            o.option_text,
            o.image_url,
            o.sort_order,
            COUNT(a.id) AS vote_count
         FROM poll_question_options o

         LEFT JOIN poll_answers a
           ON a.option_id = o.id
          AND a.poll_id = ?
          AND a.is_draft = 0

         WHERE o.question_id = ?

         GROUP BY
            o.id,
            o.option_text,
            o.image_url,
            o.sort_order

         ORDER BY
            COUNT(a.id) DESC,
            o.sort_order ASC,
            o.id ASC"
    );

    foreach ($qs->fetchAll() as $q) {

        $os->execute([
            $pollId,
            $q['id']
        ]);

        $out[] = [
            'question' => $q['question_text'],
            'options'  => $os->fetchAll()
        ];
    }

    return $out;
}
