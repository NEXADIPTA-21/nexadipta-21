<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$existing = get_verified_participant();
if ($existing) {
    redirect('vote.php');
}

$error = flash_get('error');
$info = flash_get('info');
$poll = null;
$tokenVerified = !empty($_SESSION['poll_token_verified']);

$getToken = trim((string)($_GET['token'] ?? ''));
if ($getToken !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $poll = get_poll_by_token($getToken);
    if ($poll) {
        session_regenerate_id(true);
        $_SESSION['poll_token_verified'] = true;
        $_SESSION['poll_id'] = (int)$poll['id'];
        $_SESSION['poll_token_at'] = time();
        $tokenVerified = true;
    } elseif (!$tokenVerified) {
        $error = 'Token polling tidak valid atau polling sedang tidak tersedia.';
    }
}

// Token dapat dimasukkan lewat form POST. Setelah valid, token disimpan di session
// agar peserta tidak perlu mengetiknya lagi saat masuk ke tahap verifikasi identitas.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'token') {
        $token = normalize_poll_token((string)($_POST['token'] ?? ''));

        if ($token === '') {
            $error = 'Token polling wajib diisi.';
        } else {
            $poll = get_poll_by_token($token);
            if (!$poll) {
                $error = 'Token polling tidak valid, atau polling belum aktif/sudah ditutup.';
            } else {
                session_regenerate_id(true);
                $_SESSION['poll_token_verified'] = true;
                $_SESSION['poll_id'] = (int)$poll['id'];
                $_SESSION['poll_token_at'] = time();
                $tokenVerified = true;
            }
        }
    }

    if ($action === 'verify_participant') {
        $pollId = (int)($_SESSION['poll_id'] ?? 0);
        $poll = get_poll_by_id($pollId);

        if (!$poll || !poll_is_open($poll)) {
            unset($_SESSION['poll_id'], $_SESSION['poll_token_verified'], $_SESSION['poll_token_at']);
            $error = 'Polling saat ini sudah ditutup atau belum dibuka.';
            $tokenVerified = false;
        } else {
            $rawName = (string)($_POST['name'] ?? '');
            $classId = (int)($_POST['class_id'] ?? 0);

            if (trim($rawName) === '' || $classId <= 0) {
                $error = 'Nama dan kelas wajib diisi.';
            } else {
                $normalizedName = normalize_text($rawName);
                $pdo = get_db();

                $classStmt = $pdo->prepare('SELECT id, name FROM classes WHERE id = ? AND status = \'active\' LIMIT 1');
                $classStmt->execute([$classId]);
                $classRow = $classStmt->fetch();

                if (!$classRow) {
                    $error = 'Data peserta tidak sesuai. Periksa kembali nama dan kelas yang dipilih.';
                } else {
                    // Identitas diverifikasi server-side berdasarkan normalized_name + class_id.
                    $stmt = $pdo->prepare(
                        'SELECT p.*, c.name AS class_name
                         FROM participants p
                         INNER JOIN classes c ON c.id = p.class_id
                         WHERE p.normalized_name = ?
                           AND p.class_id = ?
                         LIMIT 1'
                    );
                    $stmt->execute([$normalizedName, $classId]);
                    $participant = $stmt->fetch();

                    if (!$participant || $participant['status'] !== 'active' || $classRow['id'] != $participant['class_id']) {
                        $error = 'Nama dan kelas tidak ditemukan dalam daftar peserta.';
                    } elseif (!participant_allowed_for_poll((int)$poll['id'], (int)$participant['id'])) {
                        $error = 'Peserta ini tidak terdaftar sebagai peserta pada polling tersebut.';
                    } elseif (($poll['poll_type'] ?? '') === 'questionnaire' && poll_questionnaire_done((int)$poll['id'], (int)$participant['id'])) {
                        $error = 'Peserta ini sudah menyelesaikan polling tersebut.';
                    } elseif (($poll['poll_type'] ?? '') !== 'questionnaire' && participant_has_voted((int)$poll['id'], (int)$participant['id']) && empty(get_poll_settings((int)$poll['id'])['allow_change_vote'])) {
                        $error = 'Peserta ini sudah melakukan polling untuk polling tersebut.';
                    } else {
                        // Regenerasi session ID setelah identitas berhasil diverifikasi.
                        session_regenerate_id(true);
                        $_SESSION['poll_id'] = (int)$poll['id'];
                        $_SESSION['poll_token_verified'] = true;
                        $_SESSION['poll_token_at'] = time();
                        $_SESSION['participant'] = [
                            'id' => (int)$participant['id'],
                            'name' => $participant['name'],
                            'class_id' => (int)$participant['class_id'],
                            'class_name' => $participant['class_name'],
                            'poll_id' => (int)$poll['id'],
                            'verified_at' => date('c'),
                        ];
                        redirect('vote.php');
                    }
                }
            }
        }
    }
}

// Pulihkan poll dari session setelah token berhasil.
// Session token selalu terikat ke poll_id; jika session korup/stale, jangan
// pernah menganggap token masih valid.
if ($tokenVerified && (int)($_SESSION['poll_id'] ?? 0) <= 0) {
    unset($_SESSION['poll_id'], $_SESSION['poll_token_verified'], $_SESSION['poll_token_at']);
    $tokenVerified = false;
}

if (!$poll && $tokenVerified) {
    $poll = get_poll_by_id((int)($_SESSION['poll_id'] ?? 0));
    if (!$poll || !poll_is_open($poll)) {
        unset($_SESSION['poll_id'], $_SESSION['poll_token_verified'], $_SESSION['poll_token_at']);
        $poll = null;
        $tokenVerified = false;
        $info = 'Polling saat ini tidak tersedia.';
    }
}

$classes = $tokenVerified ? get_active_classes() : [];
$accessMode = $poll['access_mode'] ?? 'token_verification';
$showParticipantForm = $tokenVerified && $poll;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> - Verifikasi Peserta</title>
<link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/theme.js"></script>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <h1><?= e(APP_NAME) ?></h1>
    <p><?= $poll ? e($poll['title']) : 'Akses polling' ?></p>
  </div>

  <div class="steps">
    <div class="step-dot <?= $tokenVerified ? 'done' : 'active' ?>"></div>
    <div class="step-dot <?= $showParticipantForm ? 'active' : '' ?>"></div>
    <div class="step-dot"></div>
    <div class="step-dot"></div>
  </div>

  <?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
  <?php endif; ?>
  <?php if ($info): ?>
    <div class="alert info"><?= e($info) ?></div>
  <?php endif; ?>

  <?php if (!$tokenVerified): ?>
    <div class="card">
      <h2 style="margin-top:0;">Token Polling</h2>
      <p class="muted">Masukkan token yang diberikan panitia untuk membuka polling.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="token">
        <label for="token">Token</label>
        <input type="text" id="token" name="token" placeholder="Masukkan token polling" required maxlength="64" autocomplete="off" style="text-transform:none">
        <button type="submit" class="btn">Lanjut</button>
      </form>
    </div>
  <?php elseif (empty($classes)): ?>
    <div class="card">
      <div class="alert info">Belum ada kelas yang aktif untuk polling ini.</div>
    </div>
  <?php else: ?>
    <div class="card">
      <h2 style="margin-top:0;">Verifikasi Peserta</h2>
      <p class="muted">Nama dan kelas harus sesuai dengan data peserta yang sudah didaftarkan panitia.</p>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify_participant">
        <label for="name">Nama Lengkap</label>
        <input type="text" id="name" name="name" placeholder="Masukkan nama lengkap" required maxlength="150"
               value="<?= e($_POST['name'] ?? '') ?>">

        <label for="class_id">Kelas</label>
        <select id="class_id" name="class_id" required>
          <option value="">-- Pilih Kelas --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (isset($_POST['class_id']) && (int)$_POST['class_id'] === (int)$c['id']) ? 'selected' : '' ?>>
              <?= e($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn">Verifikasi Peserta</button>
      </form>
    </div>
  <?php endif; ?>

  <p class="center muted" style="margin-top:18px;">Butuh bantuan? Hubungi panitia polling.</p>
  
    <div class="footer-copyright">
        <p><a href="nexadipta-21/" class="btn secondary">Website Angkatan</a></p>
        <p>&copy; 2026 SpotPulse. All rights reserved. | Powered by Panitia Polling</p>
    </div>
  
</div>
<div class="loader-overlay" id="loaderOverlay" aria-live="polite" aria-hidden="true">
  <div class="loader-box">
    <!-- From Uiverse.io by mobinkakei -->
    <div id="wifi-loader">
        <svg class="circle-outer" viewBox="0 0 86 86">
            <circle class="back" cx="43" cy="43" r="40"></circle>
            <circle class="front" cx="43" cy="43" r="40"></circle>
            <circle class="new" cx="43" cy="43" r="40"></circle>
        </svg>
        <svg class="circle-middle" viewBox="0 0 60 60">
            <circle class="back" cx="30" cy="30" r="27"></circle>
            <circle class="front" cx="30" cy="30" r="27"></circle>
        </svg>
        <svg class="circle-inner" viewBox="0 0 34 34">
            <circle class="back" cx="17" cy="17" r="14"></circle>
            <circle class="front" cx="17" cy="17" r="14"></circle>
        </svg>
        <div class="text" data-text="Memeriksa" id="loaderText"></div>
    </div>
  </div>
</div>
<script>
(function () {
  var overlay = document.getElementById('loaderOverlay');
  var text = document.getElementById('loaderText');
  var labels = { token: 'Memeriksa token', verify_participant: 'Memverifikasi' };
  document.querySelectorAll('form[method="post"]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var a = form.querySelector('input[name="action"]');
      text.setAttribute('data-text', (a && labels[a.value]) || 'Memproses');
      overlay.classList.add('show');
      overlay.setAttribute('aria-hidden', 'false');
      // Nonaktifkan tombol setelah submit berjalan agar tidak terkirim dua kali
      setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });
  // Tombol Back browser (bfcache): sembunyikan loader & aktifkan tombol lagi
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      overlay.classList.remove('show');
      overlay.setAttribute('aria-hidden', 'true');
      document.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = false; });
    }
  });
})();
</script>
</body>
</html>
