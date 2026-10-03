<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pdo = get_db();
$pollId = (int)($_GET['poll_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM polls WHERE id=? LIMIT 1');
$stmt->execute([$pollId]);
$poll = $stmt->fetch();
if (!$poll) {
    flash_set('error', 'Polling tidak ditemukan.');
    redirect('settings.php');
}

$settings = get_poll_settings($pollId);
$questions = [];
$candidates = [];
if ($poll['poll_type'] === 'questionnaire') {
    ensure_questionnaire_schema($pdo);
    $questions = get_poll_questions($pollId);
    foreach ($questions as &$q) {
        $q['options'] = get_question_options((int)$q['id']);
    }
    unset($q);
} else {
    $st = $pdo->prepare('SELECT * FROM candidates WHERE poll_id=? AND status=\'active\' ORDER BY sort_order,id');
    $st->execute([$pollId]);
    $candidates = $st->fetchAll();
}

$photoPollIds=[];
if ($poll['poll_type']==='questionnaire') {
    foreach ($questions as $qq) foreach ($qq['options'] as $oo) if (!empty($oo['target_poll_id'])) $photoPollIds[(int)$oo['target_poll_id']]=true;
}
$photoPreviewPolls=[];
if ($photoPollIds) {
    $ids=array_keys($photoPollIds); $in=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT * FROM polls WHERE id IN ($in) AND poll_type IN ('single_choice','image_choice')"); $st->execute($ids);
    foreach($st->fetchAll() as $tp){
        $cs=$pdo->prepare('SELECT id,name,image_url,description FROM candidates WHERE poll_id=? AND status=\'active\' ORDER BY sort_order,id'); $cs->execute([(int)$tp['id']]);
        $photoPreviewPolls[(int)$tp['id']]=['title'=>$tp['title'],'candidates'=>$cs->fetchAll()];
    }
}

$active_menu = 'settings';
$page_title = 'Preview Polling';
include __DIR__ . '/includes/header.php';
?>
<div class="admin-topbar"><h1>Preview Polling</h1></div>
<div class="alert info">Ini hanya preview admin. Tidak ada peserta, vote, atau jawaban yang disimpan.</div>
<div class="card" style="max-width:760px;margin:auto;">
  <div class="topbar" style="margin-bottom:18px;"><h1><?= e($poll['title']) ?></h1><?php if (!empty($poll['description'])): ?><p><?= e($poll['description']) ?></p><?php endif; ?></div>

<?php if ($poll['poll_type'] === 'questionnaire'): ?>
  <div id="previewQuestionnaire">
    <?php if (!$questions): ?><div class="alert info">Belum ada pertanyaan.</div><?php else: ?>
      <div class="muted" id="previewCounter"></div>
      <div id="previewQuestion"></div>
      <div id="previewError" class="alert error" style="display:none"></div>
      <button type="button" class="btn" id="previewNext">Lanjut</button>
    <?php endif; ?>
  </div>
<?php else: ?>
  <h2>Pilih Foto</h2>
  <?php if (!$candidates): ?><div class="alert info">Belum ada foto aktif.</div><?php else: ?>
    <div class="candidate-grid">
      <?php foreach ($candidates as $c): ?>
        <div class="candidate-card" style="cursor:default;">
          <?php if (!empty($c['image_url'])): ?><img src="<?= e($c['image_url']) ?>" alt="<?= e($c['name']) ?>" loading="lazy" class="zoomable-image"><?php endif; ?>
          <div class="cname"><?= e($c['name']) ?></div>
          <?php if (!empty($c['description'])): ?><div class="muted" style="padding:0 10px 10px;"><?= e($c['description']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>
<div style="text-align:center;margin-top:14px;"><a class="btn secondary" href="settings.php?action=edit&id=<?= $pollId ?>">Kembali ke Pengaturan Polling</a></div>

<?php if ($poll['poll_type'] === 'questionnaire' && $questions): ?>
<script>
const previewQuestions = <?= json_encode(array_map(static function($q) {
    return [
        'id'=>(int)$q['id'],
        'text'=>$q['question_text'],
        'image'=>$q['image_url'] ?? '',
        'type'=>$q['question_type'],
        'required'=>(int)$q['required'],
        'options'=>array_map(static function($o) { return ['id'=>(int)$o['id'],'text'=>$o['option_text'],'image'=>$o['image_url'] ?? '','ends'=>(int)$o['ends_poll'],'next'=>(int)($o['next_question_id'] ?? 0),'targetPoll'=>(int)($o['target_poll_id'] ?? 0)]; }, $q['options'])
    ];
}, $questions), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const photoPreviewPolls = <?= json_encode($photoPreviewPolls, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const previewMax = <?= (int)($settings['max_questions'] ?? 0) ?>;
let previewCurrent = 0;
let previewAnswered = 0;
const byId = new Map(previewQuestions.map(q => [q.id, q]));
const qEl = document.getElementById('previewQuestion');
const cEl = document.getElementById('previewCounter');
const errEl = document.getElementById('previewError');
const nextBtn = document.getElementById('previewNext');
function showQuestion(q) {
  errEl.style.display='none';
  if (!q) { qEl.innerHTML='<div class="alert success">Preview selesai.</div>'; nextBtn.style.display='none'; cEl.textContent='Selesai'; return; }
  cEl.textContent='Pertanyaan ' + (previewAnswered + 1) + (previewMax > 0 ? ' dari maksimal ' + previewMax : '');
  let html='';
  if (q.image) html += '<div style="text-align:center;margin:12px 0"><img src="'+escapeHtml(q.image)+'" class="zoomable-image" style="max-width:100%;max-height:360px;border-radius:12px;object-fit:contain"></div>';
  html += '<h2>'+escapeHtml(q.text)+'</h2>';
  q.options.forEach(o => {
    const input = q.type === 'multiple_choice' ? 'checkbox' : 'radio';
    html += '<label style="display:block;padding:12px;margin:10px 0;border:1px solid #ddd;border-radius:10px"><input type="'+input+'" name="previewOption" value="'+o.id+'"> '+escapeHtml(o.text);
    if(o.image) html += '<div style="margin-top:10px;text-align:center"><img src="'+escapeHtml(o.image)+'" class="zoomable-image" style="max-width:100%;width:220px;max-height:180px;object-fit:cover;border-radius:10px;border:1px solid #ddd"></div>';
    html += '</label>';
  });
  qEl.innerHTML=html;
}
function escapeHtml(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
function selectedOptions(q){return [...qEl.querySelectorAll('input[name="previewOption"]:checked')].map(x=>Number(x.value));}
function goNext(){
  const q=byId.get(previewCurrent); if(!q)return;
  const ids=selectedOptions(q);
  if(!ids.length && q.required){errEl.textContent='Pilih jawaban terlebih dahulu.';errEl.style.display='block';return;}
  const opts=q.options.filter(o=>ids.includes(o.id));
  if(opts.some(o=>o.ends)){qEl.innerHTML='<div class="alert success">Preview: polling selesai pada pilihan ini.</div>';nextBtn.style.display='none';cEl.textContent='Selesai';return;}
  const targets=[...new Set(opts.map(o=>o.next).filter(Boolean))];
  const photoTargets=[...new Set(opts.map(o=>o.targetPoll).filter(Boolean))];
  if(targets.length>1 || photoTargets.length>1 || (targets.length && photoTargets.length)){errEl.textContent='Pilihan ini mengarah ke jalur berbeda. Untuk preview, pilih satu jalur yang sama.';errEl.style.display='block';return;}
  if(photoTargets.length){
    const pp=photoPreviewPolls[photoTargets[0]];
    previewAnswered++;
    if(!pp){qEl.innerHTML='<div class="alert error">Polling foto tujuan tidak ditemukan dalam preview.</div>';nextBtn.style.display='none';return;}
    let html='<h2>'+escapeHtml(pp.title)+'</h2><p class="muted">Preview: peserta akan masuk ke polling foto ini.</p>';
    if(!pp.candidates.length) html+='<div class="alert info">Belum ada foto aktif pada polling tujuan.</div>';
    else { html+='<div class="candidate-grid">'; pp.candidates.forEach(c=>{html+='<div class="candidate-card" style="cursor:default">'; if(c.image_url) html+='<img src="'+escapeHtml(c.image_url)+'" alt="">'; html+='<div class="cname">'+escapeHtml(c.name)+'</div></div>';}); html+='</div>'; }
    qEl.innerHTML=html+'<div class="alert success" style="margin-top:14px">Setelah memilih jawaban ini, sesi berpindah ke polling foto tujuan.</div>'; nextBtn.style.display='none'; cEl.textContent='Polling Foto'; return;
  }
  previewAnswered++;
  if(previewMax>0 && previewAnswered>=previewMax){qEl.innerHTML='<div class="alert success">Preview: batas jumlah pertanyaan tercapai.</div>';nextBtn.style.display='none';cEl.textContent='Selesai';return;}
  let next=targets.length ? byId.get(targets[0]) : null;
  if(!next){const idx=previewQuestions.findIndex(x=>x.id===q.id); next=previewQuestions[idx+1] || null;}
  previewCurrent=next?next.id:0; showQuestion(next);
}
nextBtn.addEventListener('click',goNext);
previewCurrent=previewQuestions[0]?.id||0; showQuestion(previewQuestions[0]);
</script>
<?php endif; ?>
<?php include __DIR__.'/includes/footer.php'; ?>
