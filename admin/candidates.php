<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pdo = get_db();
$action = $_GET['action'] ?? 'list';
$error = null;

$polls = $pdo->query('SELECT id, title, status FROM polls ORDER BY id DESC')->fetchAll();
$currentPollId = (int)($_GET['poll_id'] ?? ($polls[0]['id'] ?? 0));

/** Batas jumlah foto per sekali upload massal (bukan total di database). */
const CANDIDATE_BULK_MAX = 50;

/**
 * Sisipkan nomor urut: semua foto pada polling yang sort_order >= $desired
 * digeser +1, sehingga slot $desired kosong untuk foto baru / yang dipindah.
 * $excludeId: id foto yang sedang diedit (tidak digeser).
 */
function candidates_make_room_for_sort(PDO $pdo, int $pollId, int $desired, ?int $excludeId = null): void
{
    if ($pollId <= 0) {
        return;
    }
    // Izinkan 0; nilai negatif dinormalisasi ke 0
    if ($desired < 0) {
        $desired = 0;
    }
    if ($excludeId !== null && $excludeId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE candidates SET sort_order = sort_order + 1
             WHERE poll_id = ? AND sort_order >= ? AND id <> ?'
        );
        $stmt->execute([$pollId, $desired, $excludeId]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE candidates SET sort_order = sort_order + 1
             WHERE poll_id = ? AND sort_order >= ?'
        );
        $stmt->execute([$pollId, $desired]);
    }
}


function candidate_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran foto melebihi batas server. Naikkan upload_max_filesize / post_max_size di hosting.',
        UPLOAD_ERR_PARTIAL => 'Upload terputus (partial). Coba lagi.',
        UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file (izin disk/folder).',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
        default => 'Upload foto gagal (kode ' . $code . ').',
    };
}

function handle_candidate_upload(?array $file, string $description = ''): ?string
{
    if (empty($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(candidate_upload_error_message((int)$file['error']));
    }
    if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('Ukuran foto maksimal ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.');
    }
    if (($file['size'] ?? 0) <= 0) {
        throw new RuntimeException('File foto kosong atau rusak.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP (terdeteksi: ' . ($mime ?: 'unknown') . ').');
    }
    $ext = $allowed[$mime];
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Folder upload kandidat tidak dapat dibuat.');
    }
    $dest = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Gagal menyimpan foto ke server. Periksa izin folder uploads/candidates.');
    }
    return UPLOAD_URL . $filename;
}

/**
 * Normalisasi $_FILES multi-upload menjadi daftar file per indeks.
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function normalize_multi_files(array $filesField): array
{
    $out = [];
    if (!isset($filesField['name']) || !is_array($filesField['name'])) {
        // single
        if (!empty($filesField['name'])) {
            $out[] = [
                'name' => (string)$filesField['name'],
                'type' => (string)($filesField['type'] ?? ''),
                'tmp_name' => (string)($filesField['tmp_name'] ?? ''),
                'error' => (int)($filesField['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($filesField['size'] ?? 0),
            ];
        }
        return $out;
    }
    $n = count($filesField['name']);
    for ($i = 0; $i < $n; $i++) {
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => (string)$filesField['name'][$i],
            'type' => (string)($filesField['type'][$i] ?? ''),
            'tmp_name' => (string)($filesField['tmp_name'][$i] ?? ''),
            'error' => (int)($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($filesField['size'][$i] ?? 0),
        ];
    }
    return $out;
}

// ---------- Upload massal (banyak foto sekaligus) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_add') {
    csrf_verify();
    $pollId = (int)($_POST['poll_id'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $baseSort = (int)($_POST['sort_order'] ?? 0);

    if ($pollId <= 0) {
        $error = 'Pilih polling tujuan terlebih dahulu.';
    } else {
        $pollCheck = $pdo->prepare('SELECT COUNT(*) FROM polls WHERE id=?');
        $pollCheck->execute([$pollId]);
        if ((int)$pollCheck->fetchColumn() !== 1) {
            $error = 'Polling tujuan tidak ditemukan.';
        } else {
            $files = normalize_multi_files($_FILES['images'] ?? []);
            if (!$files) {
                $error = 'Pilih minimal satu foto untuk diupload.';
            } elseif (count($files) > CANDIDATE_BULK_MAX) {
                $error = 'Maksimal ' . CANDIDATE_BULK_MAX . ' foto per sekali upload. Bagi menjadi beberapa batch.';
            } else {
                $ok = 0;
                $fail = 0;
                $errors = [];
                $stmt = $pdo->prepare('INSERT INTO candidates (poll_id, name, image_url, description, status, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($files as $idx => $file) {
                    $origName = (string)($file['name'] ?? 'foto');
                    $label = pathinfo($origName, PATHINFO_FILENAME);
                    $label = trim(preg_replace('/[_\-]+/', ' ', $label) ?? $label);
                    if ($label === '') {
                        $label = 'Foto ' . ($idx + 1);
                    }
                    if (mb_strlen($label) > 150) {
                        $label = mb_substr($label, 0, 150);
                    }
                    try {
                        $imageUrl = handle_candidate_upload($file);
                        if (!$imageUrl) {
                            throw new RuntimeException('File tidak terbaca.');
                        }
                        $thisSort = $baseSort + $idx;
                        candidates_make_room_for_sort($pdo, $pollId, $thisSort, null);
                        $stmt->execute([$pollId, $label, $imageUrl, '', $status, $thisSort]);
                        $ok++;
                    } catch (Throwable $ex) {
                        $fail++;
                        if (count($errors) < 8) {
                            $errors[] = $origName . ': ' . $ex->getMessage();
                        }
                    }
                }
                if ($ok > 0) {
                    admin_audit('candidate.bulk_create', 'poll', $pollId, $ok . ' foto ditambahkan via upload massal');
                    $msg = $ok . ' foto berhasil ditambahkan.';
                    if ($fail > 0) {
                        $msg .= ' ' . $fail . ' gagal.';
                        if ($errors) {
                            $msg .= ' Detail: ' . implode(' | ', $errors);
                        }
                    }
                    flash_set($fail > 0 ? 'error' : 'success', $msg);
                    // show partial failures as error flash but still redirect with success count
                    if ($fail > 0) {
                        flash_set('success', $ok . ' foto berhasil ditambahkan.');
                        flash_set('error', $fail . ' foto gagal. ' . implode(' | ', $errors));
                    }
                    redirect('candidates.php?poll_id=' . $pollId);
                } else {
                    $error = 'Semua upload gagal. ' . implode(' | ', $errors);
                    if (empty($errors)) {
                        $error = 'Semua upload gagal. Periksa batas max_file_uploads / post_max_size di hosting (cPanel → MultiPHP INI Editor).';
                    }
                }
            }
        }
    }
    $currentPollId = $pollId > 0 ? $pollId : $currentPollId;
}

// ---------- Tambah / Edit tunggal ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'], true)) {
    csrf_verify();
    $name = trim((string)($_POST['name'] ?? ''));
    $pollId = (int)($_POST['poll_id'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $id = (int)($_POST['id'] ?? 0);

    if ($name === '' || $pollId <= 0) {
        $error = 'Nama foto dan polling wajib diisi.';
    } else {
        try {
            $imageUrl = handle_candidate_upload($_FILES['image'] ?? null);

            if ($action === 'add') {
                if (!$imageUrl) {
                    $error = 'Foto wajib diupload untuk kandidat baru.';
                } else {
                    candidates_make_room_for_sort($pdo, $pollId, $sortOrder, null);
                    $stmt = $pdo->prepare('INSERT INTO candidates (poll_id, name, image_url, description, status, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$pollId, $name, $imageUrl, $description, $status, $sortOrder]);
                    admin_audit('candidate.create', 'candidate', (int)db_last_insert_id($pdo), $name);
                    flash_set('success', 'Foto kandidat berhasil ditambahkan.');
                    redirect('candidates.php?poll_id=' . $pollId);
                }
            } else {
                $oldStmt = $pdo->prepare('SELECT poll_id FROM candidates WHERE id=? LIMIT 1');
                $oldStmt->execute([$id]);
                $oldPollId = (int)($oldStmt->fetchColumn() ?: 0);
                if ($oldPollId <= 0) {
                    throw new RuntimeException('Data foto tidak ditemukan.');
                }

                $voteCheck = $pdo->prepare('SELECT COUNT(*) c FROM votes WHERE candidate_id = ?');
                $voteCheck->execute([$id]);
                $hasVotes = (int)$voteCheck->fetchColumn() > 0;
                if ($hasVotes) {
                    $old = $pdo->prepare('SELECT name, image_url FROM candidates WHERE id=? LIMIT 1');
                    $old->execute([$id]);
                    $oldRow = $old->fetch();
                    if ($oldRow && ($name !== $oldRow['name'] || $imageUrl)) {
                        throw new RuntimeException('Foto yang sudah memiliki vote tidak boleh mengganti nama atau file fotonya. Ubah status/urutan/deskripsi saja.');
                    }
                }

                $pollCheck = $pdo->prepare('SELECT COUNT(*) FROM polls WHERE id=?');
                $pollCheck->execute([$pollId]);
                if ((int)$pollCheck->fetchColumn() !== 1) {
                    throw new RuntimeException('Polling tujuan tidak ditemukan.');
                }

                $oldMetaStmt = $pdo->prepare('SELECT image_url, sort_order, poll_id FROM candidates WHERE id=? LIMIT 1');
                $oldMetaStmt->execute([$id]);
                $oldMeta = $oldMetaStmt->fetch() ?: [];
                $oldImageUrl = (string)($oldMeta['image_url'] ?? '');
                $oldSort = (int)($oldMeta['sort_order'] ?? 0);
                $prevPollId = (int)($oldMeta['poll_id'] ?? $pollId);

                // Geser nomor urut lain jika slot tujuan berubah / pindah polling
                if ($prevPollId !== $pollId || $oldSort !== $sortOrder) {
                    candidates_make_room_for_sort($pdo, $pollId, $sortOrder, $id);
                }

                if ($imageUrl) {
                    $stmt = $pdo->prepare('UPDATE candidates SET poll_id=?, name=?, image_url=?, description=?, status=?, sort_order=? WHERE id=?');
                    $stmt->execute([$pollId, $name, $imageUrl, $description, $status, $sortOrder, $id]);
                    if ($oldImageUrl && $oldImageUrl !== $imageUrl) {
                        $base = realpath(UPLOAD_DIR);
                        $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $oldImageUrl), '/');
                        $oldFile = realpath(dirname(__DIR__) . '/' . $relative);
                        if ($base && $oldFile && str_starts_with($oldFile, $base . DIRECTORY_SEPARATOR) && is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                } else {
                    $stmt = $pdo->prepare('UPDATE candidates SET poll_id=?, name=?, description=?, status=?, sort_order=? WHERE id=?');
                    $stmt->execute([$pollId, $name, $description, $status, $sortOrder, $id]);
                }
                admin_audit('candidate.update', 'candidate', $id, $name);
                flash_set('success', 'Foto kandidat berhasil diperbarui.');
                redirect('candidates.php?poll_id=' . $pollId);
            }
        } catch (Throwable $ex) {
            if (!empty($imageUrl)) {
                $newFile = dirname(__DIR__) . '/' . ltrim($imageUrl, '/');
                if (is_file($newFile)) {
                    @unlink($newFile);
                }
            }
            $error = $ex->getMessage();
        }
    }
    $currentPollId = $pollId > 0 ? $pollId : $currentPollId;
}

// ---------- Hapus ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $voteCheck = $pdo->prepare('SELECT COUNT(*) c FROM votes WHERE candidate_id = ?');
    $voteCheck->execute([$id]);
    if ((int)$voteCheck->fetchColumn() > 0) {
        flash_set('error', 'Foto tidak dapat dihapus karena sudah memiliki vote. Nonaktifkan saja foto ini.');
    } else {
        $imgStmt = $pdo->prepare('SELECT image_url FROM candidates WHERE id = ? LIMIT 1');
        $imgStmt->execute([$id]);
        $imageUrl = (string)($imgStmt->fetchColumn() ?: '');
        $pdo->prepare('DELETE FROM candidates WHERE id = ?')->execute([$id]);
        if ($imageUrl !== '') {
            $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $imageUrl), '/');
            $base = realpath(UPLOAD_DIR);
            $file = realpath(dirname(__DIR__) . '/' . $relative);
            if ($base && $file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
                @unlink($file);
            }
        }
        admin_audit('candidate.delete', 'candidate', $id, 'Foto kandidat dihapus');
        flash_set('success', 'Foto kandidat berhasil dihapus.');
    }
    redirect('candidates.php?poll_id=' . $currentPollId);
}

$editRow = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE id = ?');
    $stmt->execute([$id]);
    $editRow = $stmt->fetch() ?: null;
    if ($editRow) {
        $currentPollId = (int)$editRow['poll_id'];
    }
}

$candidates = [];
if ($currentPollId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM candidates WHERE poll_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$currentPollId]);
    $candidates = $stmt->fetchAll();
}

$maxFileUploads = (int)ini_get('max_file_uploads');
$uploadMax = ini_get('upload_max_filesize');
$postMax = ini_get('post_max_size');

$active_menu = 'candidates';
$page_title = 'Kelola Foto';
include __DIR__ . '/includes/header.php';
?>
<div class="admin-topbar">
  <h1>Kelola Foto Polling</h1>
  <div class="muted">Total di polling ini: <strong><?= count($candidates) ?></strong> foto · Tidak ada batas jumlah di database</div>
</div>

<?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$polls): ?>
  <div class="card"><div class="alert error">Belum ada polling. Buat polling dulu di Pengaturan.</div></div>
<?php else: ?>
  <div class="card">
    <form method="get" class="filter-bar">
      <label style="margin:0">Polling</label>
      <select name="poll_id" onchange="this.form.submit()">
        <?php foreach ($polls as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $currentPollId === (int)$p['id'] ? 'selected' : '' ?>>
            <?= e($p['title']) ?> — <?= e(ucfirst($p['status'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!$editRow): ?>
  <div class="card">
    <h3 style="margin-top:0">Upload Massal (banyak foto sekaligus)</h3>
    <p class="muted">Pilih hingga <strong><?= (int)CANDIDATE_BULK_MAX ?></strong> foto sekaligus. Nama file dipakai sebagai nama kandidat. Format JPG/PNG/WEBP, maks <?= (int)(MAX_UPLOAD_SIZE / 1024 / 1024) ?>MB per file.</p>
    <p class="muted" style="font-size:13px">Batas server saat ini: <code>max_file_uploads=<?= e((string)$maxFileUploads) ?></code>, <code>upload_max_filesize=<?= e((string)$uploadMax) ?></code>, <code>post_max_size=<?= e((string)$postMax) ?></code>. Jika gagal banyak file, naikkan nilai ini di cPanel → MultiPHP INI Editor.</p>
    <form method="post" action="candidates.php?action=bulk_add" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="poll_id" value="<?= (int)$currentPollId ?>">
      <label>Pilih banyak foto</label>
      <input type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple required>
      <label>Status</label>
      <select name="status">
        <option value=\'active\'>Active</option>
        <option value=\'inactive\'>Inactive</option>
      </select>
      <label>Urutan awal (opsional)</label>
      <input type="number" name="sort_order" value="0" min="0" step="1">
      <p class="muted" style="font-size:13px;margin-top:4px">Jika nomor sudah dipakai, foto lama di nomor itu dan seterusnya otomatis digeser (+1).</p>
      <button type="submit" class="btn">Upload Semua Foto</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card" style="max-width:520px;">
    <h3 style="margin-top:0"><?= $editRow ? 'Edit Foto' : 'Tambah Foto Satu per Satu' ?></h3>
    <form method="post" action="candidates.php?action=<?= $editRow ? 'edit' : 'add' ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
      <label>Polling</label>
      <select name="poll_id" required>
        <?php foreach ($polls as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (($editRow['poll_id'] ?? $currentPollId) == $p['id']) ? 'selected' : '' ?>><?= e($p['title']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Nama</label>
      <input type="text" name="name" required maxlength="150" value="<?= e($editRow['name'] ?? '') ?>">
      <label>Deskripsi (opsional)</label>
      <textarea name="description" rows="2"><?= e($editRow['description'] ?? '') ?></textarea>
      <label>Foto (<?= $editRow ? 'kosongkan jika tidak ingin mengganti' : 'JPG/PNG/WEBP, maks ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB' ?>)</label>
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" <?= $editRow ? '' : 'required' ?>>
      <label>Urutan Tampil</label>
      <input type="number" name="sort_order" value="<?= e((string)($editRow['sort_order'] ?? 0)) ?>" min="0" step="1">
      <p class="muted" style="font-size:13px;margin-top:4px">Jika nomor sudah dipakai foto lain, foto tersebut dan yang di belakangnya digeser otomatis (+1).</p>
      <label>Status</label>
      <select name="status">
        <option value=\'active\' <?= (!$editRow || $editRow['status'] === 'active') ? 'selected' : '' ?>>Active</option>
        <option value=\'inactive\' <?= ($editRow && $editRow['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
      </select>
      <button type="submit" class="btn"><?= $editRow ? 'Simpan Perubahan' : 'Tambah Foto' ?></button>
      <?php if ($editRow): ?><a href="candidates.php?poll_id=<?= (int)$currentPollId ?>" class="btn secondary">Batal</a><?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Daftar Foto (<?= count($candidates) ?>)</h3>
    <table>
      <thead><tr><th>Foto</th><th>Nama</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($candidates as $c): ?>
        <tr>
          <td><?php if ($c['image_url']): ?><img src="../<?= e($c['image_url']) ?>" alt="" style="width:50px;height:66px;object-fit:cover;border-radius:6px;"><?php endif; ?></td>
          <td><?= e($c['name']) ?></td>
          <td><?= (int)$c['sort_order'] ?></td>
          <td><span class="badge <?= e($c['status']) ?>"><?= $c['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
          <td>
            <a href="candidates.php?action=edit&id=<?= (int)$c['id'] ?>&amp;poll_id=<?= (int)$currentPollId ?>" class="btn small secondary">Edit</a>
            <form method="post" action="candidates.php?action=delete" style="display:inline" onsubmit="return confirm('Hapus foto ini?');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn small danger">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($candidates)): ?><tr><td colspan="5" class="muted">Belum ada foto untuk polling ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
