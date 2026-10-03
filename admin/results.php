<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pdo = get_db();
$polls = $pdo->query('SELECT id, title, status, poll_type FROM polls ORDER BY id DESC')->fetchAll();
$currentPollId = (int)($_GET['poll_id'] ?? ($polls[0]['id'] ?? 0));
$currentPoll = null;
foreach ($polls as $p) if ((int)$p['id'] === $currentPollId) { $currentPoll = $p; break; }

if ($currentPollId > 0 && ($_GET['action'] ?? '') === 'export_questionnaire_csv') {
    $st=$pdo->prepare('SELECT * FROM polls WHERE id=? LIMIT 1'); $st->execute([$currentPollId]); $poll=$st->fetch();
    if(!$poll || $poll['poll_type']!=='questionnaire'){ flash_set('error','Export questionnaire hanya tersedia untuk polling kuesioner.'); redirect('results.php?poll_id='.$currentPollId); }
    ensure_questionnaire_schema($pdo);
    $questions=get_poll_questions($currentPollId);
    $participants=[];
    $st=$pdo->prepare('SELECT p.id,p.name,c.name AS class_name,pp.status,pp.started_at,pp.completed_at FROM poll_participants x JOIN participants p ON p.id=x.participant_id JOIN classes c ON c.id=p.class_id LEFT JOIN poll_participations pp ON pp.poll_id=x.poll_id AND pp.participant_id=x.participant_id WHERE x.poll_id=? ORDER BY p.name ASC');
    $st->execute([$currentPollId]); $participants=$st->fetchAll();
    $ans=[];
    $st=$pdo->prepare("SELECT a.participant_id,a.question_id,STRING_AGG(o.option_text, '; ' ORDER BY o.sort_order, o.id) AS answer_text FROM poll_answers a JOIN poll_question_options o ON o.id=a.option_id WHERE a.poll_id=? AND a.is_draft=0 GROUP BY a.participant_id,a.question_id");
    $st->execute([$currentPollId]); foreach($st->fetchAll() as $r) $ans[(int)$r['participant_id']][(int)$r['question_id']]=$r['answer_text'];
    $safe=preg_replace('/[^A-Za-z0-9_-]+/','_',($poll['title']?:'questionnaire'))?:'questionnaire';
    header('Content-Type:text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="questionnaire_'.$safe.'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    $head=['Peserta','Kelas','Status Sesi','Mulai','Selesai']; foreach($questions as $q)$head[]='P'.(int)$q['sort_order'].' - '.$q['question_text']; fputcsv($out,$head,',','"','\\');
    foreach($participants as $r){$status=$r['status']?:'belum_mulai';$row=[$r['name'],$r['class_name'],$status,$r['started_at']??'',$r['completed_at']??''];foreach($questions as $q)$row[]=$ans[(int)$r['id']][(int)$q['id']]??'';fputcsv($out,$row,',','"','\\');}
    fclose($out); exit;
}

// Legacy/photo exports.
if ($currentPollId > 0 && ($_GET['action'] ?? '') === 'export_votes_csv') {
    $stmt=$pdo->prepare('SELECT title FROM polls WHERE id=? LIMIT 1'); $stmt->execute([$currentPollId]); $pollTitle=(string)($stmt->fetchColumn()?:'polling');
    $stmt=$pdo->prepare('SELECT p.name AS participant_name,c.name AS class_name,ca.name AS candidate_name,v.created_at,v.ip_hash FROM votes v JOIN participants p ON p.id=v.participant_id JOIN classes c ON c.id=p.class_id JOIN candidates ca ON ca.id=v.candidate_id WHERE v.poll_id=? ORDER BY v.created_at ASC'); $stmt->execute([$currentPollId]); $rows=$stmt->fetchAll();
    $safe=preg_replace('/[^A-Za-z0-9_-]+/','_',$pollTitle)?:'polling'; header('Content-Type:text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="detail_vote_'.$safe.'.csv"'); $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['Peserta','Kelas','Pilihan','Waktu Vote','IP Hash'],',','"','\\'); foreach($rows as $r) fputcsv($out,[$r['participant_name'],$r['class_name'],$r['candidate_name'],$r['created_at'],$r['ip_hash']],',','"','\\'); fclose($out); exit;
}
if ($currentPollId > 0 && ($_GET['action'] ?? '') === 'export_csv') {
    $stmt = $pdo->prepare('SELECT title FROM polls WHERE id=? LIMIT 1'); $stmt->execute([$currentPollId]); $pollTitle=(string)($stmt->fetchColumn()?:'polling');
    $stmt = $pdo->prepare('SELECT c.name,c.status,COUNT(v.id) AS vote_count FROM candidates c LEFT JOIN votes v ON v.candidate_id=c.id AND v.poll_id=c.poll_id WHERE c.poll_id=? GROUP BY c.id,c.name,c.status ORDER BY vote_count DESC,c.name ASC'); $stmt->execute([$currentPollId]); $rows=$stmt->fetchAll();
    $safe=preg_replace('/[^A-Za-z0-9_-]+/','_',$pollTitle)?:'polling'; header('Content-Type:text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="hasil_'.$safe.'.csv"'); $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['Nama Foto','Status','Jumlah Vote','Persentase'],',','"','\\'); $total=0;foreach($rows as $r)$total+=(int)$r['vote_count'];foreach($rows as $r){$pct=$total>0?round(((int)$r['vote_count']/$total)*100,1):0;fputcsv($out,[$r['name'],$r['status'],(int)$r['vote_count'],$pct.'%'],',','"','\\');}fclose($out);exit;
}

$isQuestionnaire = $currentPoll && $currentPoll['poll_type']==='questionnaire';
$totalVotes=$eligible=$votedParticipants=$rate=0;
$results=[];$questionsStats=[];$qSession=['started'=>0,'completed'=>0,'terminated'=>0,'in_progress'=>0];
if($currentPollId>0){
    $st=$pdo->prepare('SELECT COUNT(*) FROM poll_participants pp JOIN participants p ON p.id=pp.participant_id JOIN classes c ON c.id=p.class_id WHERE pp.poll_id=? AND pp.status=\'active\' AND p.status=\'active\' AND c.status=\'active\'');$st->execute([$currentPollId]);$eligible=(int)$st->fetchColumn();
    if($isQuestionnaire){
        ensure_questionnaire_schema($pdo);
        $st=$pdo->prepare('SELECT status,COUNT(*) c FROM poll_participations WHERE poll_id=? GROUP BY status');$st->execute([$currentPollId]);foreach($st->fetchAll() as $r)$qSession[$r['status']]=(int)$r['c'];
        $qSession['started']=array_sum($qSession);
        $votedParticipants=$qSession['completed']+$qSession['terminated'];
        $questions=get_poll_questions($currentPollId);
        foreach($questions as $q){
            $st=$pdo->prepare('SELECT o.id,o.option_text,o.sort_order,COUNT(a.id) AS answer_count FROM poll_question_options o LEFT JOIN poll_answers a ON a.option_id=o.id AND a.poll_id=? AND a.is_draft=0 WHERE o.question_id=? GROUP BY o.id,o.option_text,o.sort_order ORDER BY o.sort_order,o.id');$st->execute([$currentPollId,$q['id']]);$opts=$st->fetchAll();$sum=0;foreach($opts as $o)$sum+=(int)$o['answer_count'];$questionsStats[]=['question'=>$q,'options'=>$opts,'total_answers'=>$sum];
        }
    } else {
        $st=$pdo->prepare('SELECT COUNT(DISTINCT participant_id) FROM votes WHERE poll_id=?');$st->execute([$currentPollId]);$votedParticipants=(int)$st->fetchColumn();
        $st=$pdo->prepare('SELECT c.id,c.name,c.image_url,c.sort_order,COUNT(v.id) AS vote_count FROM candidates c LEFT JOIN votes v ON v.candidate_id=c.id AND v.poll_id=c.poll_id WHERE c.poll_id=? GROUP BY c.id,c.name,c.image_url,c.sort_order ORDER BY vote_count DESC,c.sort_order ASC,c.id ASC');$st->execute([$currentPollId]);$results=$st->fetchAll();foreach($results as $r)$totalVotes+=(int)$r['vote_count'];
    }
}
$rate=$eligible>0?round(($votedParticipants/$eligible)*100,1):0;
$active_menu='results';$page_title='Hasil Polling';include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Hasil Polling</h1></div>
<?php if(empty($polls)): ?><div class="alert info">Belum ada polling.</div><?php else: ?>
<form method="get" class="filter-bar"><select name="poll_id" onchange="this.form.submit()"><?php foreach($polls as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $currentPollId===(int)$p['id']?'selected':'' ?>><?= e($p['title']) ?> — <?= e(ucfirst($p['status'])) ?></option><?php endforeach; ?></select></form>
<?php if($isQuestionnaire): ?>
  <div style="margin-bottom:12px"><a class="btn secondary" href="results.php?action=export_questionnaire_csv&amp;poll_id=<?=$currentPollId?>">Export Jawaban Questionnaire CSV</a></div>
  <div class="stat-grid"><div class="stat-card"><div class="num"><?=$eligible?></div><div class="lbl">Peserta Eligible</div></div><div class="stat-card"><div class="num"><?=$qSession['started']?></div><div class="lbl">Sudah Mulai</div></div><div class="stat-card"><div class="num"><?=$qSession['completed']?></div><div class="lbl">Selesai</div></div><div class="stat-card"><div class="num"><?=$qSession['terminated']?></div><div class="lbl">Berhenti di Tengah</div></div><div class="stat-card"><div class="num"><?=$qSession['in_progress']?></div><div class="lbl">Masih Berjalan</div></div><div class="stat-card"><div class="num"><?=$rate?>%</div><div class="lbl">Partisipasi Selesai</div></div></div>
  <?php foreach($questionsStats as $i=>$qs): $q=$qs['question']; ?><div class="card"><h3>P<?= (int)$q['sort_order'] ?>. <?=e($q['question_text'])?></h3><?php if(!empty($q['image_url'])):?><img src="<?=e($q['image_url'])?>" alt="" style="max-width:260px;max-height:180px;border-radius:10px;object-fit:contain"><?php endif;?><p class="muted">Total jawaban: <?=$qs['total_answers']?></p><?php if(!$qs['options']):?><p class="muted">Belum ada opsi.</p><?php else:foreach($qs['options'] as $o):$pct=$qs['total_answers']>0?round(((int)$o['answer_count']/$qs['total_answers'])*100,1):0;?><div class="chart-row"><div class="chart-label"><?=e($o['option_text'])?></div><div class="chart-track"><div class="chart-fill" style="width:<?=max($pct,4)?>%"><?=$pct?>%</div></div><div class="chart-count"><?= (int)$o['answer_count']?></div></div><?php endforeach;endif;?></div><?php endforeach; ?>
<?php else: ?>
  <div style="margin-bottom:12px"><a class="btn secondary" href="results.php?action=export_csv&amp;poll_id=<?=$currentPollId?>">Export Ringkasan CSV</a> <a class="btn secondary" href="results.php?action=export_votes_csv&amp;poll_id=<?=$currentPollId?>">Export Detail Vote</a></div>
  <div class="stat-grid"><div class="stat-card"><div class="num"><?=$totalVotes?></div><div class="lbl">Total Vote</div></div><div class="stat-card"><div class="num"><?=$eligible?></div><div class="lbl">Peserta Eligible</div></div><div class="stat-card"><div class="num"><?=$votedParticipants?></div><div class="lbl">Peserta Sudah Vote</div></div><div class="stat-card"><div class="num"><?=$rate?>%</div><div class="lbl">Partisipasi</div></div></div>
  <div class="card"><?php if(empty($results)):?><p class="muted">Belum ada foto pada polling ini.</p><?php else:foreach($results as $r):$pct=$totalVotes>0?round(((int)$r['vote_count']/$totalVotes)*100,1):0;?><div class="chart-row"><div class="chart-label"><?=e($r['name'])?></div><div class="chart-track"><div class="chart-fill" style="width:<?=max($pct,4)?>%"> <?=$pct?>%</div></div><div class="chart-count"><?=(int)$r['vote_count']?></div></div><?php endforeach;endif;?></div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__.'/includes/footer.php'; ?>
