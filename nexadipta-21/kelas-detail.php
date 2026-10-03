<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/includes/content.php';
$pdo=get_db(); ensure_nexadipta21_schema($pdo);
$id=(int)($_GET['id']??0); $st=$pdo->prepare("SELECT id,name FROM classes WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $class=$st->fetch();
if(!$class){ http_response_code(404); $pageTitle='Kelas tidak ditemukan'; $activePage='classes'; include __DIR__.'/includes/header.php'; echo '<section class="section"><div class="container"><div class="empty-card"><h2>Kelas tidak ditemukan</h2><p>Kelas tersebut tidak tersedia atau sudah tidak aktif.</p><a class="btn btn-primary" href="'.e(nx21_url('kelas.php')).'">Kembali ke Kelas</a></div></div></section>'; include __DIR__.'/includes/footer.php'; exit; }
$st=$pdo->prepare("SELECT id,name,photo_path FROM participants WHERE class_id=? AND status='active' ORDER BY name ASC"); $st->execute([$id]); $members=$st->fetchAll();
$pageTitle='Kelas '. $class['name']; $activePage='classes'; include __DIR__.'/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="section-kicker">KELAS</span><h1><?= e($class['name']) ?></h1><p><?= count($members) ?> anggota aktif terdaftar di kelas ini.</p><a class="btn btn-ghost" href="<?= e(nx21_url('kelas.php')) ?>">← Semua Kelas</a></div></section>
<section class="section"><div class="container"><div class="members-grid members-public">
<?php foreach($members as $i=>$m): ?><div class="member member-photo-card">
  <?php if (!empty($m['photo_path'])): ?>
    <img class="member-photo" src="<?= e(nx21_media_url($m['photo_path'])) ?>" alt="<?= e($m['name']) ?>">
  <?php else: ?>
    <div class="member-photo member-photo-placeholder"><span>N21</span></div>
  <?php endif; ?>
  <div class="member-info"><span><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span><strong><?= e($m['name']) ?></strong><small><?= e($class['name']) ?></small></div>
</div><?php endforeach; ?>
<?php if(!$members): ?><div class="empty-card">Belum ada anggota aktif di kelas ini.</div><?php endif; ?>
</div></div></section>
<?php include __DIR__.'/includes/footer.php'; ?>
