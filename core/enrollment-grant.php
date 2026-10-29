<?php
declare(strict_types=1);

/**
 * SNAPSMACK - Fleet enrollment grant (SECAUDIT 061, finding A)
 *
 * WHAT WENT WRONG. On 2026-10-19 a fleet member was joined without a password
 * or a TOTP code. Nothing was broken: the admin page did require step-up
 * before it generated a registration token, and the handshake did verify the
 * token and consume it once. But the token was a bare random string sitting in
 * snap_settings.multisite_reg_token, and the handshake could only ask "does the
 * submitted value equal the stored value, and is it unexpired?" Writing that
 * row directly -- with database access, a migration, a compromised database
 * account or privileged automation -- manufactured the authorization. The row
 * was both the registration STATE and the PROOF that an administrator had
 * authenticated, and those two things must never be the same thing.
 *
 * WHAT THIS FILE CHANGES. The stored row now holds only a DIGEST of a signed,
 * scoped grant. The grant itself is minted here, authenticated with a key that
 * lives in a file and never in MariaDB, and it names the single action it
 * permits, the spoke it was issued on, the hub it may be presented to, the
 * administrator who passed step-up, and when. Writing the settings row no
 * longer produces anything the handshake will accept, because the handshake
 * verifies the MAC before it looks at the database at all.
 *
 * WHAT THIS DOES NOT DO, stated plainly because the audit demanded it be.
 * This is not root-proof and nobody should describe it as such. An attacker
 * who can read this server's filesystem can read the key file and mint a
 * grant, exactly as they could read db.php and take the database. SECAUDIT 061
 * section 4 names that honestly as the host trust boundary and asks for two
 * layers; this is layer one, DATABASE-WRITE RESISTANCE, and it closes the path
 * that was actually demonstrated plus any database-only compromise. Layer two
 * -- an approval signature from an authority whose private key is NOT on this
 * host -- is section 5.5, is a new enrollment ceremony, and is deliberately
 * NOT built here: Sean asked that the authority and user-presence choice be
 * explained concretely and agreed before anyone implements one.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// A grant is "ssg1." . base64url(payload json) . "." . base64url(mac).
// The version lives in the prefix AND in the payload: the prefix lets a reader
// reject a foreign string without parsing it, the payload claim is what the
// verifier actually trusts.
const SNAP_ENROLLMENT_GRANT_PREFIX  = 'ssg1.';
const SNAP_ENROLLMENT_GRANT_VERSION = 1;

// The stored settings row is prefixed so a legacy 32-char hex token is
// distinguishable from a digest at a glance, by code and by a person reading
// the table. Anything without this prefix is a pre-061 token and is refused.
const SNAP_ENROLLMENT_DIGEST_PREFIX = 'grantv1:';

// 15 minutes, unchanged from the token it replaces. Shortening it would be a
// workflow change nobody asked for.
const SNAP_ENROLLMENT_GRANT_TTL = 900;

/** Where the MAC key lives: a file, never a database row. */
function snap_enrollment_key_path(): string {
    return __DIR__ . '/enrollment-key.php';
}

/**
 * The MAC key.
 *
 * Stored as a PHP file that RETURNS the key, so a direct HTTP request for it
 * executes and prints nothing rather than serving the secret as text. Same
 * reasoning that keeps credentials in core/db.php rather than a .txt.
 *
 * $create is false by default and the VERIFIER must never pass true. If the
 * key file is absent, verification fails closed: a missing key means no grant
 * this install issued can be authenticated, and silently minting a fresh key
 * at verification time would let a caller who can delete the file replace the
 * authority with one of their own.
 */
function snap_enrollment_key(bool $create = false): string {
    $path = snap_enrollment_key_path();
    if (is_file($path)) {
        $key = @include $path;
        $key = is_string($key) ? trim($key) : '';
        if (preg_match('/^[0-9a-f]{64}$/', $key)) return $key;
        // A present but unusable key file is a fault, not a reason to mint a
        // new one: replacing it would invalidate nothing and prove nothing,
        // and it would hide a tampered or truncated file.
        return '';
    }
    if (!$create) return '';

    $key = bin2hex(random_bytes(32));
    $body = "<?php\n"
        . "// SNAPSMACK fleet enrollment MAC key. Generated on first use.\n"
        . "// NOT a database value on purpose -- see core/enrollment-grant.php\n"
        . "// and SECAUDIT 061. Keep it out of backups shared broadly, out of\n"
        . "// tickets and out of the forum. Replacing it invalidates any grant\n"
        . "// not yet consumed; it does not affect existing fleet keys.\n"
        . "return '" . $key . "';\n";
    // LOCK_EX so two concurrent issuances cannot write half a file each.
    if (@file_put_contents($path, $body, LOCK_EX) === false) return '';
    @chmod($path, 0600);
    return $key;
}

/** One canonical form for an origin, so binding compares like with like. */
function snap_enrollment_origin(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host   = strtolower((string)$parts['host']);
    $port   = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path   = rtrim((string)($parts['path'] ?? ''), '/');
    return $scheme . '://' . $host . $port . $path;
}

function snap_enrollment_b64(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function snap_enrollment_b64_decode(string $enc): string {
    if ($enc === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $enc)) return '';
    $pad = strlen($enc) % 4;
    if ($pad === 1) return '';   // never a valid base64 length
    if ($pad > 0) $enc .= str_repeat('=', 4 - $pad);
    $out = base64_decode(strtr($enc, '-_', '+/'), true);
    return is_string($out) ? $out : '';
}

/** The digest the settings row holds. Never the grant itself. */
function snap_enrollment_grant_digest(string $grant): string {
    return SNAP_ENROLLMENT_DIGEST_PREFIX . hash('sha256', $grant);
}

/**
 * Mint a grant. Called ONLY after reauth_verify() has passed.
 *
 * Returns ['ok'=>bool, 'error'=>string, 'grant'=>string, 'digest'=>string,
 *          'expires'=>int, 'claims'=>array]. The caller stores the digest and
 * shows the grant ONCE; the grant is the bearer value and must not be written
 * to snap_settings, a log, a ticket or the forum.
 */
function snap_enrollment_grant_issue(string $spoke_origin, string $hub_origin,
                                     int $admin_id, int $stepup_at = 0): array {
    $spoke = snap_enrollment_origin($spoke_origin);
    $hub   = snap_enrollment_origin($hub_origin);
    if ($spoke === '') return ['ok' => false, 'error' => 'This site has no usable canonical address.'];
    if ($hub === '')   return ['ok' => false, 'error' => 'A hub address is required, so the grant can name the hub it is for.'];
    if ($admin_id <= 0) return ['ok' => false, 'error' => 'No administrator identity for the grant.'];

    $key = snap_enrollment_key(true);
    if ($key === '') {
        return ['ok' => false, 'error' => 'Enrollment key file could not be written. Nothing was issued.'];
    }

    $now = time();
    $claims = [
        'ver'       => SNAP_ENROLLMENT_GRANT_VERSION,
        'act'       => 'fleet.enroll',   // the ONE action this grant permits
        'nonce'     => bin2hex(random_bytes(16)),
        'spoke'     => $spoke,
        'hub'       => $hub,
        'admin'     => $admin_id,
        'stepup_at' => $stepup_at > 0 ? $stepup_at : $now,
        'iat'       => $now,
        'exp'       => $now + SNAP_ENROLLMENT_GRANT_TTL,
    ];
    $payload = json_encode($claims, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return ['ok' => false, 'error' => 'Grant could not be encoded.'];

    $body  = snap_enrollment_b64($payload);
    $mac   = snap_enrollment_b64(hash_hmac('sha256', $body, $key, true));
    $grant = SNAP_ENROLLMENT_GRANT_PREFIX . $body . '.' . $mac;

    return [
        'ok'      => true,
        'error'   => '',
        'grant'   => $grant,
        'digest'  => snap_enrollment_grant_digest($grant),
        'expires' => $claims['exp'],
        'claims'  => $claims,
    ];
}

/**
 * Verify a grant presented to the handshake.
 *
 * $expect takes 'spoke' (this site) and 'hub' (the origin the caller claims to
 * be). BOTH are checked against the grant, because a grant that does not say
 * where it may be used is only a password with extra steps.
 *
 * Returns ['ok'=>bool, 'error'=>string, 'reason'=>string, 'claims'=>array].
 * 'error' is what a caller may be told; 'reason' is the short machine-readable
 * token for the audit row. The error text never distinguishes "no grant was
 * issued" from "the MAC is wrong", so a prober learns nothing about state.
 */
function snap_enrollment_grant_verify(string $grant, array $expect): array {
    $bad = static function (string $reason): array {
        return ['ok' => false, 'error' => 'Invalid or expired enrollment grant',
                'reason' => $reason, 'claims' => []];
    };

    $grant = trim($grant);
    if ($grant === '' || !str_starts_with($grant, SNAP_ENROLLMENT_GRANT_PREFIX)) {
        // A pre-061 bare token lands here. That is the whole point of the fix:
        // it is refused on format, before any database comparison.
        return $bad('not_a_grant');
    }
    $rest = substr($grant, strlen(SNAP_ENROLLMENT_GRANT_PREFIX));
    $dot  = strrpos($rest, '.');
    if ($dot === false || $dot === 0 || $dot === strlen($rest) - 1) return $bad('malformed');
    $body = substr($rest, 0, $dot);
    $mac  = substr($rest, $dot + 1);

    $key = snap_enrollment_key(false);   // never create while verifying
    if ($key === '') return $bad('no_key');

    $want = snap_enrollment_b64(hash_hmac('sha256', $body, $key, true));
    // Constant time, and length-checked first so hash_equals is not handed
    // mismatched lengths.
    if (strlen($mac) !== strlen($want) || !hash_equals($want, $mac)) return $bad('bad_mac');

    $json = snap_enrollment_b64_decode($body);
    if ($json === '') return $bad('bad_payload');
    $claims = json_decode($json, true);
    if (!is_array($claims)) return $bad('bad_payload');

    if ((int)($claims['ver'] ?? 0) !== SNAP_ENROLLMENT_GRANT_VERSION) return $bad('bad_version');
    if ((string)($claims['act'] ?? '') !== 'fleet.enroll')            return $bad('bad_action');
    if (!preg_match('/^[0-9a-f]{32}$/', (string)($claims['nonce'] ?? ''))) return $bad('bad_nonce');
    if ((int)($claims['admin'] ?? 0) <= 0)                            return $bad('no_admin');

    $now = time();
    $exp = (int)($claims['exp'] ?? 0);
    $iat = (int)($claims['iat'] ?? 0);
    if ($exp <= 0 || $now > $exp) return $bad('expired');
    // 120 seconds of tolerance for a clock that runs ahead, and no more: a
    // grant from the far future is a forgery or a broken host, not a clock.
    if ($iat <= 0 || $iat > $now + 120) return $bad('not_yet_valid');

    $wantSpoke = snap_enrollment_origin((string)($expect['spoke'] ?? ''));
    $wantHub   = snap_enrollment_origin((string)($expect['hub'] ?? ''));
    if ($wantSpoke === '' || $wantHub === '') return $bad('no_expectation');
    if (snap_enrollment_origin((string)($claims['spoke'] ?? '')) !== $wantSpoke) return $bad('spoke_mismatch');
    if (snap_enrollment_origin((string)($claims['hub'] ?? ''))   !== $wantHub)   return $bad('hub_mismatch');

    return ['ok' => true, 'error' => '', 'reason' => 'ok', 'claims' => $claims];
}

/**
 * The enrollment audit, SECAUDIT 061 finding C.
 *
 * Append-only in intent: this code never updates or deletes a row. It cannot
 * be append-only against database access, and saying otherwise would be the
 * same overclaim the audit was opened about.
 *
 * SECRET-FREE BY CONSTRUCTION. There is no column for a grant, a token or an
 * API key. The digest identifies an enrollment attempt without being usable
 * to perform one, which is the same reason the settings row holds a digest.
 */
function snap_enrollment_audit_table(PDO $pdo): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_enrollment_audit` (
            `id`            int unsigned NOT NULL AUTO_INCREMENT,
            `event`         varchar(40)  COLLATE utf8mb4_unicode_ci NOT NULL
                            COMMENT 'issued | consumed | rejected | revoked',
            `reason`        varchar(40)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
                            COMMENT 'machine-readable rejection reason; empty on success',
            `grant_digest`  varchar(80)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
                            COMMENT 'sha256 of the grant. NOT the grant: identifies, cannot enroll.',
            `nonce`         varchar(32)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `spoke_origin`  varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `hub_origin`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `admin_id`      int unsigned NOT NULL DEFAULT 0,
            `stepup_at`     datetime     DEFAULT NULL,
            `remote_addr`   varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
            `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_enroll_digest` (`grant_digest`),
            KEY `idx_enroll_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('SnapSmack enrollment audit: table unavailable — ' . $e->getMessage());
        return false;
    }
}

/**
 * Record an enrollment event. Never throws: an enrollment must not fail
 * because its audit row could not be written, and a lost row must not be
 * silent either, so it goes to the error log.
 */
function snap_enrollment_audit(PDO $pdo, string $event, array $f = []): void {
    static $ready = null;
    if ($ready === null) $ready = snap_enrollment_audit_table($pdo);
    if (!$ready) {
        error_log('SnapSmack enrollment audit: NOT RECORDED event=' . $event
            . ' reason=' . (string)($f['reason'] ?? ''));
        return;
    }
    // Whatever the caller passes, only these columns exist, so a secret has
    // nowhere to land even if someone hands this function a whole grant.
    $stepup = (int)($f['stepup_at'] ?? 0);
    try {
        $pdo->prepare("INSERT INTO `snap_enrollment_audit`
            (event, reason, grant_digest, nonce, spoke_origin, hub_origin,
             admin_id, stepup_at, remote_addr)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                substr($event, 0, 40),
                substr((string)($f['reason'] ?? ''), 0, 40),
                substr((string)($f['grant_digest'] ?? ''), 0, 80),
                substr((string)($f['nonce'] ?? ''), 0, 32),
                substr((string)($f['spoke_origin'] ?? ''), 0, 500),
                substr((string)($f['hub_origin'] ?? ''), 0, 500),
                max(0, (int)($f['admin_id'] ?? 0)),
                $stepup > 0 ? date('Y-m-d H:i:s', $stepup) : null,
                substr((string)($f['remote_addr'] ?? ''), 0, 64),
            ]);
    } catch (PDOException $e) {
        error_log('SnapSmack enrollment audit: insert failed — ' . $e->getMessage());
    }
}

// ===== SNAPSMACK EOF =====
