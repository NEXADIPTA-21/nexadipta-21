<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$participant=get_verified_participant();
$pollId=(int)($participant['poll_id']??$_SESSION['poll_id']??0);
if(!$participant||$pollId<=0||(int)($participant['poll_id']??0)!==$pollId){clear_participant_session();redirect('poll.php');}
$pdo=get_db();
try { ensure_questionnaire_schema($pdo); } catch (Throwable $e) { http_response_code(500); exit('Struktur questionnaire belum siap. Hubungi admin.'); }
$poll=get_poll_by_id($pollId);
if(!$poll||!poll_is_open($poll)||($poll['poll_type']??'')!=='questionnaire'){redirect('vote.php');}

$p=$pdo->prepare('SELECT p.*,c.name class_name FROM participants p JOIN classes c ON c.id=p.class_id WHERE p.id=? LIMIT 1');
$p->execute([$participant['id']]); $dbParticipant=$p->fetch();
if(!$dbParticipant||$dbParticipant['status']!=='active'||!participant_allowed_for_poll($pollId,(int)$dbParticipant['id'])){clear_participant_session();flash_set('error','Peserta tidak lagi memenuhi syarat.');redirect('poll.php');}

$participation=get_or_create_poll_participation($pollId,(int)$dbParticipant['id']);
if(in_array($participation['status'],['completed','terminated'],true)){clear_participant_session();flash_set('info','Polling ini sudah selesai untuk peserta tersebut.');redirect('poll.php');}
$questions=get_poll_questions($pollId); $settings=get_questionnaire_settings($pollId); $maxQuestions=(int)($settings['max_questions']??0);
if(!$questions){clear_participant_session();flash_set('error','Polling belum memiliki pertanyaan.');redirect('poll.php');}

function answered_question_ids(PDO $pdo,int $pollId,int $participantId): array {
    $st=$pdo->prepare('SELECT DISTINCT question_id FROM poll_answers WHERE poll_id=? AND participant_id=? AND is_draft=0');
    $st->execute([$pollId,$participantId]);
    return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}

// AJAX autosave: draft jawaban hanya untuk pertanyaan yang sedang dikerjakan.
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='autosave'){
    header('Content-Type: application/json; charset=UTF-8');
    try{
        csrf_verify();
        $qid=(int)($_POST['question_id']??0); $raw=$_POST['option_ids']??[]; if(!is_array($raw))$raw=[$raw];
        $ids=array_values(array_unique(array_filter(array_map('intval',$raw),fn($v)=>$v>0)));
        $question=get_poll_question($qid,$pollId);
        $participation=get_poll_participation($pollId,(int)$dbParticipant['id']);
        if(!$question || !$participation || $participation['status']!=='in_progress') throw new RuntimeException('Sesi pertanyaan tidak valid.');
        $valid=[];
        if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$st=$pdo->prepare('SELECT id FROM poll_question_options WHERE question_id=? AND id IN('.$in.') ORDER BY sort_order,id');$st->execute(array_merge([$qid],$ids));$valid=$st->fetchAll(PDO::FETCH_COLUMN);}
        $valid=array_map('intval',$valid);
        if($question['question_type']!=='multiple_choice' && count($valid)>1) throw new RuntimeException('Pilihan tidak valid.');
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM poll_answers WHERE poll_id=? AND participant_id=? AND question_id=? AND is_draft=1')->execute([$pollId,$dbParticipant['id'],$qid]);
        if($valid){$ins=$pdo->prepare('INSERT INTO poll_answers(poll_id,participant_id,question_id,option_id,is_draft) VALUES(?,?,?,?,1)');foreach($valid as $oid)$ins->execute([$pollId,$dbParticipant['id'],$qid,$oid]);}
        $pdo->commit();
        echo json_encode(['ok'=>true,'saved_count'=>count($valid),'message'=>'Jawaban tersimpan otomatis.']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'message'=>'Autosave gagal. Silakan lanjutkan secara manual.']);}
    exit;
}

$answered=answered_question_ids($pdo,$pollId,(int)$dbParticipant['id']);
if($maxQuestions>0 && count($answered)>=$maxQuestions){$pdo->prepare('UPDATE poll_participations SET status=\'completed\',completed_at=NOW() WHERE poll_id=? AND participant_id=?')->execute([$pollId,$dbParticipant['id']]);$_SESSION['vote_success']=true;$_SESSION['vote_success_poll_title']=$poll['title'];$_SESSION['vote_success_poll_id']=$pollId;$_SESSION['vote_success_questionnaire']=true;$_SESSION['vote_success_terminated']=false;clear_participant_session();redirect('success.php');}

$current=null;
$dbCurrent=(int)($participation['current_question_id']??0);
if($dbCurrent){foreach($questions as $q){if((int)$q['id']===$dbCurrent&&!in_array((int)$q['id'],$answered,true)){$current=$q;break;}}}
if(!$current){foreach($questions as $q){if(!in_array((int)$q['id'],$answered,true)){$current=$q;break;}}}
if(!$current){$pdo->prepare('UPDATE poll_participations SET status=\'completed\',completed_at=NOW() WHERE poll_id=? AND participant_id=?')->execute([$pollId,$dbParticipant['id']]);$_SESSION['vote_success']=true;$_SESSION['vote_success_poll_title']=$poll['title'];$_SESSION['vote_success_poll_id']=$pollId;$_SESSION['vote_success_questionnaire']=true;$_SESSION['vote_success_terminated']=false;clear_participant_session();redirect('success.php');}

$options=get_question_options((int)$current['id']);
$draftStmt=$pdo->prepare('SELECT option_id FROM poll_answers WHERE poll_id=? AND participant_id=? AND question_id=? AND is_draft=1');
$draftStmt->execute([$pollId,$dbParticipant['id'],$current['id']]);
$draftIds=array_map('intval',$draftStmt->fetchAll(PDO::FETCH_COLUMN));
$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_verify(); $raw=$_POST['option_ids']??[]; if(!is_array($raw))$raw=[$raw]; $ids=array_values(array_unique(array_filter(array_map('intval',$raw),fn($v)=>$v>0)));
 $valid=[]; if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$st=$pdo->prepare('SELECT * FROM poll_question_options WHERE question_id=? AND id IN('.$in.') ORDER BY sort_order,id');$st->execute(array_merge([(int)$current['id']],$ids));$valid=$st->fetchAll();}
 $validIds=array_map('intval',array_column($valid,'id')); $isMulti=$current['question_type']==='multiple_choice';
 if(!$valid&& !empty($current['required']))$error='Pilih jawaban terlebih dahulu.';
 elseif(!$isMulti&&count($validIds)>1)$error='Pilih satu jawaban saja.';
 elseif($isMulti&&count($validIds)<1&& !empty($current['required']))$error='Pilih minimal satu jawaban.';
 else{
  $targets=array_values(array_unique(array_filter(array_map('intval',array_column($valid,'next_question_id')),fn($v)=>$v>0)));
  $photoTargets=array_values(array_unique(array_filter(array_map('intval',array_column($valid,'target_poll_id')),fn($v)=>$v>0)));
  $ends=false; foreach($valid as $o){if(!empty($o['ends_poll']))$ends=true;}
  $invalidTarget=false;
  foreach($targets as $targetId){
      $targetQuestion=get_poll_question($targetId,$pollId);
      if(!$targetQuestion || (int)$targetId === (int)$current['id']) {$invalidTarget=true; break;}
  }
  if($ends && ($targets || $photoTargets)) $error='Jawaban yang mengakhiri polling tidak boleh memiliki cabang lain.';
  elseif($invalidTarget) $error='Tujuan pertanyaan tidak valid.';
  elseif(count($targets)>1 || count($photoTargets)>1 || ($targets && $photoTargets)){
      $error='Pilihan yang Anda pilih mengarah ke jalur berbeda. Silakan pilih satu jalur yang sama.';
  }
  else try{
   $targetPhotoPollId=(int)($photoTargets[0]??0);
   $targetPhotoPoll=null;
   if($targetPhotoPollId){
      $tp=$pdo->prepare("SELECT * FROM polls WHERE id=? AND poll_type IN ('single_choice','image_choice') LIMIT 1");
      $tp->execute([$targetPhotoPollId]);
      $targetPhotoPoll=$tp->fetch();
      if(!$targetPhotoPoll || !poll_is_open($targetPhotoPoll)) throw new RuntimeException('Polling foto tujuan belum dibuka atau sudah ditutup.');
      if(!participant_can_enter_poll($targetPhotoPollId,(int)$dbParticipant['id'])) throw new RuntimeException('Peserta tidak terdaftar pada polling foto tujuan.');
      $tpSettings=get_poll_settings($targetPhotoPollId);
      if(participant_has_voted($targetPhotoPollId,(int)$dbParticipant['id']) && empty($tpSettings['allow_change_vote'])) throw new RuntimeException('Peserta sudah mengikuti polling foto tujuan tersebut.');
   }
   $pdo->beginTransaction();
   $pdo->prepare('DELETE FROM poll_answers WHERE poll_id=? AND participant_id=? AND question_id=? AND is_draft=1')->execute([$pollId,$dbParticipant['id'],$current['id']]);
   // Saat final, jawaban untuk pertanyaan ini ditulis sebagai jawaban resmi.
   $pdo->prepare('DELETE FROM poll_answers WHERE poll_id=? AND participant_id=? AND question_id=? AND is_draft=0')->execute([$pollId,$dbParticipant['id'],$current['id']]);
   foreach($valid as $o){$ins=$pdo->prepare('INSERT INTO poll_answers(poll_id,participant_id,question_id,option_id,is_draft) VALUES(?,?,?,?,0)');$ins->execute([$pollId,$dbParticipant['id'],$current['id'],$o['id']]);}
   $nextId=$targets[0]??0; $newAnswered=count($answered)+($valid?1:0); $limitReached=$maxQuestions>0&&$newAnswered>=$maxQuestions;
   // Tanpa cabang khusus: lanjut ke pertanyaan berikutnya berdasarkan urutan (juga untuk pertanyaan opsional yang dilewati).
   $noMore=false;
   if(!$nextId&&!$ends&&!$targetPhotoPollId){
       $seen=false;$doneIds=array_merge($answered,[(int)$current['id']]);
       foreach($questions as $qq){
           if((int)$qq['id']===(int)$current['id']){$seen=true;continue;}
           if($seen&&!in_array((int)$qq['id'],$doneIds,true)){$nextId=(int)$qq['id'];break;}
       }
       $noMore=!$nextId;
   }
   if($ends||$limitReached||$targetPhotoPollId||$noMore){$status=$ends?'terminated':'completed';$pdo->prepare('UPDATE poll_participations SET status=?,current_question_id=NULL,completed_at=NOW() WHERE poll_id=? AND participant_id=?')->execute([$status,$pollId,$dbParticipant['id']]);}
   else{$pdo->prepare('UPDATE poll_participations SET status=\'in_progress\',completed_at=NULL,current_question_id=? WHERE poll_id=? AND participant_id=?')->execute([$nextId?:null,$pollId,$dbParticipant['id']]);}
   $pdo->commit();
   if($targetPhotoPollId){
       $_SESSION['participant']['poll_id']=$targetPhotoPollId;
       $_SESSION['poll_id']=$targetPhotoPollId;
       $_SESSION['questionnaire_source_poll_id']=$pollId;
       redirect('vote.php');
   }
   if($ends||$limitReached||$noMore){
       $_SESSION['vote_success']=true;$_SESSION['vote_success_poll_title']=$poll['title'];$_SESSION['vote_success_poll_id']=$pollId;$_SESSION['vote_success_questionnaire']=true;$_SESSION['vote_success_terminated']=$ends;
       $_SESSION['vote_success_show_results']=!empty(get_poll_settings($pollId)['show_results']);
       clear_participant_session();redirect('success.php');
   }
   redirect('questionnaire.php');
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Jawaban gagal disimpan. Silakan coba lagi.';}
 }
}
$number=count($answered)+1;
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e(APP_NAME)?> - Polling</title><link rel="stylesheet" href="assets/css/style.css">
<script src="assets/js/theme.js"></script></head><body><div class="wrap"><div class="topbar"><h1><?=e($poll['title'])?></h1><p>Halo, <strong><?=e($dbParticipant['name'])?></strong> · <?=e($dbParticipant['class_name'])?></p></div><div class="steps"><div class="step-dot done"></div><div class="step-dot active"></div><div class="step-dot"></div><div class="step-dot"></div></div><div class="card"><div class="muted" style="margin-bottom:8px;">Pertanyaan ke-<?=$number?><?= $maxQuestions>0?' dari maksimal '.$maxQuestions:'' ?> <span id="autosaveStatus" style="margin-left:8px;"></span></div><?php if(!empty($current['image_url'])):?><div style="margin:12px 0;text-align:center"><img src="<?=e($current['image_url'])?>" alt="Gambar pertanyaan" class="zoomable-image q-img" style="max-width:100%;max-height:420px;object-fit:contain"></div><?php endif;?><h2><?=e($current['question_text'])?></h2><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post" id="questionForm"><?=csrf_field()?><?php foreach($options as $o):?><label class="opt"><input type="<?= $current['question_type']==='multiple_choice'?'checkbox':'radio' ?>" name="option_ids<?= $current['question_type']==='multiple_choice'?'[]':'' ?>" value="<?=$o['id']?>" <?=in_array((int)$o['id'],$draftIds,true)?'checked':''?>> <span style="vertical-align:middle"><?=e($o['option_text'])?></span><?php if(!empty($o['image_url'])):?><div style="margin-top:10px"><img src="<?=e($o['image_url'])?>" alt="Foto jawaban" loading="lazy" class="zoomable-image" style="max-width:100%;width:220px;max-height:180px;object-fit:cover;border-radius:10px;border:1px solid #ddd"></div><?php endif;?></label><?php endforeach;?><button class="btn" type="submit">Lanjut</button></form></div><p class="center muted">Jawaban tersimpan otomatis saat Anda memilih. Jika koneksi terputus, buka kembali sesi ini untuk melanjutkan.</p></div>
<script>
const form=document.getElementById('questionForm'), statusEl=document.getElementById('autosaveStatus'), qid=<?= (int)$current['id'] ?>;
let saveTimer=null;
function setStatus(text,ok){statusEl.textContent=text;statusEl.style.color=ok?'#16a34a':'#dc2626';}
async function autosave(){
 const fd=new FormData(); fd.append('action','autosave'); fd.append('question_id',String(qid));
 const csrf=form.querySelector('input[name="csrf_token"]'); if(csrf) fd.append('csrf_token',csrf.value);
 form.querySelectorAll('input[name="option_ids[]"]:checked,input[name="option_ids"]:checked').forEach(i=>fd.append('option_ids[]',i.value));
 setStatus('Menyimpan...',true);
 try{const r=await fetch('questionnaire.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();if(!d.ok)throw new Error(d.message||'Gagal');setStatus('Tersimpan otomatis',true);}catch(e){setStatus('Belum tersimpan',false);}
}
form.querySelectorAll('input[type="radio"],input[type="checkbox"]').forEach(i=>i.addEventListener('change',()=>{clearTimeout(saveTimer);saveTimer=setTimeout(autosave,350);}));
window.addEventListener('beforeunload',()=>{});
</script><script src="assets/js/image-viewer.js"></script></body></html>
