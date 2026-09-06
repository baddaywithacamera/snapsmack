<?php
/**
 * SNAPSMACK — SNAP HQ device authorization API.
 *
 * Activation codes are shown once and consumed once.  The activated machine
 * subsequently authenticates by signing each request with its Ed25519 key.
 * The CMS is authoritative and signs the cached entitlement returned to HQ.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function da_out(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
function da_b64u(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function da_unb64u(string $text): string|false {
    $text = strtr($text, '-_', '+/');
    return base64_decode($text . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
}
function da_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) da_out(['ok' => false, 'error' => 'A JSON request body is required.'], 400);
    return [$data, $raw];
}
function da_ip(): string {
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($cf, FILTER_VALIDATE_IP) ? $cf : (filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '');
}
function da_uuid(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function da_ensure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_desktop_activation_keys (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, key_hash CHAR(64) NOT NULL, key_prefix VARCHAR(12) NOT NULL,
      label VARCHAR(100) NOT NULL DEFAULT 'SNAP HQ device', created_by_user_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL,
      consumed_at DATETIME NULL, consumed_device_id CHAR(36) NULL, PRIMARY KEY(id), UNIQUE KEY uq_desktop_activation_hash(key_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_desktop_devices (
      id CHAR(36) NOT NULL, user_id INT UNSIGNED NULL, public_key VARCHAR(100) NOT NULL, fingerprint CHAR(64) NOT NULL,
      device_name VARCHAR(120) NOT NULL DEFAULT 'SNAP HQ device', locale_name VARCHAR(40) NULL, timezone_name VARCHAR(80) NULL,
      os_name VARCHAR(160) NULL, hq_version VARCHAR(40) NULL, first_ip VARCHAR(45) NULL, last_ip VARCHAR(45) NULL,
      status ENUM('active','disabled','blocked') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      first_used_at DATETIME NULL, last_used_at DATETIME NULL, term_started_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
      grace_ends_at DATETIME NOT NULL, disabled_at DATETIME NULL, blocked_at DATETIME NULL, token_version INT UNSIGNED NOT NULL DEFAULT 1,
      PRIMARY KEY(id), UNIQUE KEY uq_desktop_device_fingerprint(fingerprint), KEY idx_desktop_device_status(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_desktop_nonces (
      device_id CHAR(36) NOT NULL, nonce_hash CHAR(64) NOT NULL, seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(device_id,nonce_hash), KEY idx_desktop_nonce_seen(seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function da_setting(PDO $pdo, string $key): ?string {
    $q = $pdo->prepare('SELECT setting_val FROM snap_settings WHERE setting_key=? LIMIT 1'); $q->execute([$key]);
    $v = $q->fetchColumn(); return $v === false ? null : (string)$v;
}
function da_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO snap_settings(setting_key,setting_val) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)')->execute([$key,$value]);
}
function da_signing(PDO $pdo): array {
    if (!function_exists('sodium_crypto_sign_keypair')) da_out(['ok'=>false,'error'=>'This server needs the PHP Sodium extension for device authorization.'],503);
    $secret = da_setting($pdo, 'desktop_auth_signing_secret');
    $public = da_setting($pdo, 'desktop_auth_signing_public');
    if ($secret && $public) {
        $sk = base64_decode($secret, true); $pk = base64_decode($public, true);
        if ($sk !== false && $pk !== false) return [$sk,$pk];
    }
    $pair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($pair); $pk = sodium_crypto_sign_publickey($pair);
    da_set($pdo, 'desktop_auth_signing_secret', base64_encode($sk)); da_set($pdo, 'desktop_auth_signing_public', base64_encode($pk));
    return [$sk,$pk];
}
function da_install_id(PDO $pdo): string {
    $id = da_setting($pdo, 'desktop_auth_install_id');
    if (!$id) { $id = da_uuid(); da_set($pdo, 'desktop_auth_install_id', $id); }
    return $id;
}
function da_token(PDO $pdo, array $device): array {
    [$sk,$pk] = da_signing($pdo);
    $payload = [
      'v'=>1, 'installation_id'=>da_install_id($pdo), 'device_id'=>$device['id'], 'fingerprint'=>$device['fingerprint'],
      'issued_at'=>time(), 'term_started_at'=>strtotime($device['term_started_at']), 'expires_at'=>strtotime($device['expires_at']),
      'grace_ends_at'=>strtotime($device['grace_ends_at']), 'token_version'=>(int)$device['token_version'],
      'capabilities'=>['snap_slapper_full','lewk_again_full']
    ];
    $encoded = da_b64u(json_encode($payload, JSON_UNESCAPED_SLASHES));
    return ['payload'=>$encoded,'signature'=>da_b64u(sodium_crypto_sign_detached($encoded,$sk)),'server_public_key'=>base64_encode($pk)];
}
function da_auth_device(PDO $pdo, string $raw): array {
    $id = trim((string)($_SERVER['HTTP_X_SNAP_DEVICE'] ?? ''));
    $stamp = trim((string)($_SERVER['HTTP_X_SNAP_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_X_SNAP_NONCE'] ?? ''));
    $sig64 = trim((string)($_SERVER['HTTP_X_SNAP_SIGNATURE'] ?? ''));
    if (!$id || !ctype_digit($stamp) || !$nonce || !$sig64 || abs(time()-(int)$stamp)>300) da_out(['ok'=>false,'error'=>'Invalid or stale device proof.'],401);
    $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE id=? LIMIT 1'); $q->execute([$id]); $d=$q->fetch(PDO::FETCH_ASSOC);
    if (!$d) da_out(['ok'=>false,'error'=>'Unknown SNAP HQ device.'],401);
    if ($d['status']==='blocked') da_out(['ok'=>false,'error'=>'This device is blocked.'],403);
    if ($d['status']!=='active') da_out(['ok'=>false,'error'=>'This device is disabled. Authorize it again with a new one-use key.'],403);
    $pk=base64_decode($d['public_key'],true); $sig=da_unb64u($sig64);
    $path=(string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
    $proof=strtoupper($_SERVER['REQUEST_METHOD']??'POST')."\n".$path."\n".$stamp."\n".$nonce."\n".hash('sha256',$raw);
    if ($pk===false || $sig===false || !sodium_crypto_sign_verify_detached($sig,$proof,$pk)) da_out(['ok'=>false,'error'=>'Device signature rejected.'],401);
    try { $pdo->prepare('INSERT INTO snap_desktop_nonces(device_id,nonce_hash) VALUES(?,?)')->execute([$id,hash('sha256',$nonce)]); }
    catch (PDOException $e) { da_out(['ok'=>false,'error'=>'Request replay rejected.'],409); }
    $pdo->exec("DELETE FROM snap_desktop_nonces WHERE seen_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    return $d;
}

da_ensure($pdo);
$action = strtolower((string)basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)));
if (isset($_GET['route'])) $action = strtolower((string)basename(trim((string)$_GET['route'],'/')));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') da_out(['ok'=>false,'error'=>'POST required.'],405);
[$data,$raw]=da_body();

if ($action === 'activate') {
    if (!function_exists('sodium_crypto_sign_verify_detached')) da_out(['ok'=>false,'error'=>'This server needs the PHP Sodium extension for device authorization.'],503);
    $code=preg_replace('/\s+/','',(string)($data['activation_code']??''));
    $pk64=trim((string)($data['public_key']??'')); $pk=base64_decode($pk64,true);
    if (strlen($code)<32 || $pk===false || strlen($pk)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) da_out(['ok'=>false,'error'=>'Invalid activation code or device public key.'],400);
    $fingerprint=hash('sha256',$pk); $pdo->beginTransaction();
    try {
      $q=$pdo->prepare('SELECT * FROM snap_desktop_activation_keys WHERE key_hash=? FOR UPDATE'); $q->execute([hash('sha256',$code)]); $key=$q->fetch(PDO::FETCH_ASSOC);
      if (!$key || $key['consumed_at'] || strtotime($key['expires_at'])<=time()) throw new RuntimeException('Activation code is invalid, expired, or already used.');
      $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE fingerprint=? FOR UPDATE'); $q->execute([$fingerprint]); $existing=$q->fetch(PDO::FETCH_ASSOC);
      if ($existing && $existing['status']==='blocked') throw new RuntimeException('This device identity is blocked.');
      $active=(int)$pdo->query("SELECT COUNT(*) FROM snap_desktop_devices WHERE status='active'")->fetchColumn();
      if ((!$existing || $existing['status']!=='active') && $active>=4) throw new RuntimeException('Four devices are already active. Disable one before adding another.');
      $id=$existing['id']??da_uuid(); $now=date('Y-m-d H:i:s'); $exp=date('Y-m-d H:i:s',strtotime('+3 months')); $grace=date('Y-m-d H:i:s',strtotime($exp.' +14 days')); $ip=da_ip();
      $meta=[substr(trim((string)($data['device_name']??'SNAP HQ device')),0,120),substr(trim((string)($data['locale']??'')),0,40),substr(trim((string)($data['timezone']??'')),0,80),substr(trim((string)($data['os']??'')),0,160),substr(trim((string)($data['hq_version']??'')),0,40)];
      if ($existing) {
        $pdo->prepare("UPDATE snap_desktop_devices SET user_id=?,public_key=?,device_name=?,locale_name=?,timezone_name=?,os_name=?,hq_version=?,last_ip=?,status='active',term_started_at=?,expires_at=?,grace_ends_at=?,disabled_at=NULL,token_version=token_version+1 WHERE id=?")
          ->execute([$key['created_by_user_id'],$pk64,...$meta,$ip,$now,$exp,$grace,$id]);
      } else {
        $pdo->prepare("INSERT INTO snap_desktop_devices(id,user_id,public_key,fingerprint,device_name,locale_name,timezone_name,os_name,hq_version,first_ip,last_ip,status,term_started_at,expires_at,grace_ends_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'active',?,?,?)")
          ->execute([$id,$key['created_by_user_id'],$pk64,$fingerprint,...$meta,$ip,$ip,$now,$exp,$grace]);
      }
      $pdo->prepare('UPDATE snap_desktop_activation_keys SET consumed_at=NOW(),consumed_device_id=? WHERE id=?')->execute([$id,$key['id']]);
      $pdo->commit(); $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE id=?');$q->execute([$id]);$d=$q->fetch(PDO::FETCH_ASSOC);
      da_out(['ok'=>true,'device_id'=>$id,'active_devices'=>$active+((!$existing||$existing['status']!=='active')?1:0),'limit'=>4,'entitlement'=>da_token($pdo,$d)]);
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); da_out(['ok'=>false,'error'=>$e->getMessage()],409); }
}

if (in_array($action,['status','renew'],true)) {
    $d=da_auth_device($pdo,$raw); $ip=da_ip();
    // Keep an actively checking installation licensed three months ahead.  A
    // disabled/blocked device cannot reach this point, while an offline device
    // naturally ages into its signed 14-day grace period.
    if ($action === 'renew' || strtotime($d['expires_at']) <= strtotime('+30 days')) {
        $term=date('Y-m-d H:i:s'); $exp=date('Y-m-d H:i:s',strtotime('+3 months')); $grace=date('Y-m-d H:i:s',strtotime($exp.' +14 days'));
        $pdo->prepare('UPDATE snap_desktop_devices SET term_started_at=?,expires_at=?,grace_ends_at=?,token_version=token_version+1 WHERE id=?')
            ->execute([$term,$exp,$grace,$d['id']]);
    }
    $pdo->prepare('UPDATE snap_desktop_devices SET first_used_at=COALESCE(first_used_at,NOW()),last_used_at=NOW(),last_ip=?,device_name=COALESCE(NULLIF(?,\'\'),device_name),locale_name=COALESCE(NULLIF(?,\'\'),locale_name),timezone_name=COALESCE(NULLIF(?,\'\'),timezone_name),os_name=COALESCE(NULLIF(?,\'\'),os_name),hq_version=COALESCE(NULLIF(?,\'\'),hq_version) WHERE id=?')
      ->execute([$ip,substr((string)($data['device_name']??''),0,120),substr((string)($data['locale']??''),0,40),substr((string)($data['timezone']??''),0,80),substr((string)($data['os']??''),0,160),substr((string)($data['hq_version']??''),0,40),$d['id']]);
    $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE id=?');$q->execute([$d['id']]);$d=$q->fetch(PDO::FETCH_ASSOC);
    da_out(['ok'=>true,'status'=>$d['status'],'entitlement'=>da_token($pdo,$d)]);
}

da_out(['ok'=>false,'error'=>'Unknown desktop authorization action.'],404);
// ===== SNAPSMACK EOF =====
