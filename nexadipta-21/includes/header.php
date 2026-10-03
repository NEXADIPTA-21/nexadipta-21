<?php
require_once __DIR__ . '/content.php';
$pageTitle = $pageTitle ?? 'NEXADIPTA 21';
$activePage = $activePage ?? 'home';
$pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : get_db();
$siteLogo = function_exists('nx21_setting') ? nx21_setting($pdo, 'logo_image', '') : '';

// Base path: works when folder is /nexadipta-21 (subdirectory) or document root.
// Prefer detecting from the current script location so assets/links stay correct.
if (!isset($base) || $base === null) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // SCRIPT_NAME like /nexadipta-21/index.php → base = /nexadipta-21
    // If somehow document root is this folder, SCRIPT_NAME = /index.php → base = ''
    $base = rtrim($scriptDir, '/');
    if ($base === '/' || $base === '.') {
        $base = '';
    }
}

if (!function_exists('nx21_url')) {
    function nx21_url(string $page): string {
        global $base;
        $page = ltrim($page, '/');
        if ($page === '' || $page === 'index.php') {
            return ($base === '' ? '/' : $base . '/');
        }
        return ($base === '' ? '/' : $base . '/') . $page;
    }
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7c6af0">
<meta name="description" content="Website angkatan NEXADIPTA 21.">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<script src="<?= htmlspecialchars(($base === '' ? '' : $base) . '/theme.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= htmlspecialchars(($base === '' ? '' : $base) . '/style.css', ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)@filemtime(__DIR__."/../style.css") ?>">
</head>
<body>
<header class="site-header">
  <div class="container nav-wrap">
    <a class="brand" href="<?= htmlspecialchars(nx21_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">
      <?php if ($siteLogo): ?>
        <img class="brand-logo" src="<?= htmlspecialchars(nx21_media_url($siteLogo), ENT_QUOTES, 'UTF-8') ?>" alt="Logo NEXADIPTA 21">
      <?php else: ?>
        <span class="brand-mark">N21</span>
      <?php endif; ?>
      <span><strong><?= htmlspecialchars(nx21_setting($pdo, 'site_name', 'NEXADIPTA 21'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(nx21_setting($pdo, 'tagline', 'The Story of Our Generation'), ENT_QUOTES, 'UTF-8') ?></small></span>
    </a>
    <div class="header-tools">
      <button class="menu-toggle" type="button" aria-label="Buka menu" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
    </div>
    <nav class="main-nav" aria-label="Navigasi utama">
      <?php $items = ['home'=>'Beranda','about'=>'Tentang','timeline'=>'Timeline','gallery'=>'Galeri','classes'=>'Kelas','polling'=>'Polling','results'=>'Hasil Polling','contact'=>'Kontak'];
      foreach ($items as $key=>$label): $file = ['home'=>'index.php','about'=>'tentang.php','timeline'=>'timeline.php','gallery'=>'galeri.php','classes'=>'kelas.php','polling'=>'polling.php','results'=>'hasil.php','contact'=>'kontak.php'][$key]; ?>
      <a class="<?= $activePage === $key ? 'active' : '' ?>" href="<?= htmlspecialchars(nx21_url($file), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
</header>
<main>
<div class="clock-bar">
  <div class="container">
    <div class="site-datetime" aria-label="Tanggal dan waktu saat ini">
      <span class="datetime-date" id="nx21-date">Memuat tanggal...</span>
      <span class="datetime-time" id="nx21-time">--:--:-- WIB</span>
    </div>
  </div>
</div>
