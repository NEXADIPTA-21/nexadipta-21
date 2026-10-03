<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
}

$error = null;
$now = time();
if (!isset($_SESSION['admin_login_attempts'], $_SESSION['admin_login_window'])) { $_SESSION['admin_login_attempts']=0; $_SESSION['admin_login_window']=$now; }
if ($now - (int)$_SESSION['admin_login_window'] > 900) { $_SESSION['admin_login_attempts']=0; $_SESSION['admin_login_window']=$now; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ((int)$_SESSION['admin_login_attempts'] >= 5) { $error='Terlalu banyak percobaan login. Tunggu sekitar 15 menit lalu coba lagi.'; } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_login_attempts'] = 0;
        admin_audit('admin.login', 'admin', (int) $admin['id'], 'Login admin berhasil');
        redirect('dashboard.php');
    } else {
        $_SESSION['admin_login_attempts']++;
        admin_audit('admin.login_failed', 'admin', null, 'Percobaan login gagal untuk username: ' . $username);
        $error = 'Username atau password salah.';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Admin - <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="../assets/js/theme.js"></script>
</head>
<body>
<div class="login-shell">

  <div class="login-visual">
    <div>
      <svg viewBox="0 0 400 320" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="200" cy="290" rx="130" ry="16" fill="rgba(0,0,0,0.12)"/>
        <rect x="90" y="60" width="220" height="170" rx="18" fill="#ffffff" opacity="0.97"/>
        <rect x="90" y="60" width="220" height="34" rx="18" fill="#0b8180"/>
        <circle cx="110" cy="77" r="5" fill="#fda4af"/>
        <circle cx="126" cy="77" r="5" fill="#fde68a"/>
        <circle cx="142" cy="77" r="5" fill="#86efac"/>
        <rect x="112" y="112" width="110" height="10" rx="5" fill="#cffafe"/>
        <rect x="112" y="132" width="150" height="10" rx="5" fill="#e5e7eb"/>
        <rect x="112" y="152" width="150" height="10" rx="5" fill="#e5e7eb"/>
        <rect x="112" y="180" width="176" height="26" rx="8" fill="#0ea5a4"/>
        <rect x="130" y="189" width="60" height="8" rx="4" fill="#ffffff"/>
        <circle cx="330" cy="80" r="26" fill="#f97316" opacity="0.9"/>
        <path d="M318 80 l8 8 l16 -16" stroke="#fff" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="62" cy="170" r="20" fill="#7c3aed" opacity="0.85"/>
        <rect x="52" y="162" width="20" height="16" rx="3" fill="#fff"/>
        <path d="M52 162 a10 8 0 0 1 20 0" stroke="#fff" stroke-width="3" fill="none"/>
        <circle cx="180" cy="255" r="14" fill="#fde68a"/>
        <circle cx="230" cy="262" r="9" fill="#a7f3d0"/>
      </svg>
      <div class="login-tagline">
        <h2>Kelola Polling dengan Mudah</h2>
        <p><?= e(APP_NAME) ?> membantu Anda mengelola peserta, kandidat, dan hasil polling secara real-time dan aman.</p>
      </div>
    </div>
  </div>

  <div class="login-form-side">
    <div class="login-box">
      <div class="brand">
        <div class="brand-icon">SP</div>
        <div class="brand-name"><?= e(APP_NAME) ?></div>
      </div>
      <h1>Selamat Datang</h1>
      <p class="sub">Masuk ke panel admin untuk mengelola polling Anda.</p>

      <div class="card">
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <label>Username</label>
          <input type="text" name="username" required autofocus placeholder="Masukkan username">
          <label>Password</label>
          <input type="password" name="password" required placeholder="Masukkan password">
          <button type="submit" class="btn">Masuk ke Dashboard</button>
        </form>
      </div>
      <a href="../index.php" class="login-back">← Kembali ke halaman polling</a>
    </div>
  </div>

</div>
</body>
</html>
