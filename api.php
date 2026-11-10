<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    if (!is_writable((string)ini_get('session.save_path'))) {
        @ini_set('session.save_path', sys_get_temp_dir());
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

$dbDir = __DIR__ . '/database';
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0775, true);
}
$backupDir = $dbDir . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}
$fallbackFile = $dbDir . '/app_state.json';
$dbFile = __DIR__ . '/database.sqlite';

$sqliteAvailable = false;
$pdo = null;

if (extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            state_json TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_state_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            state_json TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            note TEXT
        )');
        $sqliteAvailable = true;
    } catch (Throwable $e) {
        $sqliteAvailable = false;
    }
}

// Helper to create timestamped server backup file
function createServerBackup(string $backupDir, string $json, string $prefix = 'backup_auto'): string {
    $date = date('Y-m-d_His');
    $filename = "{$prefix}_{$date}.json";
    $filepath = "{$backupDir}/{$filename}";
    @file_put_contents($filepath, $json, LOCK_EX);
    @file_put_contents("{$backupDir}/backup_latest.json", $json, LOCK_EX);

    $dailyName = "backup_daily_" . date('Y-m-d') . ".json";
    if (!file_exists("{$backupDir}/{$dailyName}")) {
        @file_put_contents("{$backupDir}/{$dailyName}", $json, LOCK_EX);
    }

    // Keep up to 40 rolling backups
    $files = glob("{$backupDir}/backup_auto_*.json") ?: [];
    if (count($files) > 40) {
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
        while (count($files) > 40) {
            $oldest = array_shift($files);
            @unlink($oldest);
        }
    }
    return $filename;
}

// Helper to get current state
function getAppState(bool $sqliteAvailable, ?PDO $pdo, string $fallbackFile): array {
    if ($sqliteAvailable && $pdo) {
        try {
            $row = $pdo->query('SELECT state_json, updated_at FROM app_state WHERE id = 1')->fetch();
            if ($row && !empty($row['state_json'])) {
                $decoded = json_decode($row['state_json'], true);
                if (is_array($decoded)) {
                    return ['data' => $decoded, 'json' => $row['state_json'], 'updated_at' => $row['updated_at']];
                }
            }
        } catch (Throwable $t) {}
    }

    if (file_exists($fallbackFile)) {
        $raw = file_get_contents($fallbackFile);
        if ($raw) {
            $fileData = json_decode($raw, true);
            if (is_array($fileData)) {
                if (isset($fileData['data']) && is_array($fileData['data'])) {
                    return [
                        'data' => $fileData['data'],
                        'json' => json_encode($fileData['data'], JSON_UNESCAPED_UNICODE),
                        'updated_at' => $fileData['updated_at'] ?? date('c')
                    ];
                }
                return ['data' => $fileData, 'json' => $raw, 'updated_at' => date('c', filemtime($fallbackFile))];
            }
        }
    }
    return ['data' => null, 'json' => null, 'updated_at' => null];
}

// Helper to save current state
function saveAppState(bool $sqliteAvailable, ?PDO $pdo, string $fallbackFile, string $backupDir, string $json, string $note = ''): bool {
    // 1. Write to resilient fallback file
    $envelope = json_encode([
        'updated_at' => gmdate('c'),
        'data' => json_decode($json, true)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($fallbackFile, $envelope, LOCK_EX);

    // 2. Write to SQLite if available
    if ($sqliteAvailable && $pdo) {
        try {
            $stmt = $pdo->prepare('INSERT INTO app_state (id, state_json, updated_at)
                VALUES (1, :s, :u)
                ON CONFLICT(id) DO UPDATE SET state_json = excluded.state_json, updated_at = excluded.updated_at');
            $stmt->execute([':s' => $json, ':u' => gmdate('c')]);

            // Save revision history
            $hist = $pdo->prepare('INSERT INTO app_state_history (state_json, updated_at, note) VALUES (:s, :u, :n)');
            $hist->execute([':s' => $json, ':u' => gmdate('c'), ':n' => $note ?: 'Simpan otomatis']);
            $pdo->exec('DELETE FROM app_state_history WHERE id NOT IN (SELECT id FROM app_state_history ORDER BY id DESC LIMIT 50)');
        } catch (Throwable $t) {}
    }

    // 3. Create file snapshot
    createServerBackup($backupDir, $json, 'backup_auto');
    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

// 1. Download full JSON backup file
if ($method === 'GET' && in_array($action, ['export_backup', 'download_backup', 'download_json'], true)) {
    $cur = getAppState($sqliteAvailable, $pdo, $fallbackFile);
    $dataJson = $cur['json'] ?: '{}';
    $yearStr = date('Y');
    if (!empty($cur['data']['activeYear'])) {
        $yearStr = preg_replace('/[^a-zA-Z0-9]/', '_', (string)$cur['data']['activeYear']);
    }

    $filename = "cadangan_qurban_pdkqu_{$yearStr}_" . date('Y-m-d_His') . ".json";
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dataJson));
    echo $dataJson;
    exit;
}

// 2. Download physical SQLite database file (Super Admin / Admin only)
if ($method === 'GET' && $action === 'export_sqlite') {
    if (file_exists($dbFile)) {
        $filename = "database_qurban_" . date('Y-m-d_His') . ".sqlite";
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string)filesize($dbFile));
        readfile($dbFile);
        exit;
    }
    http_response_code(404);
    echo "Database file not found.";
    exit;
}

// 3. List server-side automated backups
if ($method === 'GET' && $action === 'list_backups') {
    header('Content-Type: application/json; charset=utf-8');
    $list = [];
    $files = glob("{$backupDir}/*.json") ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($files as $f) {
        $bn = basename($f);
        if ($bn === 'backup_latest.json') continue;
        $list[] = [
            'filename' => $bn,
            'size' => filesize($f),
            'modified' => date('c', filemtime($f)),
            'date_formatted' => date('d M Y, H:i', filemtime($f)),
            'type' => str_starts_with($bn, 'backup_daily') ? 'Harian' : (str_starts_with($bn, 'emergency') ? 'Darurat' : (str_starts_with($bn, 'backup_pre') ? 'Pra-Pulihkan' : 'Otomatis'))
        ];
    }
    echo json_encode(['ok' => true, 'backups' => $list]);
    exit;
}

// 4. Normal GET (Load state)
if ($method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $cur = getAppState($sqliteAvailable, $pdo, $fallbackFile);
    echo json_encode([
        'ok' => true,
        'data' => $cur['data'],
        'updated_at' => $cur['updated_at'],
        'storage_type' => $sqliteAvailable ? 'sqlite' : 'file_json'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Restore from a server snapshot
if ($method === 'POST' && $action === 'restore_snapshot') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $req = json_decode($raw ?: '', true);
    $targetFile = basename((string)($req['filename'] ?? ''));
    $targetPath = "{$backupDir}/{$targetFile}";

    if (!$targetFile || !file_exists($targetPath)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Berkas cadangan tidak ditemukan di server.']);
        exit;
    }

    $snapshotContent = file_get_contents($targetPath);
    $snapshotData = json_decode($snapshotContent ?: '', true);
    if (!is_array($snapshotData)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Isi berkas cadangan rusak atau tidak valid.']);
        exit;
    }

    // Archive current state first before restoring
    $current = getAppState($sqliteAvailable, $pdo, $fallbackFile);
    if ($current['json']) {
        createServerBackup($backupDir, $current['json'], 'backup_pre_restore');
    }

    saveAppState($sqliteAvailable, $pdo, $fallbackFile, $backupDir, (string)$snapshotContent, 'Pulihkan dari snapshot: ' . $targetFile);

    echo json_encode(['ok' => true, 'message' => 'Data berhasil dipulihkan dari cadangan server.', 'data' => $snapshotData]);
    exit;
}

// 6. Restore from uploaded JSON data
if ($method === 'POST' && ($action === 'restore_backup' || $action === 'restore_upload')) {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Format berkas cadangan tidak valid (harus JSON).']);
        exit;
    }

    $allowed = [
        'activeYear','availableYears','agendas','panitia','mudhohi','mustahiq','hasil',
        'suratRapatDB','suratEdaranDB','suratTugasDB','customConfigs','archives'
    ];
    $state = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) $state[$key] = $data[$key];
    }

    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!$json) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Gagal mengurai struktur data cadangan.']);
        exit;
    }

    // Archive current state first
    $current = getAppState($sqliteAvailable, $pdo, $fallbackFile);
    if ($current['json']) {
        createServerBackup($backupDir, $current['json'], 'backup_pre_restore');
    }

    saveAppState($sqliteAvailable, $pdo, $fallbackFile, $backupDir, $json, 'Pulihkan dari unggahan cadangan');

    echo json_encode(['ok' => true, 'message' => 'Data berhasil dipulihkan secara utuh!', 'data' => $state]);
    exit;
}

// 7. Normal POST (Save state)
if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $allowed = [
        'activeYear','availableYears','agendas','panitia','mudhohi','mustahiq','hasil',
        'suratRapatDB','suratEdaranDB','suratTugasDB','customConfigs','archives'
    ];
    $state = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $data)) $state[$key] = $data[$key];
    }

    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Could not encode application data']);
        exit;
    }

    // Emergency wipe detection: if previous had records and new has zero, keep emergency backup
    $current = getAppState($sqliteAvailable, $pdo, $fallbackFile);
    if ($current['data'] && !empty($current['data']['mudhohi']) && count($current['data']['mudhohi']) > 0) {
        if (empty($state['mudhohi']) || count($state['mudhohi']) === 0) {
            createServerBackup($backupDir, $current['json'], 'emergency_pre_wipe');
        }
    }

    saveAppState($sqliteAvailable, $pdo, $fallbackFile, $backupDir, $json, 'Simpan otomatis aplikasi');

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
