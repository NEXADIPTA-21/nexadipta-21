<?php
require_once __DIR__ . '/includes/functions.php';
$pollId = (int)($_GET['poll_id'] ?? 0);
$poll = get_poll_by_id($pollId);
if (!$poll) redirect('poll.php');
$settings = get_poll_settings($pollId);
if (empty($settings['show_results'])) {
    http_response_code(403);
    exit('Hasil polling belum dibuka untuk publik.');
}
$isQ = ($poll['poll_type'] ?? '') === 'questionnaire';
$rows = $isQ ? [] : get_public_poll_result_rows($pollId);
$qRows = $isQ ? get_questionnaire_result_rows($pollId) : [];
$total = 0;
foreach ($rows as $r) $total += (int)$r['vote_count'];
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Hasil - <?= e($poll['title']) ?></title><link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/theme.js"></script></head><body><div class="wrap wide"><div class="topbar"><h1>Hasil Polling</h1><p><?= e($poll['title']) ?></p></div><?php if ($isQ): ?>
<?php if (!$qRows): ?><div class="card"><p class="muted">Belum ada hasil.</p></div><?php endif; ?>
<?php foreach ($qRows as $i => $q): $qt = 0; foreach ($q['options'] as $o) $qt += (int)$o['vote_count']; ?>
<div class="card"><h3 style="margin-top:0"><?= $i+1 ?>. <?= e($q['question']) ?></h3>
<?php foreach ($q['options'] as $o): $pct = $qt>0 ? round(((int)$o['vote_count']/$qt)*100,1) : 0; ?>
<div class="chart-row"><div class="chart-label"><?= e($o['option_text']) ?></div><div class="chart-track"><div class="chart-fill" style="width:<?= max($pct, $o['vote_count']>0 ? 4 : 0) ?>%"><?= $pct ?>%</div></div><div class="chart-count"><?= (int)$o['vote_count'] ?></div></div>
<?php endforeach; ?></div>
<?php endforeach; ?>
<?php else: ?>
<div class="card"><p>Total vote: <strong><?= $total ?></strong></p><?php if (!$rows): ?><p class="muted">Belum ada hasil.</p><?php else: foreach($rows as $r): $pct=$total>0?round(((int)$r['vote_count']/$total)*100,1):0; ?><div class="chart-row"><div class="chart-label"><?= e($r['name']) ?></div><div class="chart-track"><div class="chart-fill" style="width:<?= max($pct, $r['vote_count']>0 ? 4 : 0) ?>%"><?= $pct ?>%</div></div><div class="chart-count"><?= (int)$r['vote_count'] ?></div></div><?php endforeach; endif; ?></div>
<?php endif; ?>
<div class="card center"><a href="poll.php" class="btn secondary">Kembali</a></div></div></body></html>
