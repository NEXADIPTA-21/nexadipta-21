<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pdo = get_db();
$error = null;
$generatedToken = null;
$action = (string)($_GET['action'] ?? 'list');
$allowedStatuses = ['draft', 'scheduled', 'active', 'paused', 'closed', 'archived', 'inactive'];
$allowedTypes = ['single_choice', 'image_choice', 'questionnaire'];
$allowedAccessModes = ['token_verification'];

// Questionnaire schema is prepared automatically for logged-in admins.
// This removes the need to manually import a migration just to create a polling.
try {
    ensure_questionnaire_schema($pdo);
} catch (Throwable $e) {
    error_log('Questionnaire schema preparation failed: ' . $e->getMessage());
    $error = 'Struktur fitur polling belum dapat disiapkan. Periksa hak akses database atau log hosting.';
}

function settings_question_targets(PDO $pdo, int $pollId, int $excludeId = 0): array
{
    $st = $pdo->prepare('SELECT id, question_text, sort_order FROM poll_questions WHERE poll_id=? AND status=\'active\' AND id<>? ORDER BY sort_order,id');
    $st->execute([$pollId, $excludeId]);
    return $st->fetchAll();
}

function redirect_edit_poll(int $pollId, string $anchor = ''): void
{
    redirect('settings.php?action=edit&id=' . $pollId . ($anchor ? '#' . $anchor : ''));
}

function handle_question_image_upload(?array $file): ?string
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gambar pertanyaan gagal.');
    if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) throw new RuntimeException('Ukuran gambar pertanyaan maksimal 3MB.');
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Gambar pertanyaan harus JPG, PNG, atau WEBP.');
    if (!is_dir(QUESTION_UPLOAD_DIR) && !mkdir(QUESTION_UPLOAD_DIR, 0755, true) && !is_dir(QUESTION_UPLOAD_DIR)) {
        throw new RuntimeException('Folder gambar pertanyaan tidak dapat dibuat.');
    }
    $filename = bin2hex(random_bytes(12)).'.'.$allowed[$mime];
    $dest = QUESTION_UPLOAD_DIR.$filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Gagal menyimpan gambar pertanyaan.');
    return QUESTION_UPLOAD_URL.$filename;
}

function remove_question_image(?string $url): void
{
    if (!$url) return;
    $relative = ltrim(str_replace(['\\','..'], ['/',''], $url), '/');
    $base = realpath(QUESTION_UPLOAD_DIR);
    $file = realpath(dirname(__DIR__).'/'.$relative);
    if ($base && $file && str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
}

function handle_option_image_upload(?array $file): ?string
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gambar jawaban gagal.');
    if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) throw new RuntimeException('Ukuran gambar jawaban maksimal 3MB.');
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Gambar jawaban harus JPG, PNG, atau WEBP.');
    if (!is_dir(OPTION_UPLOAD_DIR) && !mkdir(OPTION_UPLOAD_DIR, 0755, true) && !is_dir(OPTION_UPLOAD_DIR)) {
        throw new RuntimeException('Folder gambar jawaban tidak dapat dibuat.');
    }
    $filename = bin2hex(random_bytes(12)).'.'.$allowed[$mime];
    $dest = OPTION_UPLOAD_DIR.$filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Gagal menyimpan gambar jawaban.');
    return OPTION_UPLOAD_URL.$filename;
}

function remove_option_image(?string $url): void
{
    if (!$url) return;
    $relative = ltrim(str_replace(['\\','..'], ['/',''], $url), '/');
    $base = realpath(OPTION_UPLOAD_DIR);
    $file = realpath(dirname(__DIR__).'/'.$relative);
    if ($base && $file && str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
}

/**
 * Simpan pengaturan polling tanpa bergantung pada urutan/kelengkapan kolom
 * migration lama. max_questions ditulis hanya jika kolomnya tersedia.
 */
function save_poll_settings(PDO $pdo, int $pollId, int $allowChangeVote, int $showResults, int $randomizeCandidates, int $maxQuestions): void
{
    if ($pollId <= 0) throw new RuntimeException('ID polling tidak valid.');

    $columns = [];
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='poll_settings'");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) $columns[(string)$column] = true;

    foreach (['poll_id','allow_change_vote','show_results','randomize_candidates'] as $required) {
        if (!isset($columns[$required])) {
            throw new RuntimeException('Tabel poll_settings tidak lengkap: kolom '.$required.' tidak ditemukan.');
        }
    }

    $find = $pdo->prepare('SELECT id FROM poll_settings WHERE poll_id=? LIMIT 1');
    $find->execute([$pollId]);
    $settingsId = $find->fetchColumn();

    if (!$settingsId) {
        // Insert hanya poll_id agar DEFAULT/NULL kolom lain pada schema lama
        // yang masih ada tidak menyebabkan jumlah nilai tidak cocok (SQL 1136).
        $pdo->prepare('INSERT INTO poll_settings (poll_id) VALUES (?)')->execute([$pollId]);
        $settingsId = db_last_insert_id($pdo);
    }

    $sets = ['allow_change_vote=?','show_results=?','randomize_candidates=?'];
    $params = [$allowChangeVote, $showResults, $randomizeCandidates];
    if (isset($columns['max_questions'])) {
        $sets[] = 'max_questions=?';
        $params[] = $maxQuestions;
    }
    if (isset($columns['updated_at'])) $sets[] = 'updated_at=NOW()';
    $params[] = $settingsId;

    $pdo->prepare('UPDATE poll_settings SET '.implode(',', $sets).' WHERE id=?')->execute($params);
}


// Create/update polling itself.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
    csrf_verify();
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $status = (string)($_POST['status'] ?? 'draft');
    $pollType = (string)($_POST['poll_type'] ?? 'single_choice');
    $accessMode = (string)($_POST['access_mode'] ?? 'token_verification');
    $startAt = trim((string)($_POST['start_at'] ?? '')) ?: null;
    $endAt = trim((string)($_POST['end_at'] ?? '')) ?: null;
    $id = (int)($_POST['id'] ?? 0);
    $allowChangeVote = !empty($_POST['allow_change_vote']) ? 1 : 0;
    $showResults = !empty($_POST['show_results']) ? 1 : 0;
    $randomizeCandidates = !empty($_POST['randomize_candidates']) ? 1 : 0;
    $maxQuestions = max(0, (int)($_POST['max_questions'] ?? 0));

    if ($title === '') $error = 'Judul polling wajib diisi.';
    elseif (!in_array($status, $allowedStatuses, true)) $error = 'Status polling tidak valid.';
    elseif (!in_array($pollType, $allowedTypes, true)) $error = 'Tipe polling tidak valid.';
    elseif (!in_array($accessMode, $allowedAccessModes, true)) $error = 'Mode akses tidak valid.';
    elseif (($startAt && !strtotime($startAt)) || ($endAt && !strtotime($endAt))) $error = 'Format waktu polling tidak valid.';
    elseif ($startAt && $endAt && $startAt >= $endAt) $error = 'Waktu mulai harus lebih awal daripada waktu selesai.';
    else {
        try {
            $pdo->beginTransaction();
            if ($action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO polls (title,description,poll_type,access_mode,status,start_at,end_at) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$title,$description,$pollType,$accessMode,$status,$startAt,$endAt]);
                $pollId = (int)db_last_insert_id($pdo);
                save_poll_settings($pdo, $pollId, $allowChangeVote, $showResults, $randomizeCandidates, $maxQuestions);
            } else {
                $check = $pdo->prepare('SELECT id FROM polls WHERE id=? LIMIT 1');
                $check->execute([$id]);
                if (!$check->fetch()) throw new RuntimeException('Polling tidak ditemukan.');
                $stmt = $pdo->prepare('UPDATE polls SET title=?,description=?,poll_type=?,access_mode=?,status=?,start_at=?,end_at=? WHERE id=?');
                $stmt->execute([$title,$description,$pollType,$accessMode,$status,$startAt,$endAt,$id]);
                $pollId = $id;
                save_poll_settings($pdo, $pollId, $allowChangeVote, $showResults, $randomizeCandidates, $maxQuestions);
            }
            $pdo->commit();
            admin_audit($action === 'add' ? 'poll.create' : 'poll.update','poll',$pollId,$title);
            flash_set('success', 'Pengaturan polling berhasil disimpan.');
            redirect_edit_poll($pollId, $pollType === 'questionnaire' ? 'questions' : 'top');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Poll settings save failed: ' . $ex->getMessage());
            $error = 'Pengaturan polling gagal disimpan. ' . ($ex instanceof RuntimeException ? $ex->getMessage() : '');
        }
    }
}

// Questionnaire actions live on this same settings page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (str_starts_with($action, 'question_') || str_starts_with($action, 'option_'))) {
    csrf_verify();
    $pollId = (int)($_POST['poll_id'] ?? 0);
    try {
        $poll = get_poll_by_id($pollId);
        if (!$poll || $poll['poll_type'] !== 'questionnaire') throw new RuntimeException('Polling questionnaire tidak ditemukan.');

        if ($action === 'question_add') {
            $text = trim((string)($_POST['question_text'] ?? ''));
            $type = (string)($_POST['question_type'] ?? 'single_choice');
            $sort = (int)($_POST['sort_order'] ?? 0);
            $required = !empty($_POST['required']) ? 1 : 0;
            if ($text === '' || !in_array($type,['single_choice','multiple_choice','yes_no'],true)) throw new RuntimeException('Pertanyaan tidak valid.');
            $imageUrl = handle_question_image_upload($_FILES['question_image'] ?? null);
            $st=$pdo->prepare('INSERT INTO poll_questions (poll_id,question_text,image_url,question_type,sort_order,required) VALUES (?,?,?,?,?,?)');
            $st->execute([$pollId,$text,$imageUrl,$type,$sort,$required]);
            admin_audit('question.create','poll_question',(int)db_last_insert_id($pdo),'Pertanyaan ditambahkan ke polling '.$pollId);
        } elseif ($action === 'question_update') {
            $qid=(int)$_POST['question_id'];
            $text=trim((string)$_POST['question_text']??'');
            $type=(string)($_POST['question_type']??'single_choice');
            $sort=(int)($_POST['sort_order']??0);
            $required=!empty($_POST['required'])?1:0;
            $oldQuestion=get_poll_question($qid,$pollId);
            if (!$oldQuestion || $text==='' || !in_array($type,['single_choice','multiple_choice','yes_no'],true)) throw new RuntimeException('Pertanyaan tidak valid.');
            $imageUrl = handle_question_image_upload($_FILES['question_image'] ?? null);
            if ($imageUrl) {
                $pdo->prepare('UPDATE poll_questions SET question_text=?,image_url=?,question_type=?,sort_order=?,required=?,updated_at=NOW() WHERE id=? AND poll_id=?')->execute([$text,$imageUrl,$type,$sort,$required,$qid,$pollId]);
                remove_question_image($oldQuestion['image_url'] ?? null);
            } else {
                $pdo->prepare('UPDATE poll_questions SET question_text=?,question_type=?,sort_order=?,required=?,updated_at=NOW() WHERE id=? AND poll_id=?')->execute([$text,$type,$sort,$required,$qid,$pollId]);
            }
            admin_audit('question.update','poll_question',$qid,'Pertanyaan diperbarui');
        } elseif ($action === 'question_delete') {
            $qid=(int)$_POST['question_id'];
            if (!get_poll_question($qid,$pollId)) throw new RuntimeException('Pertanyaan tidak ditemukan.');
            $qToDelete=get_poll_question($qid,$pollId);
            $optStmt=$pdo->prepare("SELECT image_url FROM poll_question_options WHERE question_id=? AND image_url IS NOT NULL AND image_url<>''");
            $optStmt->execute([$qid]);
            $optFiles=$optStmt->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE poll_question_options SET next_question_id=NULL WHERE next_question_id=?')->execute([$qid]);
            $pdo->prepare('DELETE FROM poll_questions WHERE id=? AND poll_id=?')->execute([$qid,$pollId]);
            $pdo->commit();
            remove_question_image($qToDelete['image_url'] ?? null);
            foreach($optFiles as $url) remove_option_image($url);
            admin_audit('question.delete','poll_question',$qid,'Pertanyaan dihapus');
        } elseif ($action === 'option_add' || $action === 'option_update') {
            $qid=(int)$_POST['question_id'];
            $oid=(int)($_POST['option_id']??0);
            $text=trim((string)($_POST['option_text']??''));
            $sort=(int)($_POST['sort_order']??0);
            $ends=!empty($_POST['ends_poll'])?1:0;
            $next=(int)($_POST['next_question_id']??0);
            $targetPoll=(int)($_POST['target_poll_id']??0);
            $oldOption=null;
            if ($action === 'option_update') {
                $stOld=$pdo->prepare('SELECT * FROM poll_question_options WHERE id=? AND question_id=? LIMIT 1');
                $stOld->execute([$oid,$qid]); $oldOption=$stOld->fetch();
                if (!$oldOption) throw new RuntimeException('Opsi tidak ditemukan.');
            }
            if (!get_poll_question($qid,$pollId) || $text==='') throw new RuntimeException('Opsi tidak valid.');
            if ($ends && ($next || $targetPoll)) throw new RuntimeException('Opsi yang ditandai Selesai tidak boleh memiliki tujuan lain.');
            if ($ends) { $next=0; $targetPoll=0; }
            if ($next && $targetPoll) throw new RuntimeException('Pilih satu tujuan: pertanyaan atau polling foto.');
            if ($next && !get_poll_question($next,$pollId)) throw new RuntimeException('Tujuan pertanyaan tidak valid atau bukan bagian dari polling ini.');
            if ($next === $qid) throw new RuntimeException('Pertanyaan tidak boleh bercabang ke dirinya sendiri.');
            if ($targetPoll) {
                $tp=$pdo->prepare("SELECT id,title,poll_type FROM polls WHERE id=? AND id<>? AND poll_type IN ('single_choice','image_choice') LIMIT 1");
                $tp->execute([$targetPoll,$pollId]);
                if (!$tp->fetch()) throw new RuntimeException('Polling foto tujuan tidak valid.');
            }
            $imageUrl=handle_option_image_upload($_FILES['option_image']??null);
            if ($action === 'option_add') {
                $st=$pdo->prepare('INSERT INTO poll_question_options (question_id,option_text,sort_order,ends_poll,next_question_id,target_poll_id,image_url) VALUES (?,?,?,?,?,?,?)');
                $st->execute([$qid,$text,$sort,$ends,$next?:null,$targetPoll?:null,$imageUrl]);
                $oid=(int)db_last_insert_id($pdo);
                admin_audit('question.option.create','poll_question_option',$oid,'Opsi ditambahkan');
            } else {
                if ($imageUrl) {
                    $st=$pdo->prepare('UPDATE poll_question_options SET option_text=?,sort_order=?,ends_poll=?,next_question_id=?,target_poll_id=?,image_url=?,updated_at=NOW() WHERE id=? AND question_id=?');
                    $st->execute([$text,$sort,$ends,$next?:null,$targetPoll?:null,$imageUrl,$oid,$qid]);
                    remove_option_image($oldOption['image_url']??null);
                } else {
                    $st=$pdo->prepare('UPDATE poll_question_options SET option_text=?,sort_order=?,ends_poll=?,next_question_id=?,target_poll_id=?,updated_at=NOW() WHERE id=? AND question_id=?');
                    $st->execute([$text,$sort,$ends,$next?:null,$targetPoll?:null,$oid,$qid]);
                }
                admin_audit('question.option.update','poll_question_option',$oid,'Opsi diperbarui');
            }
        } elseif ($action === 'option_delete') {
            $oid=(int)$_POST['option_id'];
            $st=$pdo->prepare('SELECT o.id,o.image_url FROM poll_question_options o JOIN poll_questions q ON q.id=o.question_id WHERE o.id=? AND q.poll_id=?');
            $st->execute([$oid,$pollId]);
            $optionToDelete=$st->fetch();
            if (!$optionToDelete) throw new RuntimeException('Opsi tidak ditemukan.');
            $pdo->prepare('DELETE FROM poll_question_options WHERE id=?')->execute([$oid]);
            remove_option_image($optionToDelete['image_url']??null);
            admin_audit('question.option.delete','poll_question_option',$oid,'Opsi dihapus');
        }
        flash_set('success','Pengaturan pertanyaan berhasil disimpan.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($imageUrl) && $action === 'question_add') remove_question_image($imageUrl);
        if (!empty($imageUrl) && $action === 'question_update') remove_question_image($imageUrl);
        if (!empty($imageUrl) && str_starts_with($action, 'option_')) remove_option_image($imageUrl);
        error_log('Question settings failed: '.$ex->getMessage());
        flash_set('error',$ex instanceof RuntimeException ? $ex->getMessage() : 'Pengaturan pertanyaan gagal disimpan.');
    }
    redirect_edit_poll($pollId,'questions');
}

if ($action === 'generate_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id=(int)($_POST['id']??0);
    $stmt=$pdo->prepare('SELECT id,title FROM polls WHERE id=? LIMIT 1'); $stmt->execute([$id]); $poll=$stmt->fetch();
    if (!$poll) { flash_set('error','Polling tidak ditemukan.'); redirect('settings.php'); }
    if (!db_column_exists($pdo, 'polls', 'token_hash')) {
        flash_set('error','Kolom token_hash tidak ada pada tabel polls. Perbaiki schema database terlebih dahulu.');
        redirect('settings.php?action=edit&id='.$id);
    }

    $token = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidateToken = normalize_poll_token(generate_poll_token(12));
        $hash = hash('sha256', $candidateToken);
        $check = $pdo->prepare('SELECT id FROM polls WHERE token_hash=? AND id<>? LIMIT 1');
        $check->execute([$hash, $id]);
        if (!$check->fetchColumn()) {
            $update = $pdo->prepare('UPDATE polls SET token_hash=?,token_rotated_at=NOW() WHERE id=?');
            $update->execute([$hash, $id]);
            $verify = $pdo->prepare('SELECT token_hash FROM polls WHERE id=? LIMIT 1');
            $verify->execute([$id]);
            if (hash_equals((string)$verify->fetchColumn(), $hash)) {
                $token = $candidateToken;
                break;
            }
        }
    }
    if ($token === null) throw new RuntimeException('Token gagal disimpan dan diverifikasi. Periksa struktur tabel polls.');
    $generatedToken=$token;
    admin_audit('poll.token_rotate','poll',$id,'Token polling dirotasi');
    flash_set('success','Token polling berhasil dibuat/dirotasi dan diverifikasi.');
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    try {
        // Penghapusan polling memang destruktif: semua vote/jawaban, peserta
        // polling, kandidat, pertanyaan, dan pengaturannya ikut terhapus
        // melalui foreign key ON DELETE CASCADE. File upload dibersihkan setelah
        // transaksi berhasil agar rollback tidak meninggalkan data tanpa file.
        $pollStmt = $pdo->prepare('SELECT id, title FROM polls WHERE id=? LIMIT 1');
        $pollStmt->execute([$id]);
        $poll = $pollStmt->fetch();
        if (!$poll) throw new RuntimeException('Polling tidak ditemukan.');

        $files = [];
        $st = $pdo->prepare("SELECT image_url FROM candidates WHERE poll_id=? AND image_url IS NOT NULL AND image_url<>''");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $url) $files[] = ['type' => 'candidate', 'url' => $url];
        $st = $pdo->prepare("SELECT image_url FROM poll_questions WHERE poll_id=? AND image_url IS NOT NULL AND image_url<>''");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $url) $files[] = ['type' => 'question', 'url' => $url];
        $st = $pdo->prepare("SELECT o.image_url FROM poll_question_options o JOIN poll_questions q ON q.id=o.question_id WHERE q.poll_id=? AND o.image_url IS NOT NULL AND o.image_url<>''");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $url) $files[] = ['type' => 'option', 'url' => $url];

        $pdo->beginTransaction();
        // Hapus dependency secara eksplisit agar aksi tetap bekerja walaupun
        // database lama memiliki constraint yang berbeda dari schema terbaru.
        $pdo->prepare('DELETE FROM poll_answers WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM votes WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM poll_participations WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM poll_participants WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM poll_question_options WHERE question_id IN (SELECT id FROM poll_questions WHERE poll_id=?)')->execute([$id]);
        $pdo->prepare('DELETE FROM poll_questions WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM candidates WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM poll_settings WHERE poll_id=?')->execute([$id]);
        $del = $pdo->prepare('DELETE FROM polls WHERE id=?');
        $del->execute([$id]);
        if ($del->rowCount() !== 1) throw new RuntimeException('Polling gagal dihapus.');
        $pdo->commit();

        foreach ($files as $item) {
            $baseDir = $item['type'] === 'question' ? QUESTION_UPLOAD_DIR : ($item['type'] === 'option' ? OPTION_UPLOAD_DIR : UPLOAD_DIR);
            $base = realpath($baseDir);
            $relative = ltrim(str_replace(['\\','..'], ['/',''], (string)$item['url']), '/');
            $file = realpath(dirname(__DIR__) . '/' . $relative);
            if ($base && $file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) @unlink($file);
        }

        admin_audit('poll.delete','poll',$id,'Polling dihapus beserta seluruh data terkait: '.(string)$poll['title']);
        flash_set('success','Polling berhasil dihapus. Vote/jawaban peserta pada polling tersebut juga ikut dihapus.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Poll delete failed: '.$ex->getMessage());
        flash_set('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Polling gagal dihapus. Tidak ada data yang diubah.');
    }
    redirect('settings.php');
}

$editRow=null;
if ($action === 'edit') {
    $id=(int)($_GET['id']??0);
    $st=$pdo->prepare('SELECT * FROM polls WHERE id=?'); $st->execute([$id]); $editRow=$st->fetch();
    if($editRow){
        $st=$pdo->prepare('SELECT * FROM poll_settings WHERE poll_id=? LIMIT 1'); $st->execute([$id]); $editRow['_settings']=$st->fetch()?:[];
    }
}

$polls=$pdo->query('SELECT * FROM polls ORDER BY id DESC')->fetchAll();
$questionRows=[];
$questionTargets=[];
if ($editRow && $editRow['poll_type']==='questionnaire') {
    try {
        $questionRows=get_poll_questions((int)$editRow['id']);
        $questionTargets=settings_question_targets($pdo,(int)$editRow['id']);
    } catch(Throwable $e) {
        $error=$error ?: 'Pertanyaan belum dapat dimuat. Struktur questionnaire belum siap.';
    }
}

function fmt_dt(?string $v): string { return $v ? str_replace(' ','T',substr($v,0,16)) : ''; }
$active_menu='settings';
$page_title='Pengaturan';
include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Pengaturan Polling</h1><?php if($editRow): ?><a class="btn secondary" href="preview.php?poll_id=<?= (int)$editRow['id'] ?>" target="_blank">Preview Polling</a><?php endif; ?></div>
<?php if($msg=flash_get('success')):?><div class="alert success"><?=e($msg)?></div><?php endif; ?>
<?php if($msg=flash_get('error')):?><div class="alert error"><?=e($msg)?></div><?php endif; ?>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif; ?>

<?php if($generatedToken): ?>
<div class="card token-card"><h3 style="margin-top:0">Token Polling Baru</h3><p>Salin token ini. Token plaintext tidak disimpan.</p><input id="generatedToken" type="text" value="<?=e($generatedToken)?>" readonly onclick="this.select()" style="font-size:22px;font-weight:700;letter-spacing:2px;text-align:center"><button type="button" class="btn secondary" style="margin-top:12px" onclick="var e=document.getElementById('generatedToken');e.select();if(navigator.clipboard)navigator.clipboard.writeText(e.value)">Salin Token</button></div>
<?php endif; ?>

<?php
$typeLabels=['single_choice'=>'Pilih 1 foto','image_choice'=>'Pilih foto','questionnaire'=>'Kuesioner / Banyak Pertanyaan'];
$qTypeLabels=['single_choice'=>'Satu pilihan','multiple_choice'=>'Banyak pilihan','yes_no'=>'Ya / Tidak'];
$renderNextOptions=function(array $targets,int $selected=0): string {
    $h='<option value="0">Pertanyaan berikutnya (otomatis)</option>';
    foreach($targets as $t){ $h.='<option value="'.(int)$t['id'].'"'.($selected===(int)$t['id']?' selected':'').'>P'.(int)$t['sort_order'].' — '.e($t['question_text']).'</option>'; }
    return $h;
};
$renderPhotoOptions=function(array $photoTargets,int $selected=0): string {
    $h='<option value="0">Tidak</option>';
    foreach($photoTargets as $tp){ $h.='<option value="'.(int)$tp['id'].'"'.($selected===(int)$tp['id']?' selected':'').'>'.e($tp['title']).' ('.e($tp['poll_type']).')</option>'; }
    return $h;
};
?>
<section class="panel" id="form-polling">
  <div class="panel-head">
    <div class="panel-icon"></div>
    <div><h3><?= $editRow ? 'Edit Polling' : 'Buat Polling Baru' ?></h3><p class="muted"><?= $editRow ? 'Ubah informasi dan aturan polling ini.' : 'Isi informasi dasar, lalu atur aturan peserta.' ?></p></div>
  </div>
  <form method="post" action="settings.php?action=<?= $editRow?'edit':'add' ?>" class="form-stack">
    <?=csrf_field()?><?php if($editRow):?><input type="hidden" name="id" value="<?= (int)$editRow['id']?>"><?php endif; ?>
    <div class="field"><label>Judul Polling</label><input type="text" name="title" required maxlength="150" value="<?=e($editRow['title']??'')?>" placeholder="Contoh: Foto Terbaik Angkatan"></div>
    <div class="field"><label>Deskripsi</label><textarea name="description" rows="2" placeholder="Penjelasan singkat (opsional)"><?=e($editRow['description']??'')?></textarea></div>
    <div class="form-grid">
      <div class="field"><label>Jenis Polling</label>
        <select name="poll_type" id="poll_type" onchange="toggleQuestionnaire()">
        <?php foreach($allowedTypes as $type):?><option value="<?=e($type)?>" <?=($editRow['poll_type']??'single_choice')===$type?'selected':''?>><?= e($typeLabels[$type]??$type) ?></option><?php endforeach;?>
        </select></div>
      <div class="field"><label>Status</label>
        <select name="status"><?php foreach($allowedStatuses as $status):?><option value="<?=e($status)?>" <?=($editRow['status']??'draft')===$status?'selected':''?>><?=e(ucfirst($status))?></option><?php endforeach;?></select></div>
      <div class="field"><label>Mulai <span class="muted">(opsional)</span></label><input type="datetime-local" name="start_at" value="<?=e(fmt_dt($editRow['start_at']??null))?>"></div>
      <div class="field"><label>Selesai <span class="muted">(opsional)</span></label><input type="datetime-local" name="end_at" value="<?=e(fmt_dt($editRow['end_at']??null))?>"></div>
    </div>

    <div class="sub-panel">
      <div class="sub-title">Aturan Peserta</div>
      <label class="switch-row"><input type="checkbox" name="allow_change_vote" value="1" <?=!empty($editRow['_settings']['allow_change_vote'])?'checked':''?>><span class="switch"></span><span class="switch-text"><b>Izinkan mengubah vote</b><small>Peserta bisa memilih ulang setelah memilih.</small></span></label>
      <label class="switch-row"><input type="checkbox" name="show_results" value="1" <?=!empty($editRow['_settings']['show_results'])?'checked':''?>><span class="switch"></span><span class="switch-text"><b>Tampilkan hasil setelah selesai</b><small>Tombol “Lihat Hasil” muncul di halaman sukses.</small></span></label>
      <label class="switch-row"><input type="checkbox" name="randomize_candidates" value="1" <?=!empty($editRow['_settings']['randomize_candidates'])?'checked':''?>><span class="switch"></span><span class="switch-text"><b>Acak urutan foto</b><small>Khusus polling foto.</small></span></label>
      <div id="questionnaireSettings" class="field" style="display:none;margin-top:14px"><label>Jumlah maksimal pertanyaan</label><input type="number" min="0" name="max_questions" value="<?= (int)($editRow['_settings']['max_questions']??0)?>" style="max-width:160px"><p class="muted">0 = tanpa batas. Cabang tertentu tetap bisa mengakhiri polling lebih cepat.</p></div>
    </div>
    <p class="muted">Akses peserta: <strong>Token + Verifikasi Nama &amp; Kelas</strong>.</p>
    <div class="btn-row">
      <button class="btn block-auto" type="submit"><?= $editRow?'Simpan Perubahan':'Buat Polling' ?></button>
      <?php if($editRow):?><a class="btn secondary block-auto" href="settings.php">Batal</a><?php endif;?>
    </div>
  </form>
</section>

<?php if($editRow && $editRow['poll_type']==='questionnaire'): ?>
<section class="panel" id="questions">
  <div class="panel-head">
    <div class="panel-icon"></div>
    <div><h3>Pertanyaan &amp; Alur <span class="pill"><?= count($questionRows) ?> pertanyaan</span></h3>
    <p class="muted">Setiap jawaban bisa <b>lanjut otomatis</b>, <b>lompat ke pertanyaan tertentu</b>, <b>masuk polling foto</b>, atau <b>mengakhiri polling</b>.</p></div>
  </div>

  <details class="add-box" <?= $questionRows ? '' : 'open' ?>>
    <summary>Tambah pertanyaan baru</summary>
    <form method="post" enctype="multipart/form-data" action="settings.php?action=question_add" class="form-stack">
      <?=csrf_field()?><input type="hidden" name="poll_id" value="<?= (int)$editRow['id']?>"><input type="hidden" name="action" value="question_add">
      <div class="field"><label>Pertanyaan</label><input name="question_text" maxlength="500" required placeholder="Contoh: Apakah Anda ingin mengikuti kegiatan?"></div>
      <div class="form-grid">
        <div class="field"><label>Tipe</label><select name="question_type"><?php foreach($qTypeLabels as $k=>$v):?><option value="<?=$k?>"><?=$v?></option><?php endforeach;?></select></div>
        <div class="field"><label>Urutan</label><input type="number" name="sort_order" value="<?=count($questionRows)+1?>"></div>
        <div class="field"><label>Gambar soal <span class="muted">(opsional, maks. 3MB)</span></label><input type="file" name="question_image" accept="image/jpeg,image/png,image/webp"></div>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="required" value="1" checked> Wajib dijawab</label></div>
      </div>
      <div class="btn-row"><button class="btn block-auto">Tambah Pertanyaan</button></div>
    </form>
  </details>

  <?php if(!$questionRows):?><div class="alert info">Belum ada pertanyaan. Tambahkan pertanyaan pertama di atas.</div><?php endif;?>

  <?php foreach($questionRows as $qi=>$q): $opts=get_question_options((int)$q['id']); $targets=settings_question_targets($pdo,(int)$editRow['id'],(int)$q['id']); $photoTargets=get_questionnaire_photo_targets($pdo,(int)$editRow['id']); ?>
  <details class="q-card" <?= $qi===0 ? 'open' : '' ?>>
    <summary>
      <span class="q-num">P<?= (int)$q['sort_order'] ?></span>
      <span class="q-title"><?= e($q['question_text']) ?></span>
      <span class="q-meta"><span class="pill"><?= e($qTypeLabels[$q['question_type']]??$q['question_type']) ?></span><span class="pill <?= $q['required']?'pill-req':'' ?>"><?= $q['required']?'Wajib':'Opsional' ?></span><span class="pill"><?= count($opts) ?> opsi</span></span>
    </summary>
    <div class="q-body">
      <form method="post" enctype="multipart/form-data" action="settings.php?action=question_update" class="form-stack">
        <?=csrf_field()?><input type="hidden" name="action" value="question_update"><input type="hidden" name="poll_id" value="<?= (int)$editRow['id']?>"><input type="hidden" name="question_id" value="<?= (int)$q['id']?>">
        <div class="field"><label>Teks pertanyaan</label><input name="question_text" maxlength="500" required value="<?=e($q['question_text'])?>"></div>
        <div class="form-grid">
          <div class="field"><label>Tipe</label><select name="question_type"><?php foreach($qTypeLabels as $k=>$v):?><option value="<?=$k?>" <?=$q['question_type']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
          <div class="field"><label>Urutan</label><input type="number" name="sort_order" value="<?= (int)$q['sort_order']?>"></div>
          <div class="field"><label>Gambar soal baru <span class="muted">(opsional)</span></label><input type="file" name="question_image" accept="image/jpeg,image/png,image/webp"></div>
          <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="required" value="1" <?=$q['required']?'checked':''?>> Wajib dijawab</label></div>
        </div>
        <?php if(!empty($q['image_url'])):?><div class="thumb-row"><img src="<?=e($q['image_url'])?>" alt="Gambar soal" class="thumb-lg"><span class="muted">Upload gambar baru untuk mengganti.</span></div><?php endif;?>
        <div class="btn-row"><button class="btn small">Simpan Pertanyaan</button></div>
      </form>

      <div class="opts-head">Jawaban &amp; Cabang</div>
      <?php if(!$opts):?><div class="alert info">Belum ada jawaban. Tambahkan opsi di bawah.</div><?php endif;?>

      <?php foreach($opts as $o):?>
      <form method="post" enctype="multipart/form-data" action="settings.php?action=option_update" class="opt-card">
        <?=csrf_field()?>
        <input type="hidden" name="action" value="option_update"><input type="hidden" name="poll_id" value="<?= (int)$editRow['id']?>"><input type="hidden" name="question_id" value="<?= (int)$q['id']?>"><input type="hidden" name="option_id" value="<?= (int)$o['id']?>">
        <div class="opt-main">
          <div class="field grow"><label>Teks jawaban</label><input name="option_text" maxlength="255" required value="<?=e($o['option_text'])?>"></div>
          <div class="field tiny"><label>Urut</label><input type="number" name="sort_order" value="<?= (int)$o['sort_order']?>"></div>
        </div>
        <div class="opt-flow">
          <div class="field"><label>Setelah dipilih</label><select name="next_question_id" class="branch-target" data-option-id="<?= (int)$o['id']?>"><?= $renderNextOptions($targets,(int)$o['next_question_id']) ?></select></div>
          <div class="field"><label>Lanjut ke polling foto</label><select name="target_poll_id" class="photo-target"><?= $renderPhotoOptions($photoTargets,(int)($o['target_poll_id']??0)) ?></select></div>
        </div>
        <div class="opt-foot">
          <div class="opt-img">
            <?php if(!empty($o['image_url'])):?><img src="<?=e($o['image_url'])?>" alt="Foto opsi" class="thumb-sm"><?php endif;?>
            <div class="field"><label>Foto jawaban <span class="muted">(opsional)</span></label><input type="file" name="option_image" accept="image/jpeg,image/png,image/webp"></div>
          </div>
          <label class="check end-check"><input type="checkbox" name="ends_poll" value="1" <?=$o['ends_poll']?'checked':''?>> Akhiri polling</label>
          <div class="btn-row">
            <button class="btn small">Simpan</button>
            <button class="btn small danger" name="delete_option" value="1" formaction="settings.php?action=option_delete" onclick="return confirm('Hapus opsi ini?')">Hapus</button>
          </div>
        </div>
      </form>
      <?php endforeach;?>

      <details class="add-box add-opt">
        <summary>Tambah jawaban</summary>
        <form method="post" enctype="multipart/form-data" action="settings.php?action=option_add" class="form-stack">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="option_add"><input type="hidden" name="poll_id" value="<?= (int)$editRow['id']?>"><input type="hidden" name="question_id" value="<?= (int)$q['id']?>">
          <div class="opt-main">
            <div class="field grow"><label>Teks jawaban</label><input name="option_text" maxlength="255" required></div>
            <div class="field tiny"><label>Urut</label><input type="number" name="sort_order" value="<?= count($opts)+1 ?>"></div>
          </div>
          <div class="opt-flow">
            <div class="field"><label>Setelah dipilih</label><select name="next_question_id"><?= $renderNextOptions($targets,0) ?></select></div>
            <div class="field"><label>Lanjut ke polling foto</label><select name="target_poll_id"><?= $renderPhotoOptions($photoTargets,0) ?></select></div>
          </div>
          <div class="opt-foot">
            <div class="opt-img"><div class="field"><label>Foto jawaban <span class="muted">(opsional)</span></label><input type="file" name="option_image" accept="image/jpeg,image/png,image/webp"></div></div>
            <label class="check end-check"><input type="checkbox" name="ends_poll" value="1"> Akhiri polling</label>
            <div class="btn-row"><button class="btn small">Tambah Jawaban</button></div>
          </div>
        </form>
      </details>

      <form method="post" action="settings.php?action=question_delete" class="q-delete" onsubmit="return confirm('Hapus pertanyaan ini beserta semua opsinya?')"><?=csrf_field()?><input type="hidden" name="action" value="question_delete"><input type="hidden" name="poll_id" value="<?= (int)$editRow['id']?>"><input type="hidden" name="question_id" value="<?= (int)$q['id']?>"><button class="btn small danger">Hapus pertanyaan ini</button></form>
    </div>
  </details>
  <?php endforeach;?>
</section>
<?php endif; ?>

<section class="panel" id="daftar-polling">
  <div class="panel-head"><div class="panel-icon"></div><div><h3>Daftar Polling</h3><p class="muted">Kelola token, preview, dan hapus polling.</p></div></div>
  <div class="table-scroll"><table><thead><tr><th>Judul</th><th>Jenis</th><th>Status</th><th>Info</th><th>Token</th><th>Aksi</th></tr></thead><tbody>
  <?php foreach($polls as $p): $ps=get_poll_settings((int)$p['id']); $qc=0; if($p['poll_type']==='questionnaire'){try{$st=$pdo->prepare('SELECT COUNT(*) c FROM poll_questions WHERE poll_id=? AND status=\'active\'');$st->execute([(int)$p['id']]);$qc=(int)$st->fetch()['c'];}catch(Throwable $e){}} ?>
  <tr>
    <td><b><?=e($p['title'])?></b></td>
    <td><?= $p['poll_type']==='questionnaire'?'Kuesioner':($p['poll_type']==='image_choice'?'Foto':'Pilih 1')?></td>
    <td><span class="badge <?=e($p['status'])?>"><?=e(ucfirst($p['status']))?></span></td>
    <td class="muted"><?php if($p['poll_type']==='questionnaire'):?><?=$qc?> pertanyaan · maks <?=((int)($ps['max_questions']??0)?:'Tanpa batas')?><?php else:?><?=!empty($ps['randomize_candidates'])?'Acak · ':''?><?=!empty($ps['show_results'])?'Hasil · ':''?><?=!empty($ps['allow_change_vote'])?'Bisa ubah':''?><?php endif;?></td>
    <td><?=!empty($p['token_hash'])?'<span class="badge active">Tersedia</span>':'<span class="badge inactive">Belum</span>'?></td>
    <td><div class="actions">
      <a href="preview.php?poll_id=<?=(int)$p['id']?>" class="btn small secondary" target="_blank">Preview</a>
      <a href="settings.php?action=edit&id=<?=(int)$p['id']?>" class="btn small secondary">Atur</a>
      <form method="post" action="settings.php?action=generate_token" onsubmit="return confirm('Buat/rotasi token?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="btn small">Token</button></form>
      <form method="post" action="settings.php?action=delete" onsubmit="return confirm('Hapus polling ini?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="btn small danger">Hapus</button></form>
    </div></td>
  </tr>
  <?php endforeach;?>
  <?php if(!$polls):?><tr><td colspan="6" class="muted">Belum ada polling.</td></tr><?php endif;?></tbody></table></div>
</section>

<script>
function toggleQuestionnaire(){
 const isQ=document.getElementById('poll_type').value==='questionnaire';
 document.getElementById('questionnaireSettings').style.display=isQ?'block':'none';
}
toggleQuestionnaire();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
