<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pdo = get_db();
$action = $_GET['action'] ?? 'list';
$error = null;

// ---------- Tambah / Edit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
    csrf_verify();
    $name = trim((string)($_POST['name'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $id = (int)($_POST['id'] ?? 0);

    if ($name === '') {
        $error = 'Nama kelas wajib diisi.';
    } else {
        $normalized = normalize_text($name);
        try {
            if ($action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO classes (name, normalized_name, status) VALUES (?, ?, ?)');
                $stmt->execute([$name, $normalized, $status]);
                admin_audit('class.create','class',(int)db_last_insert_id($pdo),'Kelas ditambahkan: '.$name);
                flash_set('success', 'Kelas berhasil ditambahkan.');
            } else {
                $stmt = $pdo->prepare('UPDATE classes SET name = ?, normalized_name = ?, status = ? WHERE id = ?');
                $stmt->execute([$name, $normalized, $status, $id]);
                admin_audit('class.update','class',$id,'Kelas diperbarui: '.$name);
                flash_set('success', 'Kelas berhasil diperbarui.');
            }
            redirect('classes.php');
        } catch (PDOException $ex) {
            $error = 'Nama kelas sudah ada, gunakan nama lain.';
        }
    }
}


if ($action === 'delete_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $countAll = (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
    $countParticipants = (int)$pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
    try {
        // Karena participants.class_id memakai ON DELETE RESTRICT, semua peserta
        // harus dibersihkan terlebih dahulu. Ini sengaja menjadi aksi "bersihkan
        // semua": peserta, histori vote/jawaban, lalu seluruh kelas.
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM participants');
        $pdo->exec('DELETE FROM classes');
        $pdo->commit();
        admin_audit('class.delete_all','class',null,'Semua kelas dan peserta terkait dihapus ('.$countAll.' kelas, '.$countParticipants.' peserta)');
        flash_set('success','Semua kelas berhasil dihapus beserta seluruh peserta dan histori terkait.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete all classes failed: '.$ex->getMessage());
        flash_set('error','Semua kelas gagal dihapus. Tidak ada data yang diubah.');
    }
    redirect('classes.php');
}

// ---------- Hapus ----------
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $check = $pdo->prepare('SELECT COUNT(*) c FROM participants WHERE class_id = ?');
    $check->execute([$id]);
    if ((int)$check->fetch()['c'] > 0) {
        flash_set('error', 'Kelas tidak dapat dihapus karena masih memiliki peserta. Nonaktifkan saja kelas ini.');
    } else {
        $del = $pdo->prepare('DELETE FROM classes WHERE id = ?');
        $del->execute([$id]);
        admin_audit('class.delete','class',$id,'Kelas dihapus');
        flash_set('success', 'Kelas berhasil dihapus.');
    }
    redirect('classes.php');
}

$editRow = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM classes WHERE id = ?');
    $stmt->execute([$id]);
    $editRow = $stmt->fetch();
}

$classes = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM participants p WHERE p.class_id = c.id) AS participant_count FROM classes c ORDER BY c.name ASC')->fetchAll();

$active_menu = 'classes';
$page_title = 'Kelola Kelas';
include __DIR__ . '/includes/header.php';
?>
<div class="admin-topbar"><h1>Kelola Kelas</h1><form method="post" action="classes.php?action=delete_all" onsubmit="return confirm('PERINGATAN: Hapus SEMUA kelas? Semua peserta, histori vote, dan jawaban terkait juga akan dihapus karena peserta bergantung pada kelas. Tindakan ini tidak dapat dibatalkan. Lanjutkan?');"><?=csrf_field()?> <button type="submit" class="btn small danger">Hapus Semua Kelas</button></form></div>

<?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:420px;">
  <h3 style="margin-top:0;"><?= $editRow ? 'Edit Kelas' : 'Tambah Kelas' ?></h3>
  <form method="post" action="classes.php?action=<?= $editRow ? 'edit' : 'add' ?>">
    <?= csrf_field() ?>
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <label>Nama Kelas</label>
    <input type="text" name="name" required maxlength="100" value="<?= e($editRow['name'] ?? '') ?>" placeholder="Contoh: XII IPA 1">
    <label>Status</label>
    <select name="status">
      <option value=\'active\' <?= (!$editRow || $editRow['status'] === 'active') ? 'selected' : '' ?>>Active</option>
      <option value=\'inactive\' <?= ($editRow && $editRow['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
    </select>
    <button type="submit" class="btn"><?= $editRow ? 'Simpan Perubahan' : 'Tambah Kelas' ?></button>
    <?php if ($editRow): ?><a href="classes.php" class="btn secondary">Batal</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <table>
    <thead><tr><th>Nama Kelas</th><th>Jumlah Peserta</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($classes as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= (int)$c['participant_count'] ?></td>
        <td><span class="badge <?= $c['status'] ?>"><?= $c['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
        <td>
          <a href="classes.php?action=edit&id=<?= (int)$c['id'] ?>" class="btn small secondary">Edit</a>
          <form method="post" action="classes.php?action=delete" style="display:inline" onsubmit="return confirm('Hapus kelas ini?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn small danger">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($classes)): ?><tr><td colspan="4" class="muted">Belum ada kelas.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
