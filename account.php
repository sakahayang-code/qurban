<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesi tidak valid.']);
    exit;
}

$dbFile = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $tableSql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($tableSql === '') {
        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('superadmin','admin','petugas')),
            password_hash TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
    } elseif (strpos($tableSql, "'superadmin'") === false) {
        $pdo->beginTransaction();
        try {
            $pdo->exec("ALTER TABLE users RENAME TO users_legacy");
            $pdo->exec("CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('superadmin','admin','petugas')),
                password_hash TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )");
            $pdo->exec("INSERT INTO users (id, username, display_name, role, password_hash, active, created_at, updated_at)
                SELECT id, username, display_name, role, password_hash, active, created_at, updated_at FROM users_legacy");
            $pdo->exec("DROP TABLE users_legacy");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'superadmin' LIMIT 1");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $now = gmdate('c');
        $stmt = $pdo->prepare("INSERT INTO users (username, display_name, role, password_hash, active, created_at, updated_at)
            VALUES ('superadmin', 'Super Admin', 'superadmin', :password_hash, 1, :created_at, :updated_at)");
        $stmt->execute([
            ':password_hash' => password_hash('superadmin', PASSWORD_DEFAULT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database akun tidak tersedia.']);
    exit;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}
function reply(bool $ok, array $extra = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function require_csrf(): void {
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        reply(false, ['error' => 'Permintaan tidak sah.'], 403);
    }
}
function require_admin(): void {
    if (!in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true)) {
        reply(false, ['error' => 'Anda tidak memiliki izin mengelola akun.'], 403);
    }
}
function require_superadmin(): void {
    if (($_SESSION['role'] ?? '') !== 'superadmin') {
        reply(false, ['error' => 'Hanya Super Admin yang dapat mengelola seluruh akun.'], 403);
    }
}

$action = (string)($_GET['action'] ?? 'list');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, username, display_name, role, active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_SESSION['user_id']]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$me) reply(false, ['error' => 'Akun aktif tidak ditemukan.'], 404);

    $result = ['me' => [
        'id' => (int)$me['id'],
        'username' => $me['username'],
        'display_name' => $me['display_name'],
        'role' => $me['role'],
    ]];
    if (in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'], true)) {
        $rows = $pdo->query("SELECT id, username, display_name, role, active, created_at, updated_at FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, display_name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (int)$row['active'] === 1;
        }
        unset($row);
        $result['users'] = $rows;
    }
    reply(true, $result);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, ['error' => 'Method tidak diizinkan.'], 405);
require_csrf();
$data = json_input();

if ($action === 'save_admin') {
    if (($_SESSION['role'] ?? '') !== 'admin') reply(false, ['error' => 'Aksi ini hanya untuk akun Admin.'], 403);
    $id = (int)$_SESSION['user_id'];
    $username = trim((string)($data['username'] ?? ''));
    $display = trim((string)($data['display_name'] ?? ''));
    $current = (string)($data['current_password'] ?? '');
    $new = (string)($data['new_password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) reply(false, ['error' => 'Username 3-40 karakter: huruf, angka, titik, garis bawah, atau strip.'], 422);
    if ($display === '' || mb_strlen($display) > 80) reply(false, ['error' => 'Nama admin wajib diisi.'], 422);
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND role = \'admin\' LIMIT 1');
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current, $row['password_hash'])) reply(false, ['error' => 'Password saat ini salah.'], 422);
    if ($new !== '' && strlen($new) < 8) reply(false, ['error' => 'Password baru minimal 8 karakter.'], 422);
    try {
        $sql = 'UPDATE users SET username = :username, display_name = :display_name, updated_at = :updated_at';
        $params = [':username'=>$username, ':display_name'=>$display, ':updated_at'=>gmdate('c'), ':id'=>$id];
        if ($new !== '') { $sql .= ', password_hash = :password_hash'; $params[':password_hash'] = password_hash($new, PASSWORD_DEFAULT); }
        $sql .= ' WHERE id = :id AND role = \'admin\'';
        $pdo->prepare($sql)->execute($params);
        $_SESSION['username'] = $username; $_SESSION['display_name'] = $display;
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        setcookie('pdkq_csrf', $_SESSION['csrf_token'], ['expires'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443','httponly'=>false,'samesite'=>'Strict']);
        reply(true, ['message'=>'Akun admin berhasil diperbarui.']);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 19) reply(false, ['error'=>'Username sudah digunakan.'], 422);
        reply(false, ['error'=>'Gagal menyimpan akun admin.'], 500);
    }
}

if ($action === 'save_superadmin') {
    require_superadmin();
    $id = (int)$_SESSION['user_id'];
    $username = trim((string)($data['username'] ?? ''));
    $display = trim((string)($data['display_name'] ?? ''));
    $current = (string)($data['current_password'] ?? '');
    $new = (string)($data['new_password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) reply(false, ['error' => 'Username 3-40 karakter: huruf, angka, titik, garis bawah, atau strip.'], 422);
    if ($display === '' || mb_strlen($display) > 80) reply(false, ['error' => 'Nama Super Admin wajib diisi.'], 422);
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND role = 'superadmin' LIMIT 1");
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current, $row['password_hash'])) reply(false, ['error' => 'Password saat ini salah.'], 422);
    if ($new !== '' && strlen($new) < 8) reply(false, ['error' => 'Password baru minimal 8 karakter.'], 422);
    try {
        $sql = "UPDATE users SET username = :username, display_name = :display_name, updated_at = :updated_at";
        $params = [':username'=>$username, ':display_name'=>$display, ':updated_at'=>gmdate('c'), ':id'=>$id];
        if ($new !== '') { $sql .= ', password_hash = :password_hash'; $params[':password_hash'] = password_hash($new, PASSWORD_DEFAULT); }
        $sql .= " WHERE id = :id AND role = 'superadmin'";
        $pdo->prepare($sql)->execute($params);
        $_SESSION['username'] = $username; $_SESSION['display_name'] = $display;
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        setcookie('pdkq_csrf', $_SESSION['csrf_token'], ['expires'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443','httponly'=>false,'samesite'=>'Strict']);
        reply(true, ['message'=>'Akun Super Admin berhasil diperbarui.']);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 19) reply(false, ['error'=>'Username sudah digunakan.'], 422);
        reply(false, ['error'=>'Gagal menyimpan akun Super Admin.'], 500);
    }
}

if ($action === 'save_user') {
    require_superadmin();
    $id = (int)($data['id'] ?? 0);
    $username = trim((string)($data['username'] ?? ''));
    $display = trim((string)($data['display_name'] ?? ''));
    $role = (string)($data['role'] ?? '');
    $password = (string)($data['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) reply(false, ['error' => 'Username 3-40 karakter: huruf, angka, titik, garis bawah, atau strip.'], 422);
    if ($display === '' || mb_strlen($display) > 80) reply(false, ['error' => 'Nama akun wajib diisi.'], 422);
    if (!in_array($role, ['superadmin','admin','petugas'], true)) reply(false, ['error' => 'Role akun tidak valid.'], 422);
    if ($id === (int)$_SESSION['user_id'] && $role !== 'superadmin') reply(false, ['error' => 'Role akun Super Admin aktif tidak dapat diturunkan dari sini.'], 422);
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id'=>$id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) reply(false, ['error'=>'Akun tidak ditemukan.'], 404);
            if ($password !== '' && strlen($password) < 8) reply(false, ['error' => 'Password minimal 8 karakter.'], 422);
            $sql = 'UPDATE users SET username = :username, display_name = :display_name, role = :role, updated_at = :updated_at';
            $params = [':username'=>$username, ':display_name'=>$display, ':role'=>$role, ':updated_at'=>gmdate('c'), ':id'=>$id];
            if ($password !== '') { $sql .= ', password_hash = :password_hash'; $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT); }
            $sql .= ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['username'] = $username; $_SESSION['display_name'] = $display; $_SESSION['role'] = $role;
            }
            reply(true, ['message' => 'Akun berhasil diperbarui.']);
        }
        if (strlen($password) < 8) reply(false, ['error' => 'Password minimal 8 karakter.'], 422);
        $now = gmdate('c');
        $stmt = $pdo->prepare("INSERT INTO users (username, display_name, role, password_hash, active, created_at, updated_at)
            VALUES (:username, :display_name, :role, :password_hash, 1, :created_at, :updated_at)");
        $stmt->execute([':username'=>$username, ':display_name'=>$display, ':role'=>$role,
            ':password_hash'=>password_hash($password, PASSWORD_DEFAULT), ':created_at'=>$now, ':updated_at'=>$now]);
        reply(true, ['message' => 'Akun berhasil dibuat.']);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 19) reply(false, ['error' => 'Username sudah digunakan.'], 422);
        reply(false, ['error' => 'Gagal menyimpan akun.'], 500);
    }
}

if ($action === 'delete_user') {
    require_superadmin();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0 || $id === (int)$_SESSION['user_id']) reply(false, ['error'=>'Akun aktif tidak dapat dihapus.'], 422);
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id'=>$id]);
    if ($stmt->rowCount() < 1) reply(false, ['error'=>'Akun tidak ditemukan.'], 404);
    reply(true, ['message'=>'Akun berhasil dihapus.']);
}

if ($action === 'toggle_user') {
    require_superadmin();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0 || $id === (int)$_SESSION['user_id']) reply(false, ['error'=>'Status akun aktif tidak dapat dinonaktifkan.'], 422);
    $active = !empty($data['active']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE users SET active = :active, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([':active'=>$active, ':updated_at'=>gmdate('c'), ':id'=>$id]);
    if ($stmt->rowCount() < 1) reply(false, ['error'=>'Akun tidak ditemukan.'], 404);
    reply(true, ['message'=>$active ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.']);
}

if ($action === 'change_password') {
    $current = (string)($data['current_password'] ?? '');
    $new = (string)($data['new_password'] ?? '');
    if (strlen($new) < 8) reply(false, ['error' => 'Password baru minimal 8 karakter.'], 422);

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current, $row['password_hash'])) reply(false, ['error' => 'Password saat ini salah.'], 422);

    $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([':hash'=>password_hash($new, PASSWORD_DEFAULT), ':updated_at'=>gmdate('c'), ':id'=>(int)$_SESSION['user_id']]);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    setcookie('pdkq_csrf', $_SESSION['csrf_token'], ['expires'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443','httponly'=>false,'samesite'=>'Strict']);
    reply(true, ['message' => 'Password berhasil diubah.']);
}

if ($action === 'save_petugas') {
    require_admin();
    $id = (int)($data['id'] ?? 0);
    $username = trim((string)($data['username'] ?? ''));
    $display = trim((string)($data['display_name'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) reply(false, ['error' => 'Username 3-40 karakter: huruf, angka, titik, garis bawah, atau strip.'], 422);
    if ($display === '' || mb_strlen($display) > 80) reply(false, ['error' => 'Nama petugas wajib diisi.'], 422);

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $stmt->execute([':id'=>$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing || $existing['role'] !== 'petugas') reply(false, ['error' => 'Akun petugas tidak ditemukan.'], 404);
            if ($password !== '' && strlen($password) < 8) reply(false, ['error' => 'Password minimal 8 karakter.'], 422);

            $sql = 'UPDATE users SET username = :username, display_name = :display_name, updated_at = :updated_at';
            $params = [':username'=>$username, ':display_name'=>$display, ':updated_at'=>gmdate('c'), ':id'=>$id];
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            reply(true, ['message' => 'Akun petugas diperbarui.']);
        }

        if (strlen($password) < 8) reply(false, ['error' => 'Password petugas minimal 8 karakter.'], 422);
        $stmt = $pdo->prepare("INSERT INTO users (username, display_name, role, password_hash, active, created_at, updated_at)
            VALUES (:username, :display_name, 'petugas', :password_hash, 1, :created_at, :updated_at)");
        $now = gmdate('c');
        $stmt->execute([
            ':username'=>$username, ':display_name'=>$display, ':password_hash'=>password_hash($password, PASSWORD_DEFAULT),
            ':created_at'=>$now, ':updated_at'=>$now
        ]);
        reply(true, ['message' => 'Akun petugas berhasil dibuat.']);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 19) reply(false, ['error' => 'Username sudah digunakan.'], 422);
        reply(false, ['error' => 'Gagal menyimpan akun petugas.'], 500);
    }
}

if ($action === 'delete_petugas') {
    require_admin();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) reply(false, ['error' => 'Akun tidak valid.'], 422);
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'petugas'");
    $stmt->execute([':id'=>$id]);
    if ($stmt->rowCount() < 1) reply(false, ['error'=>'Akun petugas tidak ditemukan.'], 404);
    reply(true, ['message'=>'Akun petugas dihapus.']);
}

if ($action === 'toggle_petugas') {
    require_admin();
    $id = (int)($data['id'] ?? 0);
    $active = !empty($data['active']) ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE users SET active = :active, updated_at = :updated_at WHERE id = :id AND role = 'petugas'");
    $stmt->execute([':active'=>$active, ':updated_at'=>gmdate('c'), ':id'=>$id]);
    if ($stmt->rowCount() < 1) reply(false, ['error'=>'Akun petugas tidak ditemukan.'], 404);
    reply(true, ['message'=>$active ? 'Akun petugas diaktifkan.' : 'Akun petugas dinonaktifkan.']);
}

reply(false, ['error' => 'Aksi tidak dikenal.'], 400);
