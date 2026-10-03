<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/../nexadipta-21/includes/content.php';

$pdo = get_db();
try {
    ensure_nexadipta21_schema($pdo);
} catch (Throwable $ex) {
    error_log('ensure_nexadipta21_schema failed: ' . $ex->getMessage());
}
$participantUploadDir = __DIR__ . '/../uploads/angkatan';
$participantUploadPrefix = 'uploads/angkatan';
$action = $_GET['action'] ?? 'list';
$error = null;
// Kompatibel DB lama yang belum punya kolom poll_type
try {
    $polls = $pdo->query('SELECT id,title,status,poll_type FROM polls ORDER BY id DESC')->fetchAll();
} catch (Throwable $ex) {
    error_log('Polls query with poll_type failed, fallback: ' . $ex->getMessage());
    try {
        $polls = $pdo->query('SELECT id,title,status FROM polls ORDER BY id DESC')->fetchAll();
        foreach ($polls as &$pp) { $pp['poll_type'] = $pp['poll_type'] ?? 'single_choice'; }
        unset($pp);
    } catch (Throwable $ex2) {
        error_log('Polls query failed: ' . $ex2->getMessage());
        $polls = [];
        $error = 'Gagal memuat daftar polling.';
    }
}
$currentPollId = (int)($_GET['poll_id'] ?? ($_POST['poll_id'] ?? 0));
$currentPollType = 'single_choice';
foreach ($polls as $ppoll) { if ((int)$ppoll['id'] === $currentPollId) { $currentPollType = (string)($ppoll['poll_type'] ?? 'single_choice'); break; } }
$isQuestionnaire = ($currentPollType === 'questionnaire');
$hasSelectedPoll = $currentPollId > 0;

if (($action ?? '') === 'export_csv') {
    if ($currentPollId > 0) {
        $stmt=$pdo->prepare('SELECT title FROM polls WHERE id=? LIMIT 1');
        $stmt->execute([$currentPollId]);
        $pollTitle=(string)($stmt->fetchColumn()?:'polling');
        if ($isQuestionnaire) {
            $stmt=$pdo->prepare("SELECT p.name,c.name AS class_name,p.status,CASE WHEN EXISTS(SELECT 1 FROM poll_participations qp WHERE qp.poll_id=pp.poll_id AND qp.participant_id=p.id AND qp.status IN ('completed','terminated')) THEN 'Sudah Mengikuti' WHEN EXISTS(SELECT 1 FROM poll_participations qp WHERE qp.poll_id=pp.poll_id AND qp.participant_id=p.id AND qp.status='in_progress') THEN 'Sedang Berjalan' ELSE 'Belum Mengikuti' END AS vote_status FROM participants p JOIN classes c ON c.id=p.class_id JOIN poll_participants pp ON pp.participant_id=p.id WHERE pp.poll_id=? ORDER BY c.name,p.name");
        } else {
            $stmt=$pdo->prepare("SELECT p.name,c.name AS class_name,p.status,CASE WHEN EXISTS(SELECT 1 FROM votes v WHERE v.poll_id=pp.poll_id AND v.participant_id=p.id) THEN 'Sudah Vote' ELSE 'Belum Vote' END AS vote_status FROM participants p JOIN classes c ON c.id=p.class_id JOIN poll_participants pp ON pp.participant_id=p.id WHERE pp.poll_id=? ORDER BY c.name,p.name");
        }
        $stmt->execute([$currentPollId]); $rows=$stmt->fetchAll();
    } else {
        $pollTitle='semua_peserta';
        $rows=$pdo->query("SELECT p.name,c.name AS class_name,p.status,'-' AS vote_status FROM participants p JOIN classes c ON c.id=p.class_id ORDER BY c.name,p.name")->fetchAll();
    }
    $safe=preg_replace('/[^A-Za-z0-9_-]+/','_',$pollTitle)?:'peserta';
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="peserta_'.$safe.'.csv"');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Nama','Kelas','Status Peserta','Status Voting'],',','"','\\');
    foreach($rows as $r) fputcsv($out,[$r['name'],$r['class_name'],$r['status'],$r['vote_status']],',','"','\\');
    fclose($out); exit;
}

try {
    $classesAll = $pdo->query('SELECT id,name,status FROM classes ORDER BY name ASC')->fetchAll();
} catch (Throwable $ex) {
    error_log('Classes list failed: ' . $ex->getMessage());
    $classesAll = [];
    $error = $error ?: 'Gagal memuat daftar kelas.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add','edit'], true)) {
    csrf_verify();
    $name = trim((string)($_POST['name'] ?? ''));
    $classId = (int)($_POST['class_id'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $id = (int)($_POST['id'] ?? 0);
    $pollId = (int)($_POST['poll_id'] ?? 0);
    $newPhoto = '';
    $oldPhoto = '';

    if ($name === '' || $classId <= 0) {
        $error = 'Nama dan kelas wajib diisi.';
    } else {
        try {
            if (!empty($_FILES['photo']['name'])) {
                $newPhoto = nx21_upload_image($_FILES['photo'], $participantUploadDir, $participantUploadPrefix);
            }
        } catch (Throwable $uploadEx) {
            $error = $uploadEx->getMessage();
        }
        $classCheck = $pdo->prepare('SELECT status FROM classes WHERE id=? LIMIT 1');
        $classCheck->execute([$classId]);
        $classStatus = $classCheck->fetchColumn();
        if ($classStatus !== 'active') {
            $error = 'Kelas yang dipilih harus berstatus Active.';
        }
        if ($error === null) try {
            $pdo->beginTransaction();
            $normalized = normalize_text($name);
            if ($action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO participants (name, normalized_name, class_id, photo_path, status) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name,$normalized,$classId,$newPhoto ?: null,$status]);
                $id = (int)db_last_insert_id($pdo);
                $msg = 'Peserta berhasil ditambahkan.';
            } else {
                $oldStmt = $pdo->prepare('SELECT name, class_id, photo_path FROM participants WHERE id=? LIMIT 1');
                $oldStmt->execute([$id]);
                $oldParticipant = $oldStmt->fetch();
                $oldPhoto = (string)($oldParticipant['photo_path'] ?? '');
                $voteCheck = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE participant_id=?');
                $voteCheck->execute([$id]);
                $hasVotes = (int)$voteCheck->fetchColumn() > 0;
                if ($hasVotes) {
                    $oldStmt = $pdo->prepare('SELECT name, class_id, photo_path FROM participants WHERE id=? LIMIT 1');
                    $oldStmt->execute([$id]);
                    $old = $oldStmt->fetch();
                    if ($old && ($old['name'] !== $name || (int)$old['class_id'] !== $classId)) {
                        throw new RuntimeException('Peserta yang sudah memiliki vote tidak boleh mengganti nama atau kelas. Ubah status saja atau reset vote terlebih dahulu.');
                    }
                }
                $removePhoto = !empty($_POST['remove_photo']);
                if ($newPhoto !== '') {
                    $stmt = $pdo->prepare('UPDATE participants SET name=?, normalized_name=?, class_id=?, photo_path=?, status=? WHERE id=?');
                    $stmt->execute([$name,$normalized,$classId,$newPhoto,$status,$id]);
                } elseif ($removePhoto) {
                    $stmt = $pdo->prepare('UPDATE participants SET name=?, normalized_name=?, class_id=?, photo_path=NULL, status=? WHERE id=?');
                    $stmt->execute([$name,$normalized,$classId,$status,$id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE participants SET name=?, normalized_name=?, class_id=?, status=? WHERE id=?');
                    $stmt->execute([$name,$normalized,$classId,$status,$id]);
                }
                $msg = 'Peserta berhasil diperbarui.';
            }
            if ($pollId > 0) {
                $stmt = $pdo->prepare('INSERT INTO poll_participants (poll_id, participant_id, status) VALUES (?, ?, \'active\') ON CONFLICT (poll_id, participant_id) DO UPDATE SET status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP');
                $stmt->execute([$pollId,$id]);
            }
            $pdo->commit();
            if ($action === 'edit' && $oldPhoto && (($newPhoto !== '') || !empty($_POST['remove_photo']))) {
                nx21_delete_upload($oldPhoto);
            }
            admin_audit($action === 'add' ? 'participant.create' : 'participant.update', 'participant', $id, $msg);
            flash_set('success',$msg.' Terdaftar pada polling yang dipilih.');
            redirect('voters.php?poll_id='.$pollId);
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Participant save failed: '.$ex->getMessage());
            $error = 'Peserta gagal disimpan. Pastikan data valid dan tidak duplikat.';
        }
    }
}


if ($action === 'delete_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $countAll = (int)$pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
    try {
        // Sengaja tidak memblokir berdasarkan histori vote/jawaban. Foreign key
        // peserta menggunakan ON DELETE CASCADE sehingga seluruh histori yang
        // melekat pada peserta ikut dibersihkan dalam transaksi yang sama.
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM participants');
        $pdo->commit();
        admin_audit('participant.delete_all','participant',null,'Semua peserta dan histori terkait dihapus ('.$countAll.' data)');
        flash_set('success','Semua peserta berhasil dihapus beserta histori vote/jawabannya.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete all participants failed: '.$ex->getMessage());
        flash_set('error','Semua peserta gagal dihapus. Tidak ada data yang diubah.');
    }
    redirect('voters.php'.($currentPollId > 0 ? '?poll_id='.$currentPollId : ''));
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE participant_id=?'); $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        flash_set('error','Peserta tidak dapat dihapus karena sudah memiliki vote. Gunakan reset per polling terlebih dahulu.');
    } else {
        $pdo->prepare('DELETE FROM poll_participants WHERE participant_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM participants WHERE id=?')->execute([$id]);
        admin_audit('participant.delete', 'participant', $id, 'Peserta dihapus');
        flash_set('success','Peserta berhasil dihapus.');
    }
    redirect('voters.php'.($currentPollId > 0 ? '?poll_id='.$currentPollId : ''));
}

if ($action === 'reset_one' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    if ($currentPollId <= 0) {
        flash_set('error','Polling belum dipilih.');
    } else {
        try {
            $pdo->beginTransaction();
            if ($isQuestionnaire) {
                $a=$pdo->prepare('DELETE FROM poll_answers WHERE participant_id=? AND poll_id=?'); $a->execute([$id,$currentPollId]);
                $q=$pdo->prepare('DELETE FROM poll_participations WHERE participant_id=? AND poll_id=?'); $q->execute([$id,$currentPollId]);
                $changed=$a->rowCount()+$q->rowCount();
                if($changed) admin_audit('questionnaire.reset_one','poll',$currentPollId,'Reset sesi questionnaire peserta '.$id);
                flash_set('success',$changed>0 ? 'Sesi questionnaire peserta berhasil direset untuk polling ini.' : 'Peserta belum memiliki sesi pada polling ini.');
            } else {
                $stmt=$pdo->prepare('DELETE FROM votes WHERE participant_id=? AND poll_id=?');
                $stmt->execute([$id,$currentPollId]);
                $changed=$stmt->rowCount();
                if($changed){ $legacy=$pdo->prepare('UPDATE participants p SET has_voted=CASE WHEN EXISTS(SELECT 1 FROM votes v WHERE v.participant_id=p.id) THEN 1 ELSE 0 END WHERE p.id=?'); $legacy->execute([$id]); }
                if($changed) admin_audit('vote.reset_one','vote',$id,'Reset vote peserta pada polling '.$currentPollId);
                flash_set('success',$changed>0 ? 'Vote peserta berhasil direset untuk polling ini.' : 'Peserta belum memiliki vote pada polling ini.');
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if($pdo->inTransaction()) $pdo->rollBack();
            error_log('Reset participant failed: '.$ex->getMessage());
            flash_set('error','Reset peserta gagal diproses.');
        }
    }
    redirect('voters.php'.($currentPollId > 0 ? '?poll_id='.$currentPollId : ''));
}

// Bulk: daftarkan peserta terpilih / semua ke polling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['assign_selected', 'assign_all'], true)) {
    csrf_verify();
    $pollId = (int)($_POST['poll_id'] ?? 0);
    if ($pollId <= 0) {
        flash_set('error', 'Pilih polling terlebih dahulu sebelum mendaftarkan peserta.');
        redirect('voters.php');
    }
    $pollExists = $pdo->prepare('SELECT id FROM polls WHERE id=? LIMIT 1');
    $pollExists->execute([$pollId]);
    if (!$pollExists->fetchColumn()) {
        flash_set('error', 'Polling tidak ditemukan.');
        redirect('voters.php');
    }

    $ids = [];
    if ($action === 'assign_selected') {
        $raw = $_POST['participant_ids'] ?? [];
        if (!is_array($raw)) $raw = [];
        foreach ($raw as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) $ids[] = $rid;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            flash_set('error', 'Centang minimal satu peserta untuk didaftarkan ke polling.');
            redirect('voters.php?poll_id=' . $pollId);
        }
    } else {
        // assign_all: semua peserta aktif (opsional filter kelas dari form)
        $classFilter = (int)($_POST['class_id'] ?? 0);
        if ($classFilter > 0) {
            $stmt = $pdo->prepare("SELECT id FROM participants WHERE status='active' AND class_id=?");
            $stmt->execute([$classFilter]);
        } else {
            $stmt = $pdo->query("SELECT id FROM participants WHERE status='active'");
        }
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            flash_set('error', 'Tidak ada peserta aktif yang dapat didaftarkan.');
            redirect('voters.php?poll_id=' . $pollId);
        }
    }

    $ins = $pdo->prepare('INSERT INTO poll_participants (poll_id, participant_id, status) VALUES (?, ?, \'active\') ON CONFLICT (poll_id, participant_id) DO UPDATE SET status=EXCLUDED.status, updated_at=CURRENT_TIMESTAMP');
    $count = 0;
    $newCount = 0;
    try {
        $pdo->beginTransaction();
        $check = $pdo->prepare('SELECT 1 FROM poll_participants WHERE poll_id=? AND participant_id=? LIMIT 1');
        foreach ($ids as $pid) {
            $check->execute([$pollId, $pid]);
            $existed = (bool)$check->fetchColumn();
            $ins->execute([$pollId, $pid]);
            $count++;
            if (!$existed) $newCount++;
        }
        $pdo->commit();
        if ($newCount === 0 && $count > 0) {
            flash_set('success', 'Semua peserta yang dipilih sudah terdaftar di polling ini (' . $count . ').');
        } elseif ($count > $newCount) {
            flash_set('success', $newCount . ' peserta baru didaftarkan ke polling (' . ($count - $newCount) . ' sudah terdaftar sebelumnya).');
        } else {
            flash_set('success', $newCount . ' peserta berhasil didaftarkan ke polling.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('assign participants failed: ' . $ex->getMessage());
        flash_set('error', 'Gagal mendaftarkan peserta ke polling.');
    }
    redirect('voters.php?poll_id=' . $pollId);
}

$editRow = null;
if ($action === 'edit') {
    $id=(int)($_GET['id']??0); $stmt=$pdo->prepare('SELECT * FROM participants WHERE id=?'); $stmt->execute([$id]); $editRow=$stmt->fetch();
}

$search=trim((string)($_GET['q']??'')); $filterClass=(int)($_GET['class_id']??0); $filterStatus=$_GET['status']??''; $filterVoted=$_GET['voted']??'';
$where=[]; $params=[];
if($search!==''){ $where[]='p.name LIKE ?'; $params[]='%'.$search.'%'; }
if($filterClass>0){$where[]='p.class_id=?';$params[]=$filterClass;}
if(in_array($filterStatus,['active','inactive'],true)){$where[]='p.status=?';$params[]=$filterStatus;}
$joinPoll='';
$linkedExpr='0';
if($hasSelectedPoll){
    $joinPoll=' LEFT JOIN poll_participants pp ON pp.participant_id=p.id AND pp.poll_id=?';
    $linkedExpr='CASE WHEN pp.id IS NULL THEN 0 ELSE 1 END';
    array_unshift($params,$currentPollId);
    if($filterVoted==='voted'){
        if($isQuestionnaire){$where[]='EXISTS (SELECT 1 FROM poll_participations qv WHERE qv.poll_id=? AND qv.participant_id=p.id AND qv.status IN (\'completed\',\'terminated\'))';}
        else{$where[]='EXISTS (SELECT 1 FROM votes vx WHERE vx.poll_id=? AND vx.participant_id=p.id)';}
        $params[]=$currentPollId;
    } elseif($filterVoted==='notvoted'){
        if($isQuestionnaire){$where[]='NOT EXISTS (SELECT 1 FROM poll_participations qv WHERE qv.poll_id=? AND qv.participant_id=p.id AND qv.status IN (\'completed\',\'terminated\'))';}
        else{$where[]='NOT EXISTS (SELECT 1 FROM votes vx WHERE vx.poll_id=? AND vx.participant_id=p.id)';}
        $params[]=$currentPollId;
    }
    if($isQuestionnaire){$statusExpr="CASE WHEN EXISTS(SELECT 1 FROM poll_participations qv WHERE qv.poll_id=".(int)$currentPollId." AND qv.participant_id=p.id AND qv.status IN ('completed','terminated')) THEN 1 WHEN EXISTS(SELECT 1 FROM poll_participations qv WHERE qv.poll_id=".(int)$currentPollId." AND qv.participant_id=p.id AND qv.status='in_progress') THEN 2 ELSE 0 END";}
    else{$statusExpr="CASE WHEN EXISTS(SELECT 1 FROM votes v WHERE v.poll_id=".(int)$currentPollId." AND v.participant_id=p.id) THEN 1 ELSE 0 END";}
} else {
    $statusExpr='0';
}
$whereSql=$where?'WHERE '.implode(' AND ',$where):'';
$sql="SELECT p.*, c.name AS class_name, ($statusExpr) AS voted_this_poll, ($linkedExpr) AS linked_to_poll FROM participants p JOIN classes c ON c.id=p.class_id $joinPoll $whereSql ORDER BY p.created_at DESC LIMIT 500";
try {
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $participants=$stmt->fetchAll();
} catch (Throwable $ex) {
    error_log('Participants list failed: '.$ex->getMessage().' | SQL: '.$sql);
    $participants=[];
    $error = $error ?: 'Gagal memuat daftar peserta. Periksa koneksi database atau struktur tabel.';
}

$active_menu='voters';$page_title='Data Peserta';include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Data Peserta</h1><div><form method="post" action="voters.php?action=delete_all" style="display:inline" onsubmit="return confirm('PERINGATAN: Hapus SEMUA peserta? Semua histori vote dan jawaban yang terkait peserta juga akan dihapus. Tindakan ini tidak dapat dibatalkan. Lanjutkan?');"><?=csrf_field()?> <button class="btn small danger" type="submit">Hapus Semua Peserta</button></form> <a href="voters.php?action=export_csv<?= $currentPollId?'&amp;poll_id='.$currentPollId:'' ?>" class="btn small secondary">Export CSV</a> <a href="voter_import.php<?= $currentPollId?'?poll_id='.$currentPollId:'' ?>" class="btn small secondary">Import CSV</a></div></div>
<?php if($msg=flash_get('success')):?><div class="alert success"><?=e($msg)?></div><?php endif;?>
<?php if($msg=flash_get('error')):?><div class="alert error"><?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>

<div class="card"><div class="filter-bar"><div><strong>Data Peserta adalah data utama.</strong><div class="muted">Polling bersifat opsional. Kamu tetap bisa menambah, melihat, mengedit, menghapus, import, dan export peserta walaupun belum ada polling.</div></div><?php if($hasSelectedPoll):?><a class="btn small secondary" href="voters.php">Tampilkan Semua Peserta</a><?php endif;?></div><?php if($polls):?><form method="get" class="filter-bar"><label style="margin:0">Lihat status polling (opsional)</label><select name="poll_id" onchange="this.form.submit()"><option value="0">— Tanpa memilih polling —</option><?php foreach($polls as $p):?><option value="<?= (int)$p['id']?>" <?= $currentPollId===(int)$p['id']?'selected':''?>><?=e($p['title'])?> — <?=e(ucfirst($p['status']))?></option><?php endforeach;?></select></form><?php endif;?></div>

<div class="card" style="max-width:620px;"><h3 style="margin-top:0"><?= $editRow?'Edit Peserta':'Tambah Peserta'?></h3>
<form method="post" enctype="multipart/form-data" action="voters.php?action=<?= $editRow?'edit':'add' ?>"><input type="hidden" name="poll_id" value="<?= $currentPollId?>"><?=csrf_field()?><?php if($editRow):?><input type="hidden" name="id" value="<?= (int)$editRow['id']?>"><?php endif;?>
<label>Nama Lengkap</label><input type="text" name="name" required maxlength="150" value="<?=e($editRow['name']??'')?>" placeholder="Nama lengkap peserta">
<label>Kelas</label><select name="class_id" required><option value="">-- Pilih Kelas --</option><?php foreach($classesAll as $c):?><option value="<?= (int)$c['id']?>" <?=($editRow&&(int)$editRow['class_id']===(int)$c['id'])?'selected':''?>><?=e($c['name'])?><?= $c['status']!=='active'?' (Inactive)':''?></option><?php endforeach;?></select>
<label>Foto Anggota</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><?php if(!empty($editRow['photo_path'])):?><div style="margin:8px 0"><img src="../<?=e($editRow['photo_path'])?>" alt="Foto peserta" style="width:80px;height:80px;object-fit:cover;border-radius:12px"><br><label><input type="checkbox" name="remove_photo" value="1"> Hapus foto</label></div><?php endif;?>
<label>Status Peserta</label><select name="status"><option value=\'active\' <?=(!$editRow||$editRow['status']==='active')?'selected':''?>>Active</option><option value=\'inactive\' <?=($editRow&&$editRow['status']==='inactive')?'selected':''?>>Inactive</option></select>
<?php if($hasSelectedPoll):?><p class="muted">Peserta ini akan tetap dapat dikelola sebagai data utama dan juga didaftarkan ke polling yang dipilih jika belum terdaftar.</p><?php else:?><p class="muted">Tidak ada polling yang dipilih. Peserta akan disimpan sebagai data utama dan bisa didaftarkan ke polling kapan saja.</p><?php endif;?><button type="submit" class="btn"><?= $editRow?'Simpan Perubahan':'Tambah Peserta'?></button><?php if($editRow):?><a href="voters.php<?= $currentPollId?'?poll_id='.$currentPollId:'' ?>" class="btn secondary">Batal</a><?php endif;?></form></div>

<div class="card">
<form method="get" class="filter-bar"><input type="hidden" name="poll_id" value="<?=$currentPollId?>"><input type="search" name="q" value="<?=e($search)?>" placeholder="Cari nama..."><select name="class_id"><option value="0">Semua Kelas</option><?php foreach($classesAll as $c):?><option value="<?=(int)$c['id']?>" <?=$filterClass===(int)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select><select name="status"><option value="">Semua Status</option><option value=\'active\' <?=$filterStatus==='active'?'selected':''?>>Active</option><option value=\'inactive\' <?=$filterStatus==='inactive'?'selected':''?>>Inactive</option></select><?php if($hasSelectedPoll):?><select name="voted"><option value="">Semua Status Polling</option><option value="voted" <?=$filterVoted==='voted'?'selected':''?>><?= $isQuestionnaire ? 'Sudah Mengikuti' : 'Sudah Vote' ?></option><option value="notvoted" <?=$filterVoted==='notvoted'?'selected':''?>><?= $isQuestionnaire ? 'Belum Mengikuti' : 'Belum Vote' ?></option></select><?php endif;?><button class="btn small block-auto">Filter</button><a href="voters.php<?= $currentPollId?'?poll_id='.$currentPollId:'' ?>" class="btn small secondary block-auto">Reset</a></form>

<?php if($hasSelectedPoll): ?>
<div class="filter-bar" style="margin:10px 0 12px;gap:8px;flex-wrap:wrap;align-items:center">
  <strong>Daftarkan ke polling</strong>
  <form method="post" id="bulk-assign-selected" action="voters.php?action=assign_selected" style="display:inline">
    <?=csrf_field()?>
    <input type="hidden" name="poll_id" value="<?=$currentPollId?>">
    <div id="bulk-selected-ids"></div>
    <button type="submit" class="btn small" id="btn-assign-selected">Tambah yang dicentang</button>
  </form>
  <form method="post" id="bulk-assign-all" action="voters.php?action=assign_all" style="display:inline">
    <?=csrf_field()?>
    <input type="hidden" name="poll_id" value="<?=$currentPollId?>">
    <button type="submit" class="btn small secondary" id="btn-assign-all">Tambah semua siswa aktif</button>
  </form>
  <span class="muted" style="font-size:13px">Centang baris, atau daftarkan semua peserta Active.</span>
</div>
<script>
(function(){
  function confirmAsync(msg){
    if (window.uiConfirm) return window.uiConfirm(msg);
    return Promise.resolve(window.confirm(msg));
  }
  function alertAsync(msg){
    if (window.uiAlert) return window.uiAlert(msg);
    window.alert(msg); return Promise.resolve();
  }
  var formSel = document.getElementById('bulk-assign-selected');
  if (formSel) {
    formSel.addEventListener('submit', function(e){
      e.preventDefault();
      var box = document.getElementById('bulk-selected-ids');
      box.innerHTML = '';
      var checks = document.querySelectorAll('.participant-check:checked');
      if (!checks.length) {
        alertAsync('Centang minimal satu peserta.');
        return;
      }
      confirmAsync('Daftarkan ' + checks.length + ' peserta yang dicentang ke polling ini?').then(function(ok){
        if (!ok) return;
        checks.forEach(function(c){
          var inp = document.createElement('input');
          inp.type = 'hidden'; inp.name = 'participant_ids[]'; inp.value = c.value;
          box.appendChild(inp);
        });
        formSel.submit();
      });
    });
  }
  var formAll = document.getElementById('bulk-assign-all');
  if (formAll) {
    formAll.addEventListener('submit', function(e){
      e.preventDefault();
      confirmAsync('Daftarkan SEMUA peserta aktif ke polling ini?').then(function(ok){
        if (ok) formAll.submit();
      });
    });
  }
  document.addEventListener('DOMContentLoaded', function(){
    var all = document.getElementById('check-all-participants');
    if (!all) return;
    all.addEventListener('change', function(){
      document.querySelectorAll('.participant-check').forEach(function(c){ c.checked = all.checked; });
    });
  });
})();
</script>
<?php endif; ?>

<table><thead><tr>
<?php if($hasSelectedPoll):?><th style="width:36px"><input type="checkbox" id="check-all-participants" title="Pilih semua"></th><?php endif;?>
<th>Foto</th><th>Nama</th><th>Kelas</th><th>Status</th><?php if($hasSelectedPoll):?><th>Status Polling</th><?php endif;?><th>Aksi</th></tr></thead><tbody>
<?php foreach($participants as $p):?><tr>
<?php if($hasSelectedPoll):?><td><input type="checkbox" value="<?=(int)$p['id']?>" class="participant-check"></td><?php endif;?>
<td><?php if(!empty($p['photo_path'])):?><img src="../<?=e($p['photo_path'])?>" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:9px"><?php else:?><span class="muted">—</span><?php endif;?></td>
<td><?=e($p['name'])?></td>
<td><?=e($p['class_name'])?></td>
<td><span class="badge <?=e($p['status'])?>"><?=e(ucfirst($p['status']))?></span></td>
<?php if($hasSelectedPoll):?><td><?php
  $linked = (int)($p['linked_to_poll'] ?? 0) === 1;
  $vstat = (int)($p['voted_this_poll'] ?? 0);
  if (!$linked): ?>
    <span class="badge notvoted">Belum terdaftar</span>
  <?php else:
    if ($isQuestionnaire) {
      $label = $vstat === 1 ? 'Sudah Mengikuti' : ($vstat === 2 ? 'Sedang Berjalan' : 'Terdaftar · Belum');
      $cls = $vstat === 1 ? 'voted' : ($vstat === 2 ? '' : 'notvoted');
    } else {
      $label = $vstat === 1 ? 'Sudah Vote' : 'Terdaftar · Belum Vote';
      $cls = $vstat === 1 ? 'voted' : 'notvoted';
    }
  ?><span class="badge <?= e($cls) ?>"><?= e($label) ?></span><?php endif; ?></td><?php endif;?>
<td>
  <a href="voters.php?action=edit&id=<?=(int)$p['id']?><?= $currentPollId?'&amp;poll_id='.$currentPollId:'' ?>" class="btn small secondary">Edit</a>
  <?php if($hasSelectedPoll && (int)$p['voted_this_poll']===1):?>
  <form method="post" action="voters.php?action=reset_one" style="display:inline" onsubmit="return confirm('Reset status/jawaban peserta ini hanya untuk polling ini?');"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$p['id']?>"><input type="hidden" name="poll_id" value="<?=$currentPollId?>"><button class="btn small secondary">Reset Vote</button></form>
  <?php endif;?>
  <form method="post" action="voters.php?action=delete" style="display:inline" onsubmit="return confirm('Hapus peserta ini? Hanya peserta tanpa vote yang dapat dihapus.');"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$p['id']?>"><input type="hidden" name="poll_id" value="<?=$currentPollId?>"><button class="btn small danger">Hapus</button></form>
</td>
</tr><?php endforeach;?>
<?php if(empty($participants)):?><tr><td colspan="<?= $hasSelectedPoll?7:5 ?>" class="muted">Belum ada data peserta.</td></tr><?php endif;?>
</tbody></table>
<p class="muted" style="margin-top:10px">Menampilkan maksimal 500 peserta. Pilih polling di atas, lalu daftarkan peserta (centang atau semua aktif). Status berubah menjadi <strong>Terdaftar · Belum Vote</strong> setelah berhasil didaftarkan.</p>
</div>

<?php if($hasSelectedPoll): ?>
<div class="card"><h3 style="margin-top:0"><?= $isQuestionnaire ? 'Reset Sesi Per Polling' : 'Reset Vote Per Polling' ?></h3><p class="muted"><?= $isQuestionnaire ? 'Reset menghapus jawaban dan status sesi pada questionnaire yang dipilih. Polling lain tetap aman.' : 'Reset hanya menghapus vote pada polling yang sedang dipilih. Polling lain tetap aman.' ?></p><a href="voter_reset.php?poll_id=<?=$currentPollId?>" class="btn danger block-auto">Reset Semua Vote Polling Ini</a></div>
<?php endif; ?>
<?php include __DIR__.'/includes/footer.php'; ?>
