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
require_once __DIR__ . '/totp.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function da_out(array $body, int $status = 200): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function da_auth_header(): string {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    return trim($header);
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

    // THE ENFORCEMENT STAMP IS SET HERE, ON THE FIRST REQUEST OF ANY KIND.
    //
    // SS-1418 r2, Codex's FAIL. I had it written lazily, from inside
    // da_in_remediation(), and that function is only reached when a device is
    // already expired -- $renewable short-circuits on current and grace. So
    // on a site whose devices are all healthy the stamp was never written,
    // and the FIRST device to expire, months later, created it at that
    // moment and collected the one-time remediation window for itself. A
    // window meant for devices stranded by the change became a standing
    // fortnight's reprieve for every device that ever expires. My test
    // pre-seeded the stamp and so could not see any of it.
    //
    // da_ensure() runs before the dispatch on every request to this
    // endpoint, so the stamp records when this CODE began running on this
    // site, which is what it was always supposed to mean.
    da_enforcement_began($pdo, time());
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
/**
 * HOW LONG A TERM IS, IN ONE PLACE. SS-1418.
 *
 * These were four copies of strtotime('+3 months') and '+14 days' spread
 * through enrol, activate and renew. Expiry rules that are written out four
 * times are expiry rules that drift, and two of the copies were the ones
 * handing an expired device a brand new term.
 */
if (!defined('DA_TERM_SECONDS'))        define('DA_TERM_SECONDS', 90 * 86400);        // three months
if (!defined('DA_GRACE_SECONDS'))       define('DA_GRACE_SECONDS', 14 * 86400);       // the signed offline window
if (!defined('DA_RENEW_WINDOW_SECONDS')) define('DA_RENEW_WINDOW_SECONDS', 30 * 86400); // how early a check-in may renew

/**
 * Where a device stands against its own term RIGHT NOW.
 *
 * SS-1418, Muse's finding: nothing asked this question. da_auth_device()
 * checked status, signature and nonce and then returned the row, so a device
 * whose term ended two years ago authenticated exactly like a current one.
 * Expiry was recorded and never read.
 *
 * @return array{state:string,expires_at:int,grace_ends_at:int,remaining:int}
 *         state is 'current' (inside the term), 'grace' (past expiry, inside
 *         the signed grace window) or 'expired' (past both).
 */
function da_term_state(array $device, int $now): array {
    $exp   = (int)strtotime((string)$device['expires_at']);
    $grace = (int)strtotime((string)$device['grace_ends_at']);
    if ($grace < $exp) {
        // A row whose grace ends before its own expiry is corrupt or was
        // written by hand. Treat the term as the longer of the two rather
        // than silently giving the device less than it was sold.
        $grace = $exp + DA_GRACE_SECONDS;
    }
    if ($now <= $exp)   $state = 'current';
    elseif ($now <= $grace) $state = 'grace';
    else                $state = 'expired';
    return ['state'=>$state, 'expires_at'=>$exp, 'grace_ends_at'=>$grace,
            'remaining'=>max(0, $grace - $now)];
}

/**
 * THE REMEDIATION WINDOW, AND WHY IT EXISTS.
 *
 * Enforcing expiry for the first time is not like enforcing it on day two.
 * Every device in the field was issued under code that never checked, so the
 * moment this lands some of them may already be past a grace window nobody
 * was counting. Refusing those outright would mean an owner opens HQ one
 * morning and finds it will not talk to their own site -- a lockout caused
 * by the fix, not by the fault.
 *
 * So the first run of this code records the moment enforcement began, and a
 * device whose grace ended BEFORE that moment gets one window of
 * DA_GRACE_SECONDS from it to renew itself. One window, anchored to a stamp
 * written once: it cannot be re-triggered, and a device that lets it lapse
 * is then held to the ordinary rules like any other.
 *
 * This is deliberately not silent. The response says renew_required, and the
 * desktop shows the prompt.
 */
function da_enforcement_began(PDO $pdo, int $now): int {
    $stamp = da_setting($pdo, 'desktop_auth_expiry_enforced_from');
    if ($stamp !== null && ctype_digit((string)$stamp)) {
        return (int)$stamp;
    }
    da_set($pdo, 'desktop_auth_expiry_enforced_from', (string)$now);
    return $now;
}

/** True while a device that was already past grace when enforcement began may still renew itself. */
function da_in_remediation(PDO $pdo, array $term, int $now): bool {
    if ($term['state'] !== 'expired') return false;
    $began = da_enforcement_began($pdo, $now);
    return $term['grace_ends_at'] < $began && $now <= $began + DA_GRACE_SECONDS;
}

/**
 * The next expiry for a renewal, ANCHORED TO THE PREVIOUS ONE.
 *
 * Muse's second finding: renew set expires_at to now + 3 months whatever the
 * device's prior term, so an expired device became a current one and expiry
 * meant nothing. Anchoring to the prior expiry stops the term being reset by
 * the act of asking; the cap stops a device that has renewed early and often
 * from stockpiling term beyond the documented three months.
 */
function da_next_expiry(int $prior_expiry, int $now): int {
    return min($prior_expiry + DA_TERM_SECONDS, $now + DA_TERM_SECONDS);
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
/**
 * @param bool $allow_expired TRUE only for the actions that EXIST to get an
 *        expired device back on its feet (status and renew). Everything else
 *        is refused once the grace window has run out.
 *
 *        THIS FLAG IS THE WHOLE LOCKOUT QUESTION. status and renew are
 *        reached THROUGH this function, so an expiry check that refuses here
 *        unconditionally would make renewal unreachable by the only device
 *        that needs it: the owner opens HQ, it is one day past grace, and
 *        the single endpoint that could fix that is the one now turning it
 *        away. The fix would have built a better lock than the fault did.
 *        So the check reports, and the caller decides.
 */
function da_auth_device(PDO $pdo, string $raw, bool $allow_expired = false): array {
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

    // EXPIRY, FINALLY READ. SS-1418. Until now the row's expires_at and
    // grace_ends_at were written by three code paths and consulted by none,
    // so a device whose term ended long ago authenticated like a current one.
    $now  = time();
    $term = da_term_state($d, $now);
    if ($term['state'] === 'expired' && !$allow_expired) {
        da_out(['ok'=>false, 'error'=>'This device authorization has expired. Open SNAP HQ and renew it.',
                'renew_required'=>true, 'expired_at'=>$term['expires_at']], 403);
    }
    $d['term_state'] = $term['state'];
    return $d;
}

da_ensure($pdo);
$action = strtolower((string)basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)));
if (isset($_GET['route'])) $action = strtolower((string)basename(trim((string)$_GET['route'],'/')));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') da_out(['ok'=>false,'error'=>'POST required.'],405);
[$data,$raw]=da_body();

if ($action === 'enroll') {
    // Seamless HQ enrolment: the saved Hub key identifies the desktop client;
    // the account password + TOTP prove the human owner for this consequential
    // action. Neither credential is stored by HQ or returned by this endpoint.
    $auth = da_auth_header();
    if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        da_out(['ok'=>false,'error'=>'A Hub API key is required.'],401);
    }
    $q=$pdo->prepare("SELECT user_id,is_active,expires_at FROM snap_ohsnap_keys WHERE key_hash=? AND key_type='hub' LIMIT 1");
    $q->execute([hash('sha256',trim($m[1]))]); $hub=$q->fetch(PDO::FETCH_ASSOC);
    if (!$hub || !(int)$hub['is_active'] || ($hub['expires_at'] && strtotime($hub['expires_at'])<=time())) {
        da_out(['ok'=>false,'error'=>'The saved Hub API key is invalid or expired.'],403);
    }
    $username=trim((string)($data['username']??''));
    $password=(string)($data['password']??'');
    $totp=preg_replace('/\s+/','',(string)($data['totp_code']??''));
    $q=$pdo->prepare('SELECT id,password_hash,totp_enabled,totp_secret FROM snap_users WHERE username=? LIMIT 1');
    $q->execute([$username]); $user=$q->fetch(PDO::FETCH_ASSOC);
    if (!$user || ($hub['user_id'] && (int)$hub['user_id']!==(int)$user['id'])) {
        da_out(['ok'=>false,'error'=>'These credentials do not match the saved Hub key.'],403);
    }
    if ($password==='' || !password_verify($password,(string)$user['password_hash'])) {
        da_out(['ok'=>false,'error'=>'Password incorrect.'],401);
    }
    if (empty($user['totp_enabled']) || empty($user['totp_secret'])) {
        da_out(['ok'=>false,'error'=>'Two-factor authentication must be enabled before authorizing a computer.'],403);
    }
    if ($totp==='' || !totp_verify((string)$user['totp_secret'],$totp)) {
        da_out(['ok'=>false,'error'=>'Authenticator code incorrect.'],401);
    }
    $pk64=trim((string)($data['public_key']??'')); $pk=base64_decode($pk64,true);
    if ($pk===false || strlen($pk)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        da_out(['ok'=>false,'error'=>'Invalid device public key.'],400);
    }
    $fingerprint=hash('sha256',$pk); $pdo->beginTransaction();
    try {
      $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE fingerprint=? FOR UPDATE'); $q->execute([$fingerprint]); $existing=$q->fetch(PDO::FETCH_ASSOC);
      if ($existing && $existing['status']==='blocked') throw new RuntimeException('This device identity is blocked.');
      $active=(int)$pdo->query("SELECT COUNT(*) FROM snap_desktop_devices WHERE status='active'")->fetchColumn();
      if ((!$existing || $existing['status']!=='active') && $active>=4) throw new RuntimeException('Four devices are already active. Disable one before adding another.');
      $id=$existing['id']??da_uuid(); $ts=time(); $now=date('Y-m-d H:i:s',$ts); $exp=date('Y-m-d H:i:s',$ts+DA_TERM_SECONDS); $grace=date('Y-m-d H:i:s',$ts+DA_TERM_SECONDS+DA_GRACE_SECONDS); $ip=da_ip();
      $meta=[substr(trim((string)($data['device_name']??'SNAP HQ device')),0,120),substr(trim((string)($data['locale']??'')),0,40),substr(trim((string)($data['timezone']??'')),0,80),substr(trim((string)($data['os']??'')),0,160),substr(trim((string)($data['hq_version']??'')),0,40)];
      if ($existing) {
        $pdo->prepare("UPDATE snap_desktop_devices SET user_id=?,public_key=?,device_name=?,locale_name=?,timezone_name=?,os_name=?,hq_version=?,last_ip=?,status='active',term_started_at=?,expires_at=?,grace_ends_at=?,disabled_at=NULL,token_version=token_version+1 WHERE id=?")
          ->execute([(int)$user['id'],$pk64,...$meta,$ip,$now,$exp,$grace,$id]);
      } else {
        $pdo->prepare("INSERT INTO snap_desktop_devices(id,user_id,public_key,fingerprint,device_name,locale_name,timezone_name,os_name,hq_version,first_ip,last_ip,status,term_started_at,expires_at,grace_ends_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'active',?,?,?)")
          ->execute([$id,(int)$user['id'],$pk64,$fingerprint,...$meta,$ip,$ip,$now,$exp,$grace]);
      }
      $pdo->commit(); $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE id=?');$q->execute([$id]);$device=$q->fetch(PDO::FETCH_ASSOC);
      da_out(['ok'=>true,'device_id'=>$id,'entitlement'=>da_token($pdo,$device)]);
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); da_out(['ok'=>false,'error'=>$e->getMessage()],409); }
}

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
      $id=$existing['id']??da_uuid(); $ts=time(); $now=date('Y-m-d H:i:s',$ts); $exp=date('Y-m-d H:i:s',$ts+DA_TERM_SECONDS); $grace=date('Y-m-d H:i:s',$ts+DA_TERM_SECONDS+DA_GRACE_SECONDS); $ip=da_ip();
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
    // allow_expired: these two actions are the way back from an expired term,
    // so they must stay reachable by a device that has one. See da_auth_device.
    $d=da_auth_device($pdo,$raw,true); $ip=da_ip();
    $now=time(); $term_state=da_term_state($d,$now);
    // Already written by da_ensure() above; read here so the anchor and the
    // remediation test below agree on one value for this request.
    $enforced_from=da_enforcement_began($pdo,$now);

    // WHEN A CHECK-IN MAY RENEW ITSELF, AND BY HOW MUCH. SS-1418.
    //
    // This used to renew whenever the action was 'renew' OR the term had less
    // than 30 days to run, and it set the new expiry to now + 3 months in
    // both cases. Two separate faults in one line. A device could renew from
    // any state at all, expired included, and the new term was measured from
    // the moment of asking -- so the act of checking in reset the clock and
    // expiry could never actually arrive. Muse's finding: "status/renew can
    // silently reissue a fresh three-month term even after expiry and
    // re-anchors to now".
    //
    // Now: only a device inside its term or its grace renews itself, the new
    // expiry is anchored to the PREVIOUS one, and it is capped so that early
    // renewals cannot stockpile term. A device past grace renews only inside
    // the one-time remediation window, and otherwise is told to re-authorize.
    $renewable = in_array($term_state['state'], ['current','grace'], true)
                 || da_in_remediation($pdo, $term_state, $now);
    $due = ($action === 'renew') || ($term_state['expires_at'] <= $now + DA_RENEW_WINDOW_SECONDS);
    $renew_required = !$renewable;

    if ($renewable && $due) {
        // A device inside remediation has a prior expiry far in the past, so
        // anchoring would hand it a term that is still expired. Its anchor is
        // the moment enforcement began -- the point from which it was given
        // fair warning -- not the term it let lapse.
        $anchor = da_in_remediation($pdo, $term_state, $now)
            ? max($term_state['expires_at'], $enforced_from)
            : $term_state['expires_at'];
        $exp_ts = da_next_expiry($anchor, $now);
        $term=date('Y-m-d H:i:s',$now);
        $exp=date('Y-m-d H:i:s',$exp_ts);
        $grace=date('Y-m-d H:i:s',$exp_ts + DA_GRACE_SECONDS);
        $pdo->prepare('UPDATE snap_desktop_devices SET term_started_at=?,expires_at=?,grace_ends_at=?,token_version=token_version+1 WHERE id=?')
            ->execute([$term,$exp,$grace,$d['id']]);
        $renew_required = false;
    }
    $pdo->prepare('UPDATE snap_desktop_devices SET first_used_at=COALESCE(first_used_at,NOW()),last_used_at=NOW(),last_ip=?,device_name=COALESCE(NULLIF(?,\'\'),device_name),locale_name=COALESCE(NULLIF(?,\'\'),locale_name),timezone_name=COALESCE(NULLIF(?,\'\'),timezone_name),os_name=COALESCE(NULLIF(?,\'\'),os_name),hq_version=COALESCE(NULLIF(?,\'\'),hq_version) WHERE id=?')
      ->execute([$ip,substr((string)($data['device_name']??''),0,120),substr((string)($data['locale']??''),0,40),substr((string)($data['timezone']??''),0,80),substr((string)($data['os']??''),0,160),substr((string)($data['hq_version']??''),0,40),$d['id']]);
    $q=$pdo->prepare('SELECT * FROM snap_desktop_devices WHERE id=?');$q->execute([$d['id']]);$d=$q->fetch(PDO::FETCH_ASSOC);
    $final=da_term_state($d,$now);
    // The desktop is TOLD, rather than left to work it out from dates. An
    // expired device still gets its (expired) entitlement back so HQ can open
    // and show the prompt: restricted, not shut.
    da_out(['ok'=>true,'status'=>$d['status'],'term_state'=>$final['state'],
            'renew_required'=>$renew_required,'entitlement'=>da_token($pdo,$d)]);
}

da_out(['ok'=>false,'error'=>'Unknown desktop authorization action.'],404);
// ===== SNAPSMACK EOF =====
