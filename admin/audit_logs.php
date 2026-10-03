<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
$pdo=get_db();
$actionFilter=trim((string)($_GET['action_filter']??''));
$entity=trim((string)($_GET['entity_type']??''));
$where=[];$params=[];
if($actionFilter!==''){ $where[]='al.action LIKE ?'; $params[]='%'.$actionFilter.'%'; }
if($entity!==''){ $where[]='al.entity_type=?'; $params[]=$entity; }
$whereSql=$where?'WHERE '.implode(' AND ',$where):'';
$stmt=$pdo->prepare("SELECT al.*, a.username FROM audit_logs al LEFT JOIN admins a ON a.id=al.admin_id $whereSql ORDER BY al.id DESC LIMIT 500");
$stmt->execute($params);$logs=$stmt->fetchAll();
$active_menu='audit';$page_title='Audit Log';include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Audit Log</h1></div>
<div class="card"><form method="get" class="filter-bar"><input name="action_filter" placeholder="Filter aksi, contoh: vote.create" value="<?=e($actionFilter)?>"><select name="entity_type"><option value="">Semua Entity</option><?php foreach(['poll','candidate','participant','class','vote','poll_question','poll_question_option','website','website_timeline','website_gallery','website_contact','admin'] as $x):?><option value="<?=e($x)?>" <?=$entity===$x?'selected':''?>><?=e($x)?></option><?php endforeach;?></select><button class="btn small">Filter</button><a href="audit_logs.php" class="btn small secondary">Reset</a></form>
<table><thead><tr><th>Waktu</th><th>Admin</th><th>Aksi</th><th>Entity</th><th>ID</th><th>Deskripsi</th></tr></thead><tbody><?php foreach($logs as $log):?><tr><td><?=e($log['created_at'])?></td><td><?=e($log['username']??'System')?></td><td><code><?=e($log['action'])?></code></td><td><?=e($log['entity_type']??'-')?></td><td><?=e((string)($log['entity_id']??'-'))?></td><td><?=e($log['description']??'')?></td></tr><?php endforeach;?><?php if(!$logs):?><tr><td colspan="6" class="muted">Belum ada log.</td></tr><?php endif;?></tbody></table><p class="muted">Menampilkan maksimal 500 aktivitas terbaru, diurutkan dari yang paling baru.</p></div>
<?php include __DIR__.'/includes/footer.php'; ?>
