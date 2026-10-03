# V12.5 - Perbaikan Clock & Audit Log

## Clock NEXADIPTA 21
- Tetap overlay dengan `position: absolute`, sehingga tidak mengambil ruang layout.
- Kontras ditingkatkan agar teks tanggal dan jam terbaca jelas di mode gelap maupun terang.
- Kartu jam menggunakan radius penuh dan tidak lagi menghilangkan border sisi kanan.
- Lebar dibatasi terhadap viewport dan memiliki aturan responsive untuk layar kecil.
- `pointer-events: none` dipertahankan agar elemen di bawah jam tetap dapat diklik.

## Audit Log
Audit log sekarang mencatat:
- perubahan identitas/beranda website;
- tambah/edit/hapus timeline;
- tambah/edit/hapus galeri;
- tambah/edit/hapus kontak;
- login berhasil;
- login gagal;
- logout admin.

`admin_audit()` juga dapat membuat tabel `audit_logs` otomatis pada deployment lama jika tabel belum tersedia. Kegagalan pencatatan audit tidak membatalkan operasi utama.
