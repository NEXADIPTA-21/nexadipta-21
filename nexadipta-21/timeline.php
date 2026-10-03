<?php
require_once __DIR__.'/../includes/functions.php'; require_once __DIR__.'/includes/content.php';
$pdo=get_db(); ensure_nexadipta21_schema($pdo);
$pageTitle='Timeline NEXADIPTA 21'; $activePage='timeline';
$events=$pdo->query("SELECT * FROM website_timeline WHERE is_active=1 ORDER BY CASE WHEN event_date IS NULL THEN 1 ELSE 0 END, event_date ASC, sort_order ASC, id ASC")->fetchAll();
include __DIR__.'/includes/header.php';
?>
<section class="page-hero"><div class="container"><span class="section-kicker">TIMELINE & JADWAL</span><h1>Perjalanan dan agenda kita.</h1><p>Jadwal sesi foto, video, kegiatan, dan momen penting angkatan dapat dilihat di sini.</p></div></section>
<section class="section section-alt"><div class="container"><div class="timeline timeline-page">
<?php foreach($events as $event): ?><article class="timeline-item"><span class="timeline-dot"></span><div class="timeline-event-card">
<small><?= e($event['event_date'] ? date('d F Y', strtotime($event['event_date'])) : 'JADWAL') ?></small>
<h3><?= e($event['title']) ?></h3>
<?php if($event['description']): ?><p><?= nl2br(e($event['description'])) ?></p><?php endif; ?>
<div class="event-meta"><?php if($event['start_time']): ?><span><?= e(substr($event['start_time'],0,5)) ?><?= $event['end_time'] ? '–'.e(substr($event['end_time'],0,5)) : '' ?></span><?php endif; ?><?php if($event['location']): ?><span><?= e($event['location']) ?></span><?php endif; ?></div>
<?php if($event['media_url']): ?><div class="event-media"><?php if($event['media_type']==='video'): ?><a class="btn btn-outline" target="_blank" rel="noopener" href="<?= e($event['media_url']) ?>">▶ Lihat Video</a><?php else: ?><img src="<?= e($event['media_url']) ?>" alt="<?= e($event['title']) ?>" loading="lazy" class="event-image zoomable-image"><?php endif; ?></div><?php endif; ?>
</div></article><?php endforeach; ?>
<?php if(!$events): ?><div class="empty-card">Belum ada timeline atau jadwal yang dipublikasikan.</div><?php endif; ?>
</div></div></section>
<?php include __DIR__.'/includes/footer.php'; ?>
