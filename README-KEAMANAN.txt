# Deployment Rumahweb — Login + Security

Aplikasi asli tidak diubah. Perubahan hanya pada lapisan akses/login dan keamanan hosting.

## Struktur
- `index.php` — pintu masuk yang hanya menampilkan aplikasi setelah sesi login.
- `login.html` — form login.
- `login.php` — validasi login dan pembuatan sesi server-side.
- `logout.php` — menghapus sesi.
- `app.html` — aplikasi asli, dipertahankan apa adanya.
- `.htaccess` — security headers + memblokir akses langsung ke app.html.
- `README-KEAMANAN.txt` — petunjuk ini.

## Kredensial bootstrap
Username: admin
Password: admin

**WAJIB ganti password sebelum website dipublikasikan.**
Ubah `PASSWORD_SHA256` di `login.php` menjadi SHA-256 password baru. Jangan gunakan password contoh.

## Instalasi
Upload semua file ke `public_html`. Pastikan PHP aktif dan HTTPS/SSL aktif.

Setelah upload:
1. buka domain — harus muncul form login;
2. login dengan kredensial yang sudah diganti;
3. pastikan membuka `/app.html` langsung ditolak;
4. pastikan `logout.php` mengakhiri sesi.

## Batasan penting
Ini sudah menggunakan session server-side, cookie Secure/HttpOnly/SameSite=Strict, session ID regeneration setelah login, no-store caching, CSP, HSTS, dan beberapa hardening Apache.

Karena aplikasi aslinya client-side, login ini melindungi akses ke halaman aplikasi, tetapi belum membuat data internal aplikasi menjadi server-authoritative. Jika aplikasi nantinya menyimpan data qurban ke database/API, backend tersebut harus memiliki autentikasi/otorisasi sendiri.


## Pengaturan Akun
- `account.php` — pengelolaan akun berbasis SQLite.
- Login sekarang menggunakan `password_hash()` / `password_verify()`.
- Role tersedia: `admin` dan `petugas`.
- Admin dapat menambah, mengubah, menonaktifkan, dan menghapus akun petugas.
- Admin maupun petugas dapat mengubah password akun sendiri.
- Endpoint pengelolaan akun dilindungi sesi login + CSRF token.
- Password tidak disimpan dalam bentuk teks biasa.

## Perlindungan & Keamanan Data (Anti-Hilang / Anti-Tertimpa)
1. **Perlindungan Deployment cPanel (`.cpanel.yml`)**:
   - Skrip deployment secara otomatis mencadangkan `database.sqlite` sebelum menyalin berkas aplikasi baru.
   - Database produksi dikembalikan secara utuh setelah penyalinan berkas, sehingga pembaruan aplikasi lewat Git/cPanel tidak akan pernah menimpa atau menghapus data qurban yang sedang berjalan.
2. **Penyimpanan Ganda (Dual-Layer Persistence)**:
   - Data disimpan secara otomatis ke SQLite (`database.sqlite`) dan cermin file JSON terproteksi (`database/app_state.json`).
3. **Pencadangan Otomatis (Auto-Snapshots)**:
   - Setiap kali data disimpan oleh panitia, sistem otomatis membuat snapshot cadangan di folder `database/backups/`.
   - Tabel `app_state_history` di SQLite mencatat 50 riwayat perubahan terakhir.
4. **Pusat Cadangan & Pemulihan Mandiri (UI Pengaturan)**:
   - Admin dapat mengunduh berkas cadangan JSON lengkap ke komputer/ponsel kapan saja via menu Pengaturan.
   - Admin dapat mengunduh berkas mentah `database.sqlite`.
   - Admin dapat memulihkan seluruh data dari berkas cadangan JSON hanya dengan 1 klik.
   - Admin dapat memulihkan data dari snapshot riwayat server.
5. **Proteksi Folder & Berkas Sensitif (`.htaccess`)**:
   - Seluruh berkas database (`*.sqlite`, `*.db`, `*.json`, `*.bak`) dan direktori `database/` serta `storage/` diblokir total dari akses langsung melalui web browser.
