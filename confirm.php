<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$participant = get_verified_participant();
$pollId = (int)($participant['poll_id'] ?? $_SESSION['poll_id'] ?? 0);
$candidateId = (int)($_SESSION['pending_candidate_id'] ?? 0);

if (!$participant || $pollId <= 0 || $candidateId <= 0) {
    clear_participant_session();
    redirect('poll.php');
}

$pdo = get_db();
$poll = get_poll_by_id($pollId);

if (!$poll || !poll_is_open($poll)) {
    clear_participant_session();
    flash_set('info', 'Polling saat ini sudah ditutup atau belum dibuka.');
    redirect('poll.php');
}

$candStmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ? AND poll_id = ? AND status = \'active\' LIMIT 1');
$candStmt->execute([$candidateId, $pollId]);
$candidate = $candStmt->fetch();

if (!$candidate) {
    unset($_SESSION['pending_candidate_id']);
    redirect('vote.php');
}

$settings = get_poll_settings($pollId);
$allowChangeVote = !empty($settings['allow_change_vote']);
$existingVoteStmt = $pdo->prepare('SELECT id, candidate_id FROM votes WHERE poll_id = ? AND participant_id = ? LIMIT 1');
$existingVoteStmt->execute([$pollId, $participant['id']]);
$existingVote = $existingVoteStmt->fetch();

if ($existingVote && !$allowChangeVote) {
    clear_participant_session();
    flash_set('info', 'Peserta ini sudah melakukan polling.');
    redirect('poll.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ================= VALIDASI ULANG PENUH DI BACKEND =================
    $pStmt = $pdo->prepare(
        'SELECT p.*, c.name AS class_name
         FROM participants p
         INNER JOIN classes c ON c.id = p.class_id
         WHERE p.id = ? LIMIT 1'
    );
    $pStmt->execute([$participant['id']]);
    $dbParticipant = $pStmt->fetch();

    $poll = get_poll_by_id($pollId);

    $cStmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ? AND poll_id = ? AND status = \'active\' LIMIT 1');
    $cStmt->execute([$candidateId, $pollId]);
    $dbCandidate = $cStmt->fetch();

    if (!$dbParticipant || $dbParticipant['status'] !== 'active') {
        $error = 'Nama dan kelas tidak ditemukan dalam daftar peserta.';
        clear_participant_session();
    } elseif (!$poll || !poll_is_open($poll)) {
        $error = 'Polling saat ini sudah ditutup.';
    } elseif (!participant_allowed_for_poll($pollId, (int)$dbParticipant['id'])) {
        $error = 'Peserta ini tidak terdaftar sebagai peserta pada polling tersebut.';
        clear_participant_session();
    } elseif (participant_has_voted($pollId, (int)$dbParticipant['id']) && !$allowChangeVote) {
        $error = 'Peserta ini sudah melakukan polling.';
        clear_participant_session();
    } elseif (!$dbCandidate) {
        $error = 'Foto yang dipilih tidak valid.';
    } else {
        try {
            $pdo->beginTransaction();

            if ($existingVote) {
                if (!$allowChangeVote) {
                    throw new RuntimeException('Vote sudah tercatat.');
                }
                $updateVote = $pdo->prepare('UPDATE votes SET candidate_id = ?, ip_hash = ? WHERE id = ? AND poll_id = ? AND participant_id = ?');
                $updateVote->execute([$dbCandidate['id'], hash_ip(client_ip()), $existingVote['id'], $pollId, $dbParticipant['id']]);
                $auditAction = 'vote.change';
            } else {
                // UNIQUE(poll_id, participant_id) adalah lapisan pengaman terakhir terhadap double vote.
                $insert = $pdo->prepare(
                    'INSERT INTO votes (poll_id, participant_id, candidate_id, ip_hash) VALUES (?, ?, ?, ?)'
                );
                $insert->execute([
                    $pollId,
                    $dbParticipant['id'],
                    $dbCandidate['id'],
                    hash_ip(client_ip()),
                ]);
                $auditAction = 'vote.create';
            }

            // has_voted dipertahankan hanya sebagai field legacy/kompatibilitas.
            $update = $pdo->prepare('UPDATE participants SET has_voted = 1 WHERE id = ?');
            $update->execute([$dbParticipant['id']]);

            $pdo->commit();
            admin_audit($auditAction, 'vote', (int)($existingVote['id'] ?? db_last_insert_id($pdo)), 'Vote tersimpan untuk polling '.$pollId);

            unset($_SESSION['pending_candidate_id']);
            $_SESSION['vote_success'] = true;
            $_SESSION['vote_success_poll_title'] = $poll['title'];
            $_SESSION['vote_success_poll_id'] = $pollId;
            $_SESSION['vote_success_show_results'] = !empty($settings['show_results']);
            $_SESSION['vote_success_changed'] = ($auditAction === 'vote.change');

            if (!$allowChangeVote) {
                clear_participant_session();
            } else {
                $_SESSION['participant']['poll_id'] = $pollId;
            }
            redirect('success.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Untuk client, jangan bocorkan detail SQL. Unique constraint berarti vote sudah tercatat.
            $error = 'Peserta ini sudah melakukan polling atau vote gagal disimpan.';
            clear_participant_session();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> - Konfirmasi</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/theme.js"></script>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1>Konfirmasi Pilihan</h1>
    <p><?= e($poll['title'] ?? APP_NAME) ?></p>
  </div>

  <div class="steps">
    <div class="step-dot done"></div>
    <div class="step-dot done"></div>
    <div class="step-dot active"></div>
    <div class="step-dot"></div>
  </div>

  <?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
    <div class="card"><a href="poll.php" class="btn secondary">Kembali ke Halaman Awal</a></div>
  <?php else: ?>
    <div class="card center">
      <?php if (!empty($candidate['image_url'])): ?>
        <img src="<?= e($candidate['image_url']) ?>" alt="<?= e($candidate['name']) ?>" class="zoomable-image" style="width:100%;max-width:260px;border-radius:12px;aspect-ratio:3/4;object-fit:cover;">
      <?php endif; ?>
      <h2 style="margin:14px 0 4px;"><?= e($candidate['name']) ?></h2>
      <p class="muted"><?= $allowChangeVote ? 'Jika sebelumnya sudah memilih, pilihan lama akan diganti dengan pilihan ini.' : 'Vote ini tidak dapat diubah setelah dikonfirmasi.' ?></p>

      <form method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn">Konfirmasi Vote</button>
      </form>
      <a href="vote.php" class="btn secondary">Pilih Foto Lain</a>
    </div>
  <?php endif; ?>
</div>
<script src="assets/js/image-viewer.js"></script></body>
</html>
