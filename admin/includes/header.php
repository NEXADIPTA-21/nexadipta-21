<?php
// Variabel $active_menu dan $page_title harus di-set sebelum include file ini.
$active_menu = $active_menu ?? '';
$page_title = $page_title ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> - Admin <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="../assets/js/theme.js"></script>
<script src="../assets/js/ui.js" defer></script>
</head>
<body>
<div class="admin-shell">
  <div class="admin-sidebar">
    <h2><?= e(APP_NAME) ?></h2>
    <a href="dashboard.php" class="<?= $active_menu === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="voters.php" class="<?= $active_menu === 'voters' ? 'active' : '' ?>">Data Peserta</a>
    <a href="classes.php" class="<?= $active_menu === 'classes' ? 'active' : '' ?>">Kelola Kelas</a>
    <a href="candidates.php" class="<?= $active_menu === 'candidates' ? 'active' : '' ?>">Kelola Foto</a>
    <a href="results.php" class="<?= $active_menu === 'results' ? 'active' : '' ?>">Hasil Polling</a>
    <a href="settings.php" class="<?= $active_menu === 'settings' ? 'active' : '' ?>">Pengaturan</a>
    <a href="website.php" class="<?= $active_menu === 'website' ? 'active' : '' ?>">Website Angkatan</a>
    <a href="audit_logs.php" class="<?= $active_menu === 'audit' ? 'active' : '' ?>">Audit Log</a>
    <a href="logout.php">Logout</a>
  </div>
  <div class="admin-main">
