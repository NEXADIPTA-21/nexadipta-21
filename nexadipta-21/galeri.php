<?php
require_once __DIR__.'/../includes/functions.php'; require_once __DIR__.'/includes/content.php';
$pdo=get_db(); ensure_nexadipta21_schema($pdo);
$pageTitle='Galeri NEXADIPTA 21'; $activePage='gallery';
$gallery=$pdo->query("SELECT * FROM website_gallery WHERE is_active=1 ORDER BY sort_order ASC,id DESC")->fetchAll();
include __DIR__.'/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="section-kicker">GALERI</span><h1>Momen Angkatan 21</h1><p>Foto galeri dikelola oleh admin dan dapat diperbarui kapan saja.</p></div></section>
<section class="section"><div class="container"><div class="gallery-grid gallery-page">
<?php foreach($gallery as $i=>$item): ?><article class="gallery-card <?= $i===0?'gallery-large':'' ?>"><img src="<?= e(nx21_media_url($item['image_path'])) ?>" alt="<?= e($item['title']) ?>" loading="lazy" class="zoomable-image"><div class="gallery-caption"><strong><?= e($item['title']) ?></strong><?php if($item['description']): ?><span><?= e($item['description']) ?></span><?php endif; ?></div></article><?php endforeach; ?>
<?php if(!$gallery): ?><div class="empty-card">Belum ada foto di galeri.</div><?php endif; ?>
</div></div></section>
<?php include __DIR__.'/includes/footer.php'; ?>
