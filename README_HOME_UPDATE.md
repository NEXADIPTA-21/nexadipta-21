# Perbaikan: NEXADIPTA 21 sebagai halaman utama

## Perubahan
- **Beranda website** (`/`) sekarang mengarah ke **NEXADIPTA 21** (`/nexadipta-21/`).
- **Sistem polling** (token + verifikasi peserta) dipindah ke **`/poll.php`**.
- Link "Buka Polling" di halaman Polling website angkatan mengarah ke `/poll.php`.
- Redirect internal polling (`vote`, `confirm`, `success`, `questionnaire`, `results_public`) memakai `poll.php`, bukan `index.php`.
- Path aset & media di folder `nexadipta-21` disesuaikan agar bekerja sebagai subdirektori (bukan harus document root).
- Link **Admin** di footer website angkatan: `/admin/login.php`.
- `/admin/` otomatis diarahkan ke halaman login.

## Akses penting
| URL | Fungsi |
|-----|--------|
| `/` | Website angkatan NEXADIPTA 21 |
| `/nexadipta-21/` | Sama (beranda angkatan) |
| `/poll.php` | Entry sistem polling |
| `/admin/` atau `/admin/login.php` | Panel admin |

## Catatan deploy
Upload/replace seluruh isi folder ini ke document root hosting (public_html). Tidak perlu mengubah Document Root ke subfolder `nexadipta-21`. Tidak ada migration database baru.
