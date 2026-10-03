<?php
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/includes/content.php';
$pdo=get_db(); ensure_nexadipta21_schema($pdo);
$pageTitle='Kelas NEXADIPTA 21'; $activePage='classes';
$classes=$pdo->query("SELECT c.id,c.name,(SELECT COUNT(*) FROM participants p WHERE p.class_id=c.id AND p.status='active') participant_count FROM classes c WHERE c.status='active' ORDER BY c.name ASC")->fetchAll();
include __DIR__.'/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="section-kicker">KELAS</span><h1>Temukan teman sekelasmu.</h1><p>Pilih kelas untuk melihat anggota aktif yang terdaftar di dalamnya.</p></div></section>
<section class="section"><div class="container"><div class="class-grid">
<?php foreach($classes as $c): ?><a class="class-card" href="<?= htmlspecialchars(nx21_url('kelas-detail.php?id='.(int)$c['id']),ENT_QUOTES,'UTF-8') ?>"><span>KELAS</span><h3><?= e($c['name']) ?></h3><p><?= (int)$c['participant_count'] ?> anggota <strong>→</strong></p></a><?php endforeach; ?>
<?php if(!$classes): ?><div class="empty-card">Belum ada kelas aktif.</div><?php endif; ?>
</div></div></section>
<?php include __DIR__.'/includes/footer.php'; ?>
