<?php
// SNAPSMACK_EOF_HEADER
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/' && $method === 'GET') {
    require __DIR__ . '/dashboard.php';
    exit;
}

if ($path === '/health' && $method === 'GET') {
    $online = (int)tb_db()->query("SELECT COUNT(*) FROM troll_devices WHERE enabled=1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)")->fetchColumn();
    tb_json(200, ['ok' => true, 'online_devices' => $online]);
}

if ($path === '/mcp' && $method === 'POST') {
    $actor = tb_actor();
    if ($actor === null) tb_json(401, tb_mcp_error(null, -32001, 'Unauthorized'));
    $body = tb_body();
    $id = $body['id'] ?? null;
    $rpcMethod = $body['method'] ?? '';
    if (($body['jsonrpc'] ?? '') !== '2.0') tb_json(400, tb_mcp_error($id, -32600, 'Invalid Request'));
    if ($rpcMethod === 'notifications/initialized' && $id === null) tb_json(202, null);
    if ($id === null || (!is_string($id) && !is_int($id) && !is_float($id))) {
        tb_json(400, tb_mcp_error(null, -32600, 'Scalar request id required'));
    }
    if ($rpcMethod === 'initialize') {
        $requested = $body['params']['protocolVersion'] ?? '2025-11-25';
        $version = in_array($requested, ['2025-11-25', '2026-07-28'], true) ? $requested : '2025-11-25';
        tb_json(200, tb_mcp_result($id, [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'TROLL BOOTH', 'version' => '0.3.0'],
            'instructions' => 'Read-only local SnapSmack operations approved by Sean.',
        ]));
    }
    if ($rpcMethod === 'ping') tb_json(200, tb_mcp_result($id, []));
    if ($rpcMethod === 'tools/list') tb_json(200, tb_mcp_result($id, ['tools' => tb_tools()]));
    if ($rpcMethod !== 'tools/call') tb_json(200, tb_mcp_error($id, -32601, 'Method not found'));

    $tool = $body['params']['name'] ?? '';
    $arguments = $body['params']['arguments'] ?? [];
    $available = array_column(tb_tools(), null, 'name');
    if (!is_string($tool) || !isset($available[$tool]) || !is_array($arguments)) {
        tb_json(200, tb_mcp_error($id, -32602, 'Unknown tool or invalid arguments'));
    }
    $jobId = tb_uuid();
    $ttl = max(30, min(300, (int)(tb_config()['job_ttl_seconds'] ?? 120)));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
    $q = tb_db()->prepare("INSERT INTO troll_jobs(id,request_id,actor,tool_name,arguments_json,expires_at) VALUES(?,?,?,?,?,?)");
    $q->execute([$jobId, (string)$id, $actor, $tool,
                 json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $expiresAt]);
    tb_audit($actor, 'tool.queue', $tool, 'queued');
    $wait = max(1, min(30, (int)(tb_config()['tool_wait_seconds'] ?? 25)));
    $deadline = microtime(true) + $wait;
    do {
        usleep(250000);
        $check = tb_db()->prepare('SELECT status,result_json FROM troll_jobs WHERE id=?');
        $check->execute([$jobId]);
        $job = $check->fetch();
        if ($job && in_array($job['status'], ['done', 'failed'], true)) {
            $result = json_decode((string)$job['result_json'], true);
            if (!is_array($result)) $result = ['ok' => false, 'error' => 'Invalid local result'];
            tb_json(200, tb_mcp_result($id, [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'structuredContent' => $result,
                'isError' => $job['status'] !== 'done' || !($result['ok'] ?? false),
            ]));
        }
    } while (microtime(true) < $deadline);
    tb_json(200, tb_mcp_result($id, [
        'content' => [['type' => 'text', 'text' => 'Local TROLL BOOTH is offline, locked, or did not answer in time.']],
        'isError' => true,
    ]));
}

if ($path === '/agent/register' && $method === 'POST') {
    $device = tb_device();
    if ($device === null) tb_json(401, ['ok' => false, 'error' => 'Unauthorized device']);
    $body = tb_body();
    $tools = $body['tools'] ?? null;
    if (!is_array($tools) || count($tools) > 100) tb_json(400, ['ok' => false, 'error' => 'Invalid tool manifest']);
    $encoded = json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($encoded) > 131072) tb_json(400, ['ok' => false, 'error' => 'Tool manifest too large']);
    $q = tb_db()->prepare('UPDATE troll_devices SET tools_json=?,last_seen_at=NOW() WHERE device_id=?');
    $q->execute([$encoded, $device['device_id']]);
    tb_json(200, ['ok' => true]);
}

if ($path === '/agent/next' && $method === 'POST') {
    $device = tb_device();
    if ($device === null) tb_json(401, ['ok' => false, 'error' => 'Unauthorized device']);
    tb_db()->beginTransaction();
    try {
        tb_db()->exec("UPDATE troll_jobs SET status='expired' WHERE status='queued' AND expires_at < NOW()");
        $q = tb_db()->query("SELECT * FROM troll_jobs WHERE status='queued' AND expires_at >= NOW() ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED");
        $job = $q->fetch();
        if ($job) {
            $claim = tb_db()->prepare("UPDATE troll_jobs SET status='claimed',device_id=?,claimed_at=NOW() WHERE id=? AND status='queued'");
            $claim->execute([$device['device_id'], $job['id']]);
        }
        $seen = tb_db()->prepare('UPDATE troll_devices SET last_seen_at=NOW() WHERE device_id=?');
        $seen->execute([$device['device_id']]);
        tb_db()->commit();
    } catch (Throwable $e) {
        tb_db()->rollBack();
        throw $e;
    }
    if (!$job) tb_json(200, ['ok' => true, 'job' => null]);
    tb_json(200, ['ok' => true, 'job' => [
        'id' => $job['id'], 'actor' => $job['actor'], 'tool' => $job['tool_name'],
        'arguments' => json_decode($job['arguments_json'], true) ?: [],
    ]]);
}

if ($path === '/agent/result' && $method === 'POST') {
    $device = tb_device();
    if ($device === null) tb_json(401, ['ok' => false, 'error' => 'Unauthorized device']);
    $body = tb_body();
    $jobId = $body['job_id'] ?? '';
    $result = $body['result'] ?? null;
    if (!is_string($jobId) || !preg_match('/^[0-9a-f-]{36}$/', $jobId) || !is_array($result)) {
        tb_json(400, ['ok' => false, 'error' => 'Invalid result']);
    }
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strlen($encoded) > 2097152) tb_json(400, ['ok' => false, 'error' => 'Result too large']);
    $status = ($result['ok'] ?? false) ? 'done' : 'failed';
    $q = tb_db()->prepare("UPDATE troll_jobs SET status=?,result_json=?,completed_at=NOW() WHERE id=? AND device_id=? AND status='claimed'");
    $q->execute([$status, $encoded, $jobId, $device['device_id']]);
    if ($q->rowCount() !== 1) tb_json(409, ['ok' => false, 'error' => 'Job was not claimed by this device']);
    tb_audit($device['device_id'], 'tool.result', $jobId, $status);
    tb_json(200, ['ok' => true]);
}

tb_json(404, ['ok' => false, 'error' => 'Not found']);
// ===== SNAPSMACK EOF =====
