# Polling Multi-Polling cPanel V11.4

V11.4 adalah perbaikan dari V11.3 khusus untuk konsistensi status/progres peserta.

## Perbaikan
- Dashboard polling questionnaire tidak lagi bergantung pada tabel `votes`.
- Progres questionnaire dihitung dari `poll_participations`:
  - completed + terminated = sudah mengikuti/selesai
  - in_progress = sedang berjalan
  - tidak punya sesi = belum mengikuti
- Dashboard polling foto tetap menggunakan `votes`.
- Data Peserta menggunakan sumber status yang sesuai dengan tipe polling.
- Filter Sudah/Belum mengikuti pada questionnaire diperbaiki.
- Export Data Peserta questionnaire menampilkan status sesi.
- Reset satu peserta dan reset semua polling mendukung questionnaire.
- Tidak ada perubahan migration database baru.

## Update
Backup V11.3, upload dan replace file V11.4. Tidak perlu import `schema.sql` atau migration baru.

## V11.9 additions
- Website Angkatan now supports an admin-uploaded logo shown in the public header at the upper-left.
- Participant records support an optional member photo (`participants.photo_path`). The column is added automatically when the website module initializes; no manual migration is required.
- Admin > Data Peserta can upload, replace, or remove a participant photo.
- Public `/nexadipta-21/kelas-detail.php?id=...` displays each active class member with photo and name.
