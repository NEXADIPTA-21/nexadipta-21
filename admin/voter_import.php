<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
$pdo=get_db();
$step='upload';$error=null;$preview=null;
$polls=$pdo->query('SELECT id,title,status FROM polls ORDER BY id DESC')->fetchAll();
$currentPollId=(int)($_GET['poll_id']??($_POST['poll_id']??($polls[0]['id']??0)));

function parse_participant_csv(string $filePath, PDO $pdo): array {
 $valid=[];$errors=[];$seen=[];$classMap=[];$existing=[];$linkedToPoll=[];
 foreach($pdo->query('SELECT id,name,normalized_name,status FROM classes') as $c)$classMap[$c['normalized_name']]=$c;
 foreach($pdo->query('SELECT normalized_name,class_id FROM participants') as $p)$existing[$p['normalized_name'].'|'.$p['class_id']]=true;
 $h=fopen($filePath,'r'); if(!$h)return ['valid'=>[],'errors'=>[['line'=>0,'reason'=>'File tidak dapat dibaca.']]];
 $bom=fread($h,3); if($bom!=="\xEF\xBB\xBF")rewind($h);
 $line=1;$header=fgetcsv($h,0,',');
 if(!$header||count($header)<2||strtolower(trim($header[0]))!=='name'||strtolower(trim($header[1]))!=='class_name'){fclose($h);return ['valid'=>[],'errors'=>[['line'=>1,'reason'=>'Header CSV harus persis: name,class_name']]];}
 while(($row=fgetcsv($h,0,','))!==false){$line++;if(count($row)<2||trim((string)$row[0])===''&&trim((string)$row[1])==='')continue;$name=trim((string)$row[0]);$class=trim((string)$row[1]);if($name===''||$class===''){ $errors[]=['line'=>$line,'reason'=>'Nama atau kelas kosong.','name'=>$name,'class_name'=>$class];continue;}$nn=normalize_text($name);$nc=normalize_text($class);if(!isset($classMap[$nc])){$errors[]=['line'=>$line,'reason'=>'Kelas tidak ditemukan di sistem.','name'=>$name,'class_name'=>$class];continue;}$cr=$classMap[$nc];$key=$nn.'|'.$cr['id'];if(isset($seen[$key])){$errors[]=['line'=>$line,'reason'=>'Peserta duplikat (muncul dua kali dalam CSV).','name'=>$name,'class_name'=>$class];continue;}$seen[$key]=true;$valid[]=['name'=>$name,'normalized_name'=>$nn,'class_id'=>(int)$cr['id'],'class_name'=>$cr['name']];}
 fclose($h);return ['valid'=>$valid,'errors'=>$errors];
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['step']??'')==='upload'){
 csrf_verify();
 if(empty($_FILES['csv_file'])||$_FILES['csv_file']['error']!==UPLOAD_ERR_OK){$error='Silakan pilih file CSV yang valid.';}
 else{$result=parse_participant_csv($_FILES['csv_file']['tmp_name'],$pdo);$preview=$result;$step='preview';if(!empty($result['valid'])){$_SESSION['import_rows']=$result['valid'];$_SESSION['import_poll_id']=$currentPollId;}else{$error='Tidak ada data valid yang ditemukan pada file CSV.';}}
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['step']??'')==='confirm'){
 csrf_verify();$rows=$_SESSION['import_rows']??[];$importPollId=(int)($_SESSION['import_poll_id']??0);unset($_SESSION['import_rows'],$_SESSION['import_poll_id']);
 if(empty($rows)){flash_set('error','Data import tidak tersedia atau sesi import sudah berakhir.');redirect('voters.php'.($currentPollId?'?poll_id='.$currentPollId:''));}
 $inserted=0;$linked=0;$alreadyLinked=0;$pdo->beginTransaction();
 try{
  $insert=$pdo->prepare('INSERT INTO participants (name,normalized_name,class_id,status) VALUES (?,?,?,\'active\') ON CONFLICT (normalized_name, class_id) DO NOTHING ON CONFLICT (normalized_name, class_id) DO NOTHING');
  $find=$pdo->prepare('SELECT id FROM participants WHERE normalized_name=? AND class_id=? LIMIT 1');
  $link=$importPollId>0 ? $pdo->prepare('INSERT INTO poll_participants (poll_id,participant_id,status) VALUES (?,? ,\'active\') ON CONFLICT (poll_id, participant_id) DO UPDATE SET status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP') : null;
  foreach($rows as $r){
   $insert->execute([$r['name'],$r['normalized_name'],$r['class_id']]);
   if($insert->rowCount()>0){$pid=(int)db_last_insert_id($pdo);$inserted++;}
   else{$find->execute([$r['normalized_name'],$r['class_id']]);$pid=(int)$find->fetchColumn();if(!$pid){throw new RuntimeException('Peserta existing tidak ditemukan saat proses import.');}}
   if($link){$before=$pdo->prepare('SELECT status FROM poll_participants WHERE poll_id=? AND participant_id=? LIMIT 1');$before->execute([$importPollId,$pid]);$wasLinked=$before->fetchColumn();$link->execute([$importPollId,$pid]);if($wasLinked===false)$linked++;else$alreadyLinked++;}
  }
  $pdo->commit();
  $message="Import selesai: $inserted peserta baru ditambahkan.";
  if($importPollId>0)$message.=" $linked peserta ditambahkan ke polling dan $alreadyLinked peserta memang sudah terdaftar di polling tersebut.";
  else $message.=" Tidak ada polling yang dipilih; semua peserta disimpan sebagai data utama.";
  flash_set('success',$message);redirect('voters.php'.($importPollId?'?poll_id='.$importPollId:''));
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();error_log('CSV import failed: '.$ex->getMessage());flash_set('error','Import gagal diproses.');redirect('voters.php?poll_id='.$importPollId);}
}
$active_menu='voters';$page_title='Import Peserta';include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Import Peserta dari CSV</h1></div>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<?php if($step==='upload'):?>
<div class="card" style="max-width:620px"><h3 style="margin-top:0">Polling Tujuan (Opsional)</h3><form method="get" class="filter-bar"><select name="poll_id" onchange="this.form.submit()"><option value="0">— Simpan sebagai data peserta saja —</option><?php foreach($polls as $p):?><option value="<?= (int)$p['id']?>" <?=$currentPollId===(int)$p['id']?'selected':''?>><?=e($p['title'])?> — <?=e(ucfirst($p['status']))?></option><?php endforeach;?></select></form><p>Jika polling dipilih, peserta akan sekaligus didaftarkan ke polling tersebut. Jika tidak memilih polling, data hanya masuk ke Data Peserta dan tetap bisa digunakan untuk polling berikutnya.</p><p>Format CSV: <code>name,class_name</code></p><pre style="background:#f8fafc;padding:12px;border-radius:8px;font-size:13px">name,class_name
Ahmad Rizky Pratama,XII IPA 1
Budi Santoso,XII IPA 1</pre><form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="step" value="upload"><input type="hidden" name="poll_id" value="<?=$currentPollId?>"><label>File CSV</label><input type="file" name="csv_file" accept=".csv,text/csv" required><button class="btn">Upload &amp; Preview</button><a href="voters.php?poll_id=<?=$currentPollId?>" class="btn secondary">Kembali</a></form></div>
<?php else:?>
<div class="card"><h3 style="margin-top:0">Ringkasan Preview</h3><p>Data valid siap diimpor: <strong><?=count($preview['valid'])?></strong></p><p>Data error/dilewati: <strong><?=count($preview['errors'])?></strong></p><?php if(!empty($preview['valid'])):?><form method="post"><?=csrf_field()?><input type="hidden" name="step" value="confirm"><button class="btn">Konfirmasi &amp; Import <?=count($preview['valid'])?> Peserta</button></form><?php endif;?><a href="voter_import.php?poll_id=<?=$currentPollId?>" class="btn secondary">Upload File Lain</a></div>
<?php if(!empty($preview['valid'])):?><div class="card"><h3 style="margin-top:0">Data Valid (<?=count($preview['valid'])?>)</h3><table><thead><tr><th>Nama</th><th>Kelas</th></tr></thead><tbody><?php foreach(array_slice($preview['valid'],0,200) as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['class_name'])?></td></tr><?php endforeach;?></tbody></table><?php if(count($preview['valid'])>200):?><p class="muted">Menampilkan 200 dari <?=count($preview['valid'])?> baris.</p><?php endif;?></div><?php endif;?>
<?php if(!empty($preview['errors'])):?><div class="card"><h3 style="margin-top:0">Data Error (<?=count($preview['errors'])?>)</h3><table><thead><tr><th>Baris</th><th>Nama</th><th>Kelas</th><th>Alasan</th></tr></thead><tbody><?php foreach(array_slice($preview['errors'],0,200) as $r):?><tr><td><?= (int)$r['line']?></td><td><?=e($r['name']??'')?></td><td><?=e($r['class_name']??'')?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
<?php endif;?>
<?php include __DIR__.'/includes/footer.php'; ?>
