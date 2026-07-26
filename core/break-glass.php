<?php
/**
 * SNAPSMACK — Break-Glass Card engine
 *
 * Generates and verifies a cryptographically signed, site-bound, single-use
 * total-lockout recovery card. Each card receives a fresh Ed25519 keypair.
 * Only the public key and a hash of the card ID remain on the site; the secret
 * signing key exists only long enough to sign the downloaded card.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

const SNAP_BREAK_GLASS_FORMAT   = 'snapsmack-break-glass-v1';
const SNAP_BREAK_GLASS_FILENAME = 'BREAK-GLASS-CARD.sbc';
const SNAP_BREAK_GLASS_MAX_SIZE = 16384;

function break_glass_setting_get(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare(
        "SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1"
    );
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function break_glass_setting_set(PDO $pdo, string $key, string $value): void {
    $update = $pdo->prepare(
        "UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?"
    );
    $update->execute([$value, $key]);
    if ($update->rowCount() > 0) {
        return;
    }
    try {
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)"
        )->execute([$key, $value]);
    } catch (PDOException $e) {
        // MySQL may report zero changed rows when the existing value is
        // identical. A concurrent/identical row is therefore updated once more.
        $update->execute([$value, $key]);
        if ($update->rowCount() === 0
            && break_glass_setting_get($pdo, $key, "\0") !== $value) {
            throw $e;
        }
    }
}

function break_glass_base64url_encode(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function break_glass_base64url_decode(string $encoded): string|false {
    if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
        return false;
    }
    $padding = (4 - (strlen($encoded) % 4)) % 4;
    return base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
}

/**
 * Create and activate a new card. Any previously generated card is revoked.
 *
 * @return array{filename:string,contents:string,card_id:string,username:string,generated_at:string}
 */
function break_glass_generate(PDO $pdo, int $user_id): array {
    if (!function_exists('sodium_crypto_sign_keypair')) {
        throw new RuntimeException('The sodium extension is required to generate a Break-Glass Card.');
    }

    $stmt = $pdo->prepare(
        "SELECT id, username, user_role FROM snap_users WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !in_array((string)($user['user_role'] ?? ''), ['admin', 'administrator', 'owner'], true)) {
        throw new RuntimeException('Break-Glass Cards can only be issued to an administrator.');
    }

    $site_id = break_glass_setting_get($pdo, 'break_glass_site_id');
    if (!preg_match('/^[a-f0-9]{64}$/', $site_id)) {
        $site_id = bin2hex(random_bytes(32));
    }

    $card_id      = bin2hex(random_bytes(32));
    $generated_at = gmdate('Y-m-d\TH:i:s\Z');
    $site_url     = rtrim(break_glass_setting_get($pdo, 'site_url'), '/');
    $site_name    = break_glass_setting_get($pdo, 'site_name', 'SnapSmack');
    $login_slug   = trim(break_glass_setting_get($pdo, 'login_slug', 'snap-in'), '/');

    $payload = [
        'format'       => SNAP_BREAK_GLASS_FORMAT,
        'card_id'      => $card_id,
        'site_id'      => $site_id,
        'site_url'     => $site_url,
        'site_name'    => $site_name,
        'user_id'      => (int)$user['id'],
        'username'     => (string)$user['username'],
        'login_slug'   => $login_slug,
        'generated_at' => $generated_at,
        'purpose'      => 'single-use-total-lockout-recovery',
    ];
    $payload_json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $keypair    = sodium_crypto_sign_keypair();
    $secret_key = sodium_crypto_sign_secretkey($keypair);
    $public_key = sodium_crypto_sign_publickey($keypair);
    $signature  = sodium_crypto_sign_detached($payload_json, $secret_key);

    $card = [
        'format'    => SNAP_BREAK_GLASS_FORMAT,
        'payload'   => break_glass_base64url_encode($payload_json),
        'signature' => sodium_bin2hex($signature),
        'instructions' => [
            'Keep this file offline. Anyone holding it can take control of the named site.',
            'Upload it to the SnapSmack web root without renaming it.',
            'Visit /break-glass.php in a browser and confirm recovery.',
            'The card works once. Recovery revokes the password, 2FA, recovery codes, trusted devices, reset links, old sessions, and the card itself.',
        ],
    ];
    $card_json = json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

    $pdo->beginTransaction();
    try {
        break_glass_setting_set($pdo, 'break_glass_site_id', $site_id);
        break_glass_setting_set($pdo, 'break_glass_public_key', sodium_bin2hex($public_key));
        break_glass_setting_set($pdo, 'break_glass_card_id_hash', hash('sha256', $card_id));
        break_glass_setting_set($pdo, 'break_glass_user_id', (string)$user['id']);
        break_glass_setting_set($pdo, 'break_glass_generated_at', $generated_at);
        break_glass_setting_set($pdo, 'break_glass_status', 'active');
        break_glass_setting_set($pdo, 'break_glass_consumed_at', '');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        sodium_memzero($secret_key);
    }

    return [
        'filename'     => SNAP_BREAK_GLASS_FILENAME,
        'contents'     => $card_json,
        'card_id'      => $card_id,
        'username'     => (string)$user['username'],
        'generated_at' => $generated_at,
    ];
}

/**
 * Parse and authenticate a card against this installation.
 *
 * @return array{ok:bool,error:string,payload?:array}
 */
function break_glass_verify(PDO $pdo, string $card_json): array {
    if (strlen($card_json) < 100 || strlen($card_json) > SNAP_BREAK_GLASS_MAX_SIZE) {
        return ['ok' => false, 'error' => 'Card size is invalid.'];
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        return ['ok' => false, 'error' => 'The sodium extension is required for recovery.'];
    }

    try {
        $card = json_decode($card_json, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Card is not valid JSON.'];
    }
    if (!is_array($card)
        || ($card['format'] ?? '') !== SNAP_BREAK_GLASS_FORMAT
        || !is_string($card['payload'] ?? null)
        || !is_string($card['signature'] ?? null)) {
        return ['ok' => false, 'error' => 'Card format is not recognized.'];
    }

    $payload_json = break_glass_base64url_decode($card['payload']);
    if ($payload_json === false || strlen($payload_json) > 8192) {
        return ['ok' => false, 'error' => 'Card payload encoding is invalid.'];
    }
    try {
        $payload = json_decode($payload_json, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Card payload is invalid.'];
    }
    if (!is_array($payload)
        || ($payload['format'] ?? '') !== SNAP_BREAK_GLASS_FORMAT
        || !preg_match('/^[a-f0-9]{64}$/', (string)($payload['card_id'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string)($payload['site_id'] ?? ''))
        || (int)($payload['user_id'] ?? 0) < 1) {
        return ['ok' => false, 'error' => 'Card payload fields are invalid.'];
    }

    $status        = break_glass_setting_get($pdo, 'break_glass_status');
    $site_id       = break_glass_setting_get($pdo, 'break_glass_site_id');
    $public_hex    = break_glass_setting_get($pdo, 'break_glass_public_key');
    $card_id_hash  = break_glass_setting_get($pdo, 'break_glass_card_id_hash');
    $bound_user_id = (int)break_glass_setting_get($pdo, 'break_glass_user_id', '0');

    if ($status !== 'active') {
        return ['ok' => false, 'error' => 'This installation has no active Break-Glass Card.'];
    }
    if (!hash_equals($site_id, (string)$payload['site_id'])
        || !hash_equals($card_id_hash, hash('sha256', (string)$payload['card_id']))
        || $bound_user_id !== (int)$payload['user_id']) {
        return ['ok' => false, 'error' => 'Card is not bound to this installation or has been replaced.'];
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $public_hex)
        || !preg_match('/^[a-f0-9]{128}$/', (string)$card['signature'])) {
        return ['ok' => false, 'error' => 'Card signature material is invalid.'];
    }

    try {
        $public_key = sodium_hex2bin($public_hex);
        $signature  = sodium_hex2bin((string)$card['signature']);
        $valid      = sodium_crypto_sign_verify_detached($signature, $payload_json, $public_key);
    } catch (Throwable $e) {
        $valid = false;
    }
    if (!$valid) {
        return ['ok' => false, 'error' => 'Card signature verification failed.'];
    }

    $stmt = $pdo->prepare(
        "SELECT id, username, user_role, preferred_skin, email
         FROM snap_users WHERE id = ? LIMIT 1"
    );
    $stmt->execute([(int)$payload['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user
        || !hash_equals((string)$user['username'], (string)($payload['username'] ?? ''))
        || !in_array((string)($user['user_role'] ?? ''), ['admin', 'administrator', 'owner'], true)) {
        return ['ok' => false, 'error' => 'The administrator named by this card no longer exists.'];
    }

    $payload['_user'] = $user;
    return ['ok' => true, 'error' => '', 'payload' => $payload];
}

/**
 * Burn all current access material and consume the card atomically.
 *
 * @return array{id:int,username:string,user_role:string,preferred_skin:mixed}
 */
function break_glass_consume(PDO $pdo, array $verified_payload): array {
    $user = $verified_payload['_user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        throw new RuntimeException('Verified recovery account is missing.');
    }

    $new_unknown_password = password_hash(bin2hex(random_bytes(64)), PASSWORD_DEFAULT);
    $consumed_at          = gmdate('Y-m-d\TH:i:s\Z');

    $pdo->beginTransaction();
    try {
        // Serialize consumers and re-check active binding inside the same
        // transaction. Two simultaneous requests must never both win.
        $lock = $pdo->query(
            "SELECT setting_val FROM snap_settings
             WHERE setting_key = 'break_glass_status' LIMIT 1 FOR UPDATE"
        );
        if ((string)$lock->fetchColumn() !== 'active'
            || !hash_equals(
                break_glass_setting_get($pdo, 'break_glass_card_id_hash'),
                hash('sha256', (string)($verified_payload['card_id'] ?? ''))
            )) {
            throw new RuntimeException('The Break-Glass Card has already been consumed or replaced.');
        }

        $stmt = $pdo->prepare(
            "UPDATE snap_users
             SET password_hash = ?,
                 recovery_code_hash = NULL,
                 force_password_change = 1,
                 totp_secret = NULL,
                 totp_enabled = 0,
                 totp_recovery_json = NULL
             WHERE id = ?"
        );
        $stmt->execute([$new_unknown_password, (int)$user['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Recovery account could not be locked for reset.');
        }

        try {
            $pdo->prepare("DELETE FROM snap_totp_devices WHERE user_id = ?")
                ->execute([(int)$user['id']]);
        } catch (PDOException $e) {
            // Upgraded installs may not have the trusted-device table yet.
        }
        try {
            $pdo->prepare(
                "DELETE FROM snap_password_resets
                 WHERE LOWER(email) = LOWER((SELECT email FROM snap_users WHERE id = ?))"
            )->execute([(int)$user['id']]);
        } catch (PDOException $e) {
            // Upgraded installs may not have the reset-token table yet.
        }

        break_glass_setting_set($pdo, 'break_glass_status', 'consumed');
        break_glass_setting_set($pdo, 'break_glass_consumed_at', $consumed_at);
        break_glass_setting_set($pdo, 'break_glass_public_key', '');
        break_glass_setting_set($pdo, 'break_glass_card_id_hash', '');
        break_glass_setting_set($pdo, 'break_glass_last_ip', substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    error_log(
        'SNAPSMACK BREAK-GLASS USED: user=' . (string)$user['username']
        . ' at=' . $consumed_at
        . ' ip=' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    );

    // Loud-on-use: best-effort owner email after the transaction is safely
    // committed. Mail failure must never roll back a successful credential burn.
    try {
        $recipient = trim((string)($user['email'] ?? ''));
        if ($recipient === '') {
            $recipient = trim(break_glass_setting_get($pdo, 'site_email'));
        }
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/mailer.php';
            $site_name = break_glass_setting_get($pdo, 'site_name', 'SnapSmack');
            snapsmack_send_mail(
                $recipient,
                "[{$site_name}] BREAK-GLASS CARD USED",
                "The Break-Glass Card for {$site_name} was used at {$consumed_at} UTC.\n\n"
                . "Account: {$user['username']}\n"
                . "IP: " . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n\n"
                . "The old password, 2FA material, recovery codes, trusted devices, reset links, "
                . "sessions, and card were revoked. If this was not you, treat the host and database "
                . "as compromised immediately."
            );
        }
    } catch (Throwable $e) {
        error_log('SNAPSMACK BREAK-GLASS: owner alert email failed — ' . $e->getMessage());
    }

    return [
        'id'             => (int)$user['id'],
        'username'       => (string)$user['username'],
        'user_role'      => (string)($user['user_role'] ?? 'admin'),
        'preferred_skin' => $user['preferred_skin'] ?? null,
    ];
}

// ===== SNAPSMACK EOF =====
