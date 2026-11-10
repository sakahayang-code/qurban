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

if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

$dbFile = __DIR__ . '/database.sqlite';
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
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SQLite unavailable: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $row = $pdo->query('SELECT state_json, updated_at FROM app_state WHERE id = 1')->fetch();
    if (!$row) {
        echo json_encode(['ok' => true, 'data' => null]);
        exit;
    }
    $data = json_decode($row['state_json'], true);
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Stored SQLite state is invalid']);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => $data, 'updated_at' => $row['updated_at']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // Only persist application data; UI-only state is intentionally excluded.
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

    $stmt = $pdo->prepare('INSERT INTO app_state (id, state_json, updated_at)
        VALUES (1, :state_json, :updated_at)
        ON CONFLICT(id) DO UPDATE SET state_json = excluded.state_json, updated_at = excluded.updated_at');
    $stmt->execute([
        ':state_json' => $json,
        ':updated_at' => gmdate('c'),
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
