<?php
require_once __DIR__ . '/includes/functions.php';

if (empty($_SESSION['vote_success'])) {
    redirect('poll.php');
}
$pollId = (int)($_SESSION['vote_success_poll_id'] ?? 0);
$pollTitle = (string)($_SESSION['vote_success_poll_title'] ?? APP_NAME);
$showResults = !empty($_SESSION['vote_success_show_results']);
$changed = !empty($_SESSION['vote_success_changed']);
$isQuestionnaire = !empty($_SESSION['vote_success_questionnaire']);
$terminated = !empty($_SESSION['vote_success_terminated']);
$settings = $pollId > 0 ? get_poll_settings($pollId) : [];
$allowChange = !empty($settings['allow_change_vote']);
unset($_SESSION['vote_success'], $_SESSION['vote_success_poll_id'], $_SESSION['vote_success_poll_title'], $_SESSION['vote_success_show_results'], $_SESSION['vote_success_changed'], $_SESSION['vote_success_questionnaire'], $_SESSION['vote_success_terminated']);
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(APP_NAME) ?> - Berhasil</title><link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/theme.js"></script></head>
<body><div class="wrap">
<div class="steps"><div class="step-dot done"></div><div class="step-dot done"></div><div class="step-dot done"></div><div class="step-dot done"></div></div>
<div class="card center success-card">
<div class="success-icon" aria-hidden="true">✓</div>
<h1 style="margin-top:0;"><?= $isQuestionnaire ? ($terminated ? 'Polling Selesai' : 'Jawaban Berhasil Disimpan') : 'Vote '.($changed ? 'Berhasil Diubah' : 'Berhasil') ?></h1>
<p class="muted"><?= $terminated ? 'Anda memilih opsi yang mengakhiri polling ini.' : 'Terima kasih sudah berpartisipasi pada <strong>'.e($pollTitle).'</strong>.' ?></p>
<div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
<?php if (!$isQuestionnaire && $allowChange): ?><a href="vote.php" class="btn secondary">Ubah Vote</a><?php endif; ?>
<?php if ($showResults && $pollId > 0): ?><a href="results_public.php?poll_id=<?= $pollId ?>" class="btn">Lihat Hasil</a><?php endif; ?>
<a href="poll.php" class="btn secondary">Kembali ke Halaman Awal</a>
</div>
</div></div></body></html>
