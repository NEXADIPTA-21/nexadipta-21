<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/../nexadipta-21/includes/content.php';
$pdo = get_db();
ensure_nexadipta21_schema($pdo);

$uploadDir = __DIR__ . '/../uploads/angkatan';
$uploadPrefix = 'uploads/angkatan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'settings') {
            $keys = [
                'site_name',
                'tagline',
                'hero_title',
                'hero_description',
                'year',
                'about_title',
                'about_text',
            ];

            foreach ($keys as $key) {
                nx21_save_setting($pdo, $key, trim((string) ($_POST[$key] ?? '')));
            }

            if (!empty($_FILES['hero_image']['name'])) {
                $old = nx21_setting($pdo, 'hero_image', '');
                $new = nx21_upload_image($_FILES['hero_image'], $uploadDir, $uploadPrefix);
                nx21_save_setting($pdo, 'hero_image', $new);
                nx21_delete_upload($old);
            }

            if (!empty($_FILES['logo_image']['name'])) {
                $old = nx21_setting($pdo, 'logo_image', '');
                $new = nx21_upload_image($_FILES['logo_image'], $uploadDir, $uploadPrefix);
                nx21_save_setting($pdo, 'logo_image', $new);
                nx21_delete_upload($old);
            }

            if (!empty($_POST['remove_logo_image'])) {
                $old = nx21_setting($pdo, 'logo_image', '');
                nx21_save_setting($pdo, 'logo_image', '');
                nx21_delete_upload($old);
            }

            if (!empty($_POST['remove_hero_image'])) {
                $old = nx21_setting($pdo, 'hero_image', '');
                nx21_delete_upload($old);
                nx21_save_setting($pdo, 'hero_image', '');
            }

            admin_audit(
                'website.settings.update',
                'website',
                null,
                'Pengaturan identitas/beranda website diperbarui'
            );
            flash_set('success', 'Pengaturan website berhasil disimpan.');
        } elseif ($action === 'timeline_save') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('Judul timeline wajib diisi.');
            }

            $mediaType = (string) ($_POST['media_type'] ?? 'none');
            $mediaUrl = nx21_safe_url((string) ($_POST['media_url'] ?? ''));

            if ($mediaType === 'image' && !empty($_FILES['media_image']['name'])) {
                $mediaUrl = nx21_upload_image($_FILES['media_image'], $uploadDir, $uploadPrefix);
            }

            if ($mediaType === 'none') {
                $mediaUrl = '';
            }

            if ($mediaType === 'video' && !$mediaUrl) {
                throw new RuntimeException('URL video harus menggunakan http/https.');
            }

            if ($mediaType === 'image' && !$mediaUrl) {
                throw new RuntimeException('Pilih foto timeline atau masukkan URL gambar.');
            }

            if (!in_array($mediaType, ['none', 'image', 'video'], true)) {
                $mediaType = 'none';
                $mediaUrl = '';
            }

            $data = [
                $title,
                trim((string) ($_POST['description'] ?? '')),
                ($_POST['event_date'] ?? '') ?: null,
                trim((string) ($_POST['location'] ?? '')),
                ($_POST['start_time'] ?? '') ?: null,
                ($_POST['end_time'] ?? '') ?: null,
                $mediaType,
                $mediaUrl,
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active']) ? 1 : 0,
            ];

            $oldMedia = '';

            if ($id > 0) {
                $oldSt = $pdo->prepare(
                    'SELECT media_url FROM website_timeline WHERE id = ? LIMIT 1'
                );
                $oldSt->execute([$id]);
                $oldMedia = (string) ($oldSt->fetchColumn() ?: '');

                $data[] = $id;
                $st = $pdo->prepare(
                    'UPDATE website_timeline SET\n'
                    . ' title=?, description=?, event_date=?, location=?, start_time=?, end_time=?,\n'
                    . ' media_type=?, media_url=?, sort_order=?, is_active=? WHERE id=?'
                );
                $st->execute($data);
                $auditAction = 'website.timeline.update';
                $message = 'Timeline diperbarui: ' . $title;
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO website_timeline\n'
                    . ' (title, description, event_date, location, start_time, end_time, media_type, media_url, sort_order, is_active)\n'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute($data);
                $id = (int) db_last_insert_id($pdo);
                $auditAction = 'website.timeline.create';
                $message = 'Timeline ditambahkan: ' . $title;
            }

            if ($oldMedia && $oldMedia !== $mediaUrl) {
                nx21_delete_upload($oldMedia);
            }

            admin_audit($auditAction, 'website_timeline', $id, $message);
            flash_set('success', 'Timeline berhasil disimpan.');
        } elseif ($action === 'timeline_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT title, media_url FROM website_timeline WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $timeline = $st->fetch();

            if (!$timeline) {
                throw new RuntimeException('Timeline tidak ditemukan.');
            }

            $pdo->prepare('DELETE FROM website_timeline WHERE id = ?')->execute([$id]);
            nx21_delete_upload($timeline['media_url'] ?? '');
            admin_audit(
                'website.timeline.delete',
                'website_timeline',
                $id,
                'Timeline dihapus: ' . (string) $timeline['title']
            );
            flash_set('success', 'Timeline dihapus.');
        } elseif ($action === 'gallery_add') {
            $title = trim((string) ($_POST['title'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('Judul foto wajib diisi.');
            }

            $path = nx21_upload_image(
                $_FILES['image'] ?? [],
                $uploadDir,
                $uploadPrefix
            );

            if (!$path) {
                throw new RuntimeException('Pilih foto terlebih dahulu.');
            }

            $st = $pdo->prepare(
                'INSERT INTO website_gallery\n'
                . ' (title, description, image_path, sort_order, is_active)\n'
                . ' VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([
                $title,
                trim((string) ($_POST['description'] ?? '')),
                $path,
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active']) ? 1 : 0,
            ]);

            $id = (int) db_last_insert_id($pdo);
            admin_audit('website.gallery.create', 'website_gallery', $id, 'Foto galeri ditambahkan: ' . $title);
            flash_set('success', 'Foto berhasil ditambahkan ke galeri.');
        } elseif ($action === 'gallery_update') {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT title, image_path FROM website_gallery WHERE id = ?');
            $st->execute([$id]);
            $oldRow = $st->fetch();

            if (!$oldRow) {
                throw new RuntimeException('Foto tidak ditemukan.');
            }

            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Judul foto wajib diisi.');
            }

            $path = $oldRow['image_path'];
            if (!empty($_FILES['image']['name'])) {
                $path = nx21_upload_image($_FILES['image'], $uploadDir, $uploadPrefix);
                nx21_delete_upload($oldRow['image_path']);
            }

            $st = $pdo->prepare(
                'UPDATE website_gallery SET title=?, description=?, image_path=?, sort_order=?, is_active=? WHERE id=?'
            );
            $st->execute([
                $title,
                trim((string) ($_POST['description'] ?? '')),
                $path,
                (int) ($_POST['sort_order'] ?? 0),
                !empty($_POST['is_active']) ? 1 : 0,
                $id,
            ]);

            admin_audit('website.gallery.update', 'website_gallery', $id, 'Foto galeri diperbarui: ' . $title);
            flash_set('success', 'Foto galeri diperbarui.');
        } elseif ($action === 'gallery_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT title, image_path FROM website_gallery WHERE id = ?');
            $st->execute([$id]);
            $gallery = $st->fetch();

            if (!$gallery) {
                throw new RuntimeException('Foto tidak ditemukan.');
            }

            $pdo->prepare('DELETE FROM website_gallery WHERE id = ?')->execute([$id]);
            nx21_delete_upload($gallery['image_path'] ?? '');
            admin_audit('website.gallery.delete', 'website_gallery', $id, 'Foto galeri dihapus: ' . (string) $gallery['title']);
            flash_set('success', 'Foto dihapus.');
        } elseif ($action === 'contact_save') {
            $id = (int) ($_POST['id'] ?? 0);
            $label = trim((string) ($_POST['label'] ?? ''));
            $url = nx21_safe_url((string) ($_POST['url'] ?? ''));

            if ($label === '' || $url === '') {
                throw new RuntimeException('Nama dan URL kontak wajib diisi.');
            }

            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE website_contacts SET label=?, url=?, sort_order=?, is_active=? WHERE id=?'
                );
                $st->execute([$label, $url, $sortOrder, $isActive, $id]);
                $auditAction = 'website.contact.update';
                $message = 'Kontak diperbarui: ' . $label;
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO website_contacts(label, url, sort_order, is_active) VALUES(?, ?, ?, ?)'
                );
                $st->execute([$label, $url, $sortOrder, $isActive]);
                $id = (int) db_last_insert_id($pdo);
                $auditAction = 'website.contact.create';
                $message = 'Kontak ditambahkan: ' . $label;
            }

            admin_audit($auditAction, 'website_contact', $id, $message);
            flash_set('success', 'Kontak berhasil disimpan.');
        } elseif ($action === 'contact_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT label FROM website_contacts WHERE id = ?');
            $st->execute([$id]);
            $contact = $st->fetch();

            if (!$contact) {
                throw new RuntimeException('Kontak tidak ditemukan.');
            }

            $pdo->prepare('DELETE FROM website_contacts WHERE id = ?')->execute([$id]);
            admin_audit('website.contact.delete', 'website_contact', $id, 'Kontak dihapus: ' . (string) $contact['label']);
            flash_set('success', 'Kontak dihapus.');
        } else {
            throw new RuntimeException('Aksi website tidak dikenali.');
        }
    } catch (Throwable $e) {
        error_log('Website admin action failed [' . $action . ']: ' . $e->getMessage());
        flash_set('error', $e->getMessage());
    }

    redirect('website.php');
}

$editTimeline=null; if(isset($_GET['edit_timeline'])){$st=$pdo->prepare('SELECT * FROM website_timeline WHERE id=?');$st->execute([(int)$_GET['edit_timeline']]);$editTimeline=$st->fetch();}
$editGallery=null; if(isset($_GET['edit_gallery'])){$st=$pdo->prepare('SELECT * FROM website_gallery WHERE id=?');$st->execute([(int)$_GET['edit_gallery']]);$editGallery=$st->fetch();}
$editContact=null; if(isset($_GET['edit_contact'])){$st=$pdo->prepare('SELECT * FROM website_contacts WHERE id=?');$st->execute([(int)$_GET['edit_contact']]);$editContact=$st->fetch();}
$timelines=$pdo->query('SELECT * FROM website_timeline ORDER BY CASE WHEN event_date IS NULL THEN 1 ELSE 0 END,event_date,sort_order,id')->fetchAll();
$gallery=$pdo->query('SELECT * FROM website_gallery ORDER BY sort_order,id DESC')->fetchAll();
$contacts=$pdo->query('SELECT * FROM website_contacts ORDER BY sort_order,id')->fetchAll();
$active_menu='website';$page_title='Website Angkatan';include __DIR__.'/includes/header.php';
?>
<div class="admin-topbar"><h1>Website Angkatan</h1></div>
<?php if($m=flash_get('success')): ?><div class="alert success"><?= e($m) ?></div><?php endif; ?><?php if($m=flash_get('error')): ?><div class="alert error"><?= e($m) ?></div><?php endif; ?>
<div class="admin-card" style="margin-bottom:20px"><h2>Website Publik</h2><p>Kelola isi <code>/nexadipta-21/</code> tanpa mengubah file satu per satu.</p><a class="btn btn-primary" href="../nexadipta-21/" target="_blank" rel="noopener">Buka Website Angkatan ↗</a></div>
<div class="admin-card"><h2>Identitas & Beranda</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="settings"><?= csrf_field() ?><div class="form-grid"><label>Nama Angkatan<input name="site_name" value="<?= e(nx21_setting($pdo,'site_name')) ?>"></label><label>Tagline<input name="tagline" value="<?= e(nx21_setting($pdo,'tagline')) ?>"></label><label>Tahun<input name="year" value="<?= e(nx21_setting($pdo,'year')) ?>"></label><label>Judul Hero<input name="hero_title" value="<?= e(nx21_setting($pdo,'hero_title')) ?>"></label><label>Logo Angkatan<input type="file" name="logo_image" accept="image/jpeg,image/png,image/webp"><small class="muted">Logo tampil di kiri atas website. Disarankan gambar persegi.</small></label></div><label>Deskripsi Hero<textarea name="hero_description"><?= e(nx21_setting($pdo,'hero_description')) ?></textarea></label><div class="form-grid"><label>Judul Tentang<input name="about_title" value="<?= e(nx21_setting($pdo,'about_title')) ?>"></label><label>Foto Utama<input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp"></label></div><label>Isi Tentang<textarea name="about_text" rows="6"><?= e(nx21_setting($pdo,'about_text')) ?></textarea></label><?php if(nx21_setting($pdo,'logo_image')): ?><p><strong>Logo saat ini:</strong><br><img class="admin-logo-preview" src="../<?= e(nx21_setting($pdo,'logo_image')) ?>" alt="Logo" style="width:90px;height:90px;object-fit:contain;border-radius:16px;border:1px solid var(--border);padding:8px;background:#f8fafc"><br><label><input type="checkbox" name="remove_logo_image" value="1"> Hapus logo</label></p><?php endif; ?><?php if(nx21_setting($pdo,'hero_image')): ?><p><img src="../<?= e(nx21_setting($pdo,'hero_image')) ?>" style="max-width:260px;border-radius:12px"><br><label><input type="checkbox" name="remove_hero_image" value="1"> Hapus foto utama</label></p><?php endif; ?><button class="btn btn-primary" type="submit">Simpan Beranda</button></form></div>
<div class="admin-card"><div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap"><h2 style="margin:0"><?= $editTimeline?'Edit':'Tambah' ?> Timeline / Jadwal</h2><span class="badge active"><?= count($timelines) ?> jadwal tersimpan</span></div><p class="muted">Timeline tidak dibatasi satu item. Tambahkan sebanyak yang diperlukan untuk sesi foto, video, rapat, acara, atau agenda lainnya. Setiap item punya tanggal, jam, lokasi, media, dan status publik sendiri.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="timeline_save"><input type="hidden" name="id" value="<?= (int)($editTimeline['id']??0) ?>"><?= csrf_field() ?><div class="form-grid"><label>Judul<input required name="title" value="<?= e($editTimeline['title']??'') ?>" placeholder="Sesi Foto Angkatan"></label><label>Tanggal<input type="date" name="event_date" value="<?= e($editTimeline['event_date']??'') ?>"></label><label>Jam Mulai<input type="time" name="start_time" value="<?= e($editTimeline['start_time']??'') ?>"></label><label>Jam Selesai<input type="time" name="end_time" value="<?= e($editTimeline['end_time']??'') ?>"></label><label>Lokasi<input name="location" value="<?= e($editTimeline['location']??'') ?>" placeholder="Studio / Sekolah / Lokasi"></label><label>Urutan<input type="number" name="sort_order" value="<?= (int)($editTimeline['sort_order']??0) ?>"></label><label>Media URL<input name="media_url" value="<?= e($editTimeline['media_url']??'') ?>" placeholder="URL video atau gambar (http/https)"></label><label>Upload Foto Timeline<input type="file" name="media_image" accept="image/jpeg,image/png,image/webp"></label><label>Jenis Media<select name="media_type"><option value="none" <?= (($editTimeline['media_type']??'none')==='none'?'selected':'') ?>>Tidak ada</option><option value="image" <?= (($editTimeline['media_type']??'')==='image'?'selected':'') ?>>Gambar</option><option value="video" <?= (($editTimeline['media_type']??'')==='video'?'selected':'') ?>>Video</option></select></label></div><label>Deskripsi<textarea name="description"><?= e($editTimeline['description']??'') ?></textarea></label><label><input type="checkbox" name="is_active" value="1" <?= !isset($editTimeline)||!empty($editTimeline['is_active'])?'checked':'' ?>> Tampilkan di website</label><br><button class="btn btn-primary">Simpan Timeline</button> <?php if($editTimeline): ?><a class="btn btn-outline" href="website.php">Batal</a><?php endif; ?></form><hr><div id="timeline-list"><div class="admin-list"><?php foreach($timelines as $t): ?><div class="admin-list-row"><div><strong><?= e($t['title']) ?></strong><small><?= e($t['event_date']?:'Tanpa tanggal') ?> · <?= e($t['location']?:'Tanpa lokasi') ?> · <?= $t['is_active']?'Publik':'Tersembunyi' ?></small></div><div><a class="btn btn-outline" href="?edit_timeline=<?= (int)$t['id'] ?>">Edit</a><form method="post" style="display:inline" onsubmit="return confirm('Hapus timeline ini?')"><input type="hidden" name="action" value="timeline_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><?= csrf_field() ?><button class="btn btn-danger">Hapus</button></form></div></div><?php endforeach; ?></div></div></div>
<div class="admin-card"><h2><?= $editGallery?'Edit Foto':'Tambah Foto' ?> Galeri</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="<?= $editGallery?'gallery_update':'gallery_add' ?>"><input type="hidden" name="id" value="<?= (int)($editGallery['id']??0) ?>"><?= csrf_field() ?><div class="form-grid"><label>Judul<input required name="title" value="<?= e($editGallery['title']??'') ?>"></label><label>Foto<input type="file" name="image" accept="image/jpeg,image/png,image/webp" <?= $editGallery?'':'required' ?>></label><label>Urutan<input type="number" name="sort_order" value="<?= (int)($editGallery['sort_order']??0) ?>"></label></div><label>Keterangan<textarea name="description"><?= e($editGallery['description']??'') ?></textarea></label><label><input type="checkbox" name="is_active" value="1" <?= !isset($editGallery)||!empty($editGallery['is_active'])?'checked':'' ?>> Tampilkan di website</label><br><button class="btn btn-primary">Simpan Foto</button> <?php if($editGallery): ?><a class="btn btn-outline" href="website.php">Batal</a><?php endif; ?></form><div class="admin-photo-grid"><?php foreach($gallery as $g): ?><div class="admin-photo"><img src="../<?= e($g['image_path']) ?>"><strong><?= e($g['title']) ?></strong><small><?= $g['is_active']?'Publik':'Tersembunyi' ?></small><div><a class="btn btn-outline" href="?edit_gallery=<?= (int)$g['id'] ?>">Edit</a><form method="post" style="display:inline" onsubmit="return confirm('Hapus foto ini?')"><input type="hidden" name="action" value="gallery_delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><?= csrf_field() ?><button class="btn btn-danger">Hapus</button></form></div></div><?php endforeach; ?></div></div>
<div class="admin-card"><h2><?= $editContact?'Edit':'Tambah' ?> Kontak / Link</h2><form method="post"><input type="hidden" name="action" value="contact_save"><input type="hidden" name="id" value="<?= (int)($editContact['id']??0) ?>"><?= csrf_field() ?><div class="form-grid"><label>Nama<input required name="label" value="<?= e($editContact['label']??'') ?>" placeholder="Instagram Angkatan"></label><label>URL<input required name="url" value="<?= e($editContact['url']??'') ?>" placeholder="https://..."></label><label>Urutan<input type="number" name="sort_order" value="<?= (int)($editContact['sort_order']??0) ?>"></label></div><label><input type="checkbox" name="is_active" value="1" <?= !isset($editContact)||!empty($editContact['is_active'])?'checked':'' ?>> Tampilkan</label><br><button class="btn btn-primary">Simpan Kontak</button></form><div class="admin-list"><?php foreach($contacts as $c): ?><div class="admin-list-row"><div><strong><?= e($c['label']) ?></strong><small><?= e($c['url']) ?> · <?= $c['is_active']?'Publik':'Tersembunyi' ?></small></div><div><a class="btn btn-outline" href="?edit_contact=<?= (int)$c['id'] ?>">Edit</a><form method="post" style="display:inline" onsubmit="return confirm('Hapus kontak ini?')"><input type="hidden" name="action" value="contact_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><?= csrf_field() ?><button class="btn btn-danger">Hapus</button></form></div></div><?php endforeach; ?></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
