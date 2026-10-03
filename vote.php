<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$participant = get_verified_participant();
$pollId = (int)($participant['poll_id'] ?? $_SESSION['poll_id'] ?? 0);

if (!$participant || $pollId <= 0 || (int)($participant['poll_id'] ?? 0) !== $pollId) {
    clear_participant_session();
    redirect('poll.php');
}

$pdo = get_db();
$poll = get_poll_by_id($pollId);

if ($poll && ($poll['poll_type'] ?? '') === 'questionnaire') { redirect('questionnaire.php'); }

if (!$poll || !poll_is_open($poll)) {
    clear_participant_session();
    flash_set('info', 'Polling saat ini sudah ditutup atau belum dibuka.');
    redirect('poll.php');
}

// Re-validasi identitas, kelas, eligibility poll, dan status vote di setiap request.
$pStmt = $pdo->prepare(
    'SELECT p.*, c.name AS class_name
     FROM participants p
     INNER JOIN classes c ON c.id = p.class_id
     WHERE p.id = ? LIMIT 1'
);
$pStmt->execute([$participant['id']]);
$dbParticipant = $pStmt->fetch();

if (!$dbParticipant || $dbParticipant['status'] !== 'active' || $dbParticipant['class_id'] <= 0 || !participant_allowed_for_poll($pollId, (int)$dbParticipant['id'])) {
    clear_participant_session();
    flash_set('error', 'Peserta tidak lagi memenuhi syarat untuk polling ini.');
    redirect('poll.php');
}

$settings = get_poll_settings($pollId);
$allowChangeVote = !empty($settings['allow_change_vote']);
$randomizeCandidates = !empty($settings['randomize_candidates']);
$alreadyVoted = participant_has_voted($pollId, (int)$dbParticipant['id']);
$error = null;

if ($alreadyVoted && !$allowChangeVote) {
    clear_participant_session();
    flash_set('info', 'Peserta ini sudah melakukan polling untuk polling tersebut.');
    redirect('poll.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $candidateId = (int)($_POST['candidate_id'] ?? 0);

    $cStmt = $pdo->prepare('SELECT id FROM candidates WHERE id = ? AND poll_id = ? AND status = \'active\' LIMIT 1');
    $cStmt->execute([$candidateId, $pollId]);

    if (!$cStmt->fetch()) {
        $error = 'Foto yang dipilih tidak valid.';
    } else {
        $_SESSION['pending_candidate_id'] = $candidateId;
        redirect('confirm.php');
    }
}

$candStmt = $pdo->prepare('SELECT * FROM candidates WHERE poll_id = ? AND status = \'active\' ORDER BY sort_order ASC, id ASC');
$candStmt->execute([$pollId]);
$candidates = $candStmt->fetchAll();

if ($randomizeCandidates && count($candidates) > 1) {
    if (empty($_SESSION['candidate_order_seed'][$pollId])) {
        $_SESSION['candidate_order_seed'][$pollId] = bin2hex(random_bytes(16));
    }
    $seed = $_SESSION['candidate_order_seed'][$pollId];
    usort($candidates, static function(array $a, array $b) use ($seed): int {
        return strcmp(hash('sha256', $seed . '|' . $a['id']), hash('sha256', $seed . '|' . $b['id']));
    });
}

$currentVoteCandidateId = 0;
if ($alreadyVoted) {
    $cv = $pdo->prepare('SELECT candidate_id FROM votes WHERE poll_id = ? AND participant_id = ? LIMIT 1');
    $cv->execute([$pollId, $dbParticipant['id']]);
    $currentVoteCandidateId = (int)$cv->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> - Pilih Foto</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/theme.js"></script>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><?= e($poll['title']) ?></h1>
    <p>Halo, <strong><?= e($dbParticipant['name']) ?></strong> &middot; <?= e($dbParticipant['class_name']) ?></p>
    <?php if ($alreadyVoted && $allowChangeVote): ?><p class="muted">Mode ubah vote aktif. Pilihan saat ini akan ditandai.</p><?php endif; ?>
  </div>

  <div class="steps">
    <div class="step-dot done"></div>
    <div class="step-dot active"></div>
    <div class="step-dot"></div>
    <div class="step-dot"></div>
  </div>

  <?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (empty($candidates)): ?>
    <div class="card"><div class="alert info">Belum ada foto yang tersedia untuk polling ini.</div></div>
  <?php else: ?>
    <div class="candidate-grid">
      <?php foreach ($candidates as $cand): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="candidate_id" value="<?= (int)$cand['id'] ?>">
          <button type="submit" class="candidate-card" <?= ($alreadyVoted && $currentVoteCandidateId === (int)$cand['id']) ? 'style="outline:3px solid #16a34a;"' : '' ?>>
            <?php if (!empty($cand['image_url'])): ?>
              <img src="<?= e($cand['image_url']) ?>" alt="<?= e($cand['name']) ?>" loading="lazy" class="zoomable-image">
            <?php else: ?>
              <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='400'%3E%3Crect width='100%25' height='100%25' fill='%23e2e8f0'/%3E%3C/svg%3E" alt="">
            <?php endif; ?>
            <div class="cname"><?= e($cand['name']) ?></div>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<script src="assets/js/image-viewer.js"></script></body>
</html>
