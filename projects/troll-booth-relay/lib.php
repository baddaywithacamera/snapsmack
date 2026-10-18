<?php
// SNAPSMACK_EOF_HEADER
declare(strict_types=1);

function tb_config(): array {
    static $cfg;
    if ($cfg === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) throw new RuntimeException('Relay is not configured');
        $cfg = require $path;
        if (!is_array($cfg)) throw new RuntimeException('Invalid relay configuration');
    }
    return $cfg;
}

function tb_db(): PDO {
    static $db;
    if ($db === null) {
        $c = tb_config();
        $dsn = 'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4';
        $db = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $db;
}

function tb_json(int $status, ?array $body): never {
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function tb_body(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 0 || $length > 65536) tb_json(413, ['error' => 'Request too large']);
    $raw = file_get_contents('php://input');
    $value = json_decode($raw === '' ? '{}' : $raw, true);
    if (!is_array($value)) tb_json(400, ['error' => 'JSON object required']);
    return $value;
}

function tb_bearer(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
}

function tb_actor(): ?string {
    $token = tb_bearer();
    foreach ((tb_config()['actors'] ?? []) as $actor => $expected) {
        if (is_string($expected) && strlen($expected) >= 32 && hash_equals($expected, $token)) {
            return (string)$actor;
        }
    }
    return null;
}

function tb_device(): ?array {
    $deviceId = trim((string)($_SERVER['HTTP_X_TROLL_DEVICE'] ?? ''));
    $token = tb_bearer();
    if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $deviceId) || strlen($token) < 32) return null;
    $q = tb_db()->prepare('SELECT * FROM troll_devices WHERE device_id=? AND enabled=1');
    $q->execute([$deviceId]);
    $row = $q->fetch();
    if (!$row) {
        $configured = tb_config()['devices'][$deviceId] ?? '';
        if (!is_string($configured) || strlen($configured) < 32 || !hash_equals($configured, $token)) return null;
        $insert = tb_db()->prepare("INSERT INTO troll_devices(device_id,token_hash,tools_json,last_seen_at) VALUES(?,?, '[]', NOW())");
        $insert->execute([$deviceId, hash('sha256', $token)]);
        $q->execute([$deviceId]);
        $row = $q->fetch();
    }
    return $row && hash_equals($row['token_hash'], hash('sha256', $token)) ? $row : null;
}

function tb_audit(string $actor, string $event, string $subject, string $outcome): void {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $q = tb_db()->prepare('INSERT INTO troll_audit(actor,event_name,subject,outcome,remote_hash) VALUES(?,?,?,?,?)');
    $q->execute([$actor, $event, substr($subject, 0, 190), $outcome,
                 $remote === '' ? null : hash('sha256', $remote)]);
}

function tb_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function tb_tools(): array {
    $rows = tb_db()->query("SELECT tools_json FROM troll_devices WHERE enabled=1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)")->fetchAll();
    $tools = [];
    foreach ($rows as $row) {
        $list = json_decode($row['tools_json'], true);
        if (!is_array($list)) continue;
        foreach ($list as $tool) {
            if (is_array($tool) && isset($tool['name'])) $tools[$tool['name']] = $tool;
        }
    }
    ksort($tools);
    return array_values($tools);
}

function tb_mcp_error(mixed $id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function tb_mcp_result(mixed $id, array $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}
// ===== SNAPSMACK EOF =====
