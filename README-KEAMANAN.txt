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
