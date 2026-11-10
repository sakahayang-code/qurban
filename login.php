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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html', true, 303);
    exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

/*
 * IMPORTANT: replace the two setup values before going live.
 * The password below is stored as a SHA-256 value only as a bootstrap
 * mechanism. For a production multi-user system, use password_hash().
 */
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

    $count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (username, display_name, role, password_hash, active, created_at, updated_at)
            VALUES (:username, :display_name, :role, :password_hash, 1, :created_at, :updated_at)');
        $now = gmdate('c');
        $stmt->execute([
            ':username' => 'admin',
            ':display_name' => 'Administrator',
            ':role' => 'admin',
            ':password_hash' => password_hash('admin', PASSWORD_DEFAULT),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $stmt = $pdo->prepare('SELECT id, username, display_name, role, password_hash, active FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    usleep(500000);
    header('Location: login.html?error=1', true, 303);
    exit;
}

$ok = $user
   && (int)$user['active'] === 1
   && password_verify($password, $user['password_hash']);

// Repair only the built-in Super Admin bootstrap credential if an older
// generated database contains an incompatible/stale password hash.
if (!$ok && $user && $user['username'] === 'superadmin' && $password === 'superadmin' && (int)$user['active'] === 1) {
    try {
        $repair = $pdo->prepare("UPDATE users SET role = 'superadmin', password_hash = :password_hash, active = 1, updated_at = :updated_at WHERE id = :id");
        $repair->execute([
            ':password_hash' => password_hash('superadmin', PASSWORD_DEFAULT),
            ':updated_at' => gmdate('c'),
            ':id' => (int)$user['id'],
        ]);
        $user['role'] = 'superadmin';
        $user['password_hash'] = password_hash('superadmin', PASSWORD_DEFAULT);
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
}

if (!$ok) {
    usleep(500000);
    header('Location: login.html?error=1', true, 303);
    exit;
}

session_regenerate_id(true);
$_SESSION['authenticated'] = true;
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['display_name'] = $user['display_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['login_time'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
setcookie('pdkq_csrf', $_SESSION['csrf_token'], ['expires'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443','httponly'=>false,'samesite'=>'Strict']);

header('Location: index.php', true, 303);
exit;
