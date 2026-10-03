<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pdo = get_db();
$polls = $pdo->query('SELECT id, title, status, poll_type, start_at, end_at FROM polls ORDER BY id DESC')->fetchAll();
$currentPollId = (int)($_GET['poll_id'] ?? ($polls[0]['id'] ?? 0));
$currentPoll = null;
$eligible = $voted = $notVoted = $activeCandidates = 0;
$percent = 0;
$classStats = [];
$pollSettings = [];
$isQuestionnaire = false;
$qStats = ['started'=>0,'completed'=>0,'terminated'=>0,'in_progress'=>0];

if ($currentPollId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM polls WHERE id = ? LIMIT 1');
    $stmt->execute([$currentPollId]);
    $currentPoll = $stmt->fetch();

    if ($currentPoll) {
        $isQuestionnaire = (($currentPoll['poll_type'] ?? '') === 'questionnaire');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM poll_participants pp INNER JOIN participants p ON p.id=pp.participant_id INNER JOIN classes c ON c.id=p.class_id WHERE pp.poll_id=? AND pp.status=\'active\' AND p.status=\'active\' AND c.status=\'active\'');
        $stmt->execute([$currentPollId]);
        $eligible = (int)$stmt->fetchColumn();

        if ($isQuestionnaire) {
            $stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM poll_participations WHERE poll_id=? GROUP BY status');
            $stmt->execute([$currentPollId]);
            foreach ($stmt->fetchAll() as $row) {
                if (isset($qStats[$row['status']])) $qStats[$row['status']] = (int)$row['c'];
            }
            $qStarted = $qStats['completed'] + $qStats['terminated'] + $qStats['in_progress'];
            $voted = $qStats['completed'] + $qStats['terminated'];
            $notVoted = max(0, $eligible - $voted);
            $percent = $eligible > 0 ? round(($voted / $eligible) * 100, 1) : 0;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM poll_questions WHERE poll_id=?');
            $stmt->execute([$currentPollId]);
            $activeCandidates = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare('SELECT c.name, COUNT(pp.id) AS eligible_count, COUNT(DISTINCT CASE WHEN qp.status IN (\'completed\',\'terminated\') THEN qp.participant_id END) AS voted_count FROM classes c LEFT JOIN participants p ON p.class_id=c.id AND p.status=\'active\' LEFT JOIN poll_participants pp ON pp.participant_id=p.id AND pp.poll_id=? AND pp.status=\'active\' LEFT JOIN poll_participations qp ON qp.participant_id=p.id AND qp.poll_id=? WHERE c.status=\'active\' GROUP BY c.id,c.name ORDER BY c.name');
            $stmt->execute([$currentPollId,$currentPollId]);
            $classStats = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(DISTINCT participant_id) FROM votes WHERE poll_id=?');
            $stmt->execute([$currentPollId]);
            $voted = (int)$stmt->fetchColumn();
            $notVoted = max(0, $eligible - $voted);
            $percent = $eligible > 0 ? round(($voted / $eligible) * 100, 1) : 0;

            $pollSettings = get_poll_settings($currentPollId);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM candidates WHERE poll_id=? AND status=\'active\'');
            $stmt->execute([$currentPollId]);
            $activeCandidates = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare('SELECT c.name, COUNT(pp.id) AS eligible_count, COUNT(DISTINCT CASE WHEN pp.id IS NOT NULL THEN v.participant_id END) AS voted_count FROM classes c LEFT JOIN participants p ON p.class_id=c.id AND p.status=\'active\' LEFT JOIN poll_participants pp ON pp.participant_id=p.id AND pp.poll_id=? AND pp.status=\'active\' LEFT JOIN votes v ON v.participant_id=p.id AND v.poll_id=? WHERE c.status=\'active\' GROUP BY c.id,c.name ORDER BY c.name');
            $stmt->execute([$currentPollId,$currentPollId]);
            $classStats = $stmt->fetchAll();
        }
    }
}

$allParticipants = (int)$pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
$activeParticipants = (int)$pdo->query('SELECT COUNT(*) FROM participants WHERE status=\'active\'')->fetchColumn();

$active_menu = 'dashboard';
$page_title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="admin-topbar"><h1>Dashboard</h1></div>

<?php if (empty($polls)): ?>
  <div class="alert info">Belum ada polling. Buat polling terlebih dahulu di menu Pengaturan.</div>
<?php else: ?>
  <form method="get" class="filter-bar">
    <label style="margin:0">Polling</label>
    <select name="poll_id" onchange="this.form.submit()">
      <?php foreach ($polls as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $currentPollId === (int)$p['id'] ? 'selected' : '' ?>>
          <?= e($p['title']) ?> — <?= e(ucfirst($p['status'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($currentPoll): ?>
    <div class="alert <?= $currentPoll['status'] === 'active' ? 'success' : 'info' ?>">
      Polling: <strong><?= e($currentPoll['title']) ?></strong> · Status: <strong><?= e(ucfirst($currentPoll['status'])) ?></strong>
    </div>

    <?php if ($isQuestionnaire): ?>
      <div class="stat-grid">
        <div class="stat-card"><div class="num"><?= $eligible ?></div><div class="lbl">Peserta Eligible</div></div>
        <div class="stat-card"><div class="num"><?= $qStats['completed'] + $qStats['terminated'] ?></div><div class="lbl">Sudah Mengikuti</div></div>
        <div class="stat-card"><div class="num"><?= $qStats['in_progress'] ?></div><div class="lbl">Sedang Berjalan</div></div>
        <div class="stat-card"><div class="num"><?= $notVoted ?></div><div class="lbl">Belum Mengikuti</div></div>
      </div>
      <div class="card">
        <strong>Progres Questionnaire: <?= $voted ?> / <?= $eligible ?> (<?= $percent ?>%)</strong>
        <div class="progress-bar"><div style="width:<?= $percent ?>%"></div></div>
        <p class="muted">Mulai: <?= $qStats['completed'] + $qStats['terminated'] + $qStats['in_progress'] ?> · Selesai: <?= $qStats['completed'] ?> · Berhenti: <?= $qStats['terminated'] ?> · Sedang berjalan: <?= $qStats['in_progress'] ?> · <?= $activeCandidates ?> pertanyaan</p>
      </div>
    <?php else: ?>
      <div class="stat-grid">
        <div class="stat-card"><div class="num"><?= $eligible ?></div><div class="lbl">Peserta Eligible</div></div>
        <div class="stat-card"><div class="num"><?= $voted ?></div><div class="lbl">Sudah Vote</div></div>
        <div class="stat-card"><div class="num"><?= $notVoted ?></div><div class="lbl">Belum Vote</div></div>
        <div class="stat-card"><div class="num"><?= $activeCandidates ?></div><div class="lbl">Foto Aktif</div></div>
      </div>
      <div class="card">
        <strong>Partisipasi: <?= $voted ?> / <?= $eligible ?> (<?= $percent ?>%)</strong>
        <div class="progress-bar"><div style="width:<?= $percent ?>%"></div></div>
        <p class="muted">Opsi: <?= !empty($pollSettings['randomize_candidates']) ? 'Foto diacak' : 'Urutan normal' ?> · <?= !empty($pollSettings['show_results']) ? 'Hasil publik setelah vote' : 'Hasil publik ditutup' ?> · <?= !empty($pollSettings['allow_change_vote']) ? 'Peserta boleh mengubah vote' : 'Vote tidak dapat diubah' ?></p>
      </div>
    <?php endif; ?>

    <div class="card"><h3 style="margin-top:0">Partisipasi per Kelas</h3>
      <table><thead><tr><th>Kelas</th><th>Eligible</th><th><?= $isQuestionnaire ? 'Sudah Mengikuti' : 'Sudah Vote' ?></th><th>Partisipasi</th></tr></thead><tbody>
      <?php foreach($classStats as $cs): $cp=(int)$cs['eligible_count']>0?round(((int)$cs['voted_count']/(int)$cs['eligible_count'])*100,1):0; ?>
        <tr><td><?=e($cs['name'])?></td><td><?= (int)$cs['eligible_count']?></td><td><?= (int)$cs['voted_count']?></td><td><?= $cp ?>%</td></tr>
      <?php endforeach; ?>
      <?php if(!$classStats): ?><tr><td colspan="4" class="muted">Belum ada data kelas untuk polling ini.</td></tr><?php endif; ?>
      </tbody></table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <strong>Total peserta di sistem:</strong> <?= $allParticipants ?> ·
  <strong>Peserta aktif:</strong> <?= $activeParticipants ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
