<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
$pdo=get_db();
$polls=$pdo->query('SELECT id,title,status,poll_type FROM polls ORDER BY id DESC')->fetchAll();
$pollId=(int)($_GET['poll_id']??($_POST['poll_id']??($polls[0]['id']??0)));
$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_verify(); $confirm=trim((string)($_POST['confirm_text']??''));
 if(strtoupper($confirm)!=='RESET') $error='Ketik "RESET" persis untuk melanjutkan.';
 elseif($pollId<=0) $error='Polling belum dipilih.';
 else{
   $stmt=$pdo->prepare('SELECT title FROM polls WHERE id=?');$stmt->execute([$pollId]);$poll=$stmt->fetch();
   if(!$poll)$error='Polling tidak ditemukan.';
   else{try{
      $pdo->beginTransaction();
      if (($poll['poll_type'] ?? '') === 'questionnaire') {
          $a=$pdo->prepare('DELETE FROM poll_answers WHERE poll_id=?'); $a->execute([$pollId]); $deletedAnswers=$a->rowCount();
          $q=$pdo->prepare('DELETE FROM poll_participations WHERE poll_id=?'); $q->execute([$pollId]); $deletedSessions=$q->rowCount();
          $pdo->commit();
          admin_audit('questionnaire.reset_all','poll',$pollId,'Reset semua sesi questionnaire pada polling');
          flash_set('success','Semua sesi questionnaire pada polling "'.$poll['title'].'" berhasil direset ('.$deletedSessions.' sesi, '.$deletedAnswers.' jawaban).');
      } else {
          $stmt=$pdo->prepare('DELETE FROM votes WHERE poll_id=?');$stmt->execute([$pollId]);$deleted=$stmt->rowCount();
          $legacy=$pdo->prepare('UPDATE participants p SET has_voted=CASE WHEN EXISTS(SELECT 1 FROM votes v WHERE v.participant_id=p.id) THEN 1 ELSE 0 END WHERE p.id IN (SELECT participant_id FROM poll_participants WHERE poll_id=?)');$legacy->execute([$pollId]);
          $pdo->commit();admin_audit('vote.reset_all','poll',$pollId,'Reset semua vote pada polling');flash_set('success','Semua vote pada polling "'.$poll['title'].'" berhasil direset ('.$deleted.' vote).');
      }
      redirect('voters.php?poll_id='.$pollId);
   }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();error_log('Reset polling failed: '.$ex->getMessage());$error='Reset polling gagal diproses. Tidak ada data yang diubah.';}}
 }
}
$active_menu='voters';$page_title='Reset Vote Polling';include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Reset Polling</h1></div>
<div class="card" style="max-width:520px">
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<form method="post"><?=csrf_field()?><label>Polling</label><select name="poll_id" required><?php foreach($polls as $p):?><option value="<?=(int)$p['id']?>" <?=$pollId===(int)$p['id']?'selected':''?>><?=e($p['title'])?> — <?=e(ucfirst($p['status']))?></option><?php endforeach;?></select>
<div class="alert error"><strong>Perhatian:</strong> untuk polling foto, semua vote pada polling yang dipilih akan dihapus permanen. Untuk questionnaire, semua sesi dan jawaban questionnaire pada polling yang dipilih akan dihapus. Polling lain tidak terpengaruh.</div><label>Ketik <strong>RESET</strong> untuk konfirmasi</label><input type="text" name="confirm_text" required autocomplete="off"><button class="btn danger">Reset Polling Sekarang</button><a href="voters.php?poll_id=<?=$pollId?>" class="btn secondary">Batal</a></form></div>
<?php include __DIR__.'/includes/footer.php'; ?>
