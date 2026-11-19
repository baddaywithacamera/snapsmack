<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1437 — behind a CDN, stats and audit must see the visitor, not the edge.
 *
 * THE FAULT. Three places read the connecting address for themselves instead
 * of asking core/client-ip.php, which has been the one resolver since
 * SECAUDIT 035:
 *
 *   1. snap_stats.ip_hash, the value unique visitors are counted from, hashed
 *      REMOTE_ADDR directly. Behind Cloudflare every visitor arrives from one
 *      of a small set of edge addresses, so every visitor hashed to the SAME
 *      value and the unique count collapsed to roughly the number of edges
 *      that served the site that day. This is the one the ticket did not name.
 *   2. snapsmack_client_ip() in core/stats-logger.php -- a second resolver --
 *      fed the GeoIP lookup. It trusted REMOTE_ADDR whenever that address was
 *      public, and a Cloudflare edge address IS public, so it returned the
 *      edge and never read CF-Connecting-IP. Every visitor geolocated to
 *      wherever the edge was.
 *   3. _reauth_audit() logged REMOTE_ADDR, so every step-up failure in the log
 *      carried the same handful of addresses.
 *
 * WHAT THIS IS NOT, stated so nobody reads the fix as bigger than it is: none
 * of these was a lockout or an auth bypass. The step-up budget keys on
 * 'uid:N', not on the address, so a shared edge never locked one user out
 * because of another, and ban recording already went through the trusted
 * resolver. These were wrong NUMBERS and a useless audit trail.
 *
 * HOW THE BEHAVIOUR IS TESTED. $_SERVER is set to the shapes a real request
 * arrives in -- a direct connection, a Cloudflare edge with CF-Connecting-IP,
 * an untrusted peer claiming headers -- and the resolver is called. The two
 * call sites are then checked against comment-stripped source, because what
 * must be true of them is that they do not read REMOTE_ADDR for themselves,
 * and absence cannot be rendered.
 */

$root = dirname(__DIR__);
require_once $root . '/core/client-ip.php';

$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * Source with comments removed.
 *
 * Three source assertions of mine this week matched my own comments rather
 * than the code -- the comment describing a fix satisfied the check that the
 * fix was present. Tokeniser, every time, not line-prefix stripping.
 */
function ip_code_only(string $php): string {
    $out = '';
    foreach (@token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

/** Put $_SERVER into the shape a request arrives in, and nothing else. */
function as_request(array $server): void {
    foreach (['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
        unset($_SERVER[$k]);
    }
    foreach ($server as $k => $v) $_SERVER[$k] = $v;
}

echo "SS-1437 — the real client reaches stats and the audit log\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the resolver itself, on the shapes that caused this\n";
// ─────────────────────────────────────────────────────────────────────────────
// A Cloudflare source network. snap_ip_is_cloudflare_proxy() reads these from
// Cloudflare's published ranges, so this needs no site configuration -- which
// is the point: an install that never set trusted_proxies is still correct.
$cf_edge  = '172.68.0.1';
$visitor  = '203.0.113.47';
$visitor2 = '198.51.100.9';

check('a Cloudflare source address is recognised as one',
      snap_ip_is_cloudflare_proxy($cf_edge), $failures);
check('and an ordinary public address is not',
      !snap_ip_is_cloudflare_proxy($visitor), $failures);

as_request(['REMOTE_ADDR' => $cf_edge, 'HTTP_CF_CONNECTING_IP' => $visitor]);
check('behind Cloudflare the VISITOR is resolved, not the edge',
      snap_trusted_client_ip() === $visitor, $failures);

// THE WHOLE DEFECT IN ONE CHECK: two visitors through the same edge must not
// resolve to the same address, because that is what collapsed the unique count.
as_request(['REMOTE_ADDR' => $cf_edge, 'HTTP_CF_CONNECTING_IP' => $visitor2]);
$second = snap_trusted_client_ip();
check('two visitors through the SAME edge resolve to different addresses',
      $second === $visitor2 && $second !== $visitor, $failures);

// And the hash that uniques are counted from must differ for them.
require_once $root . '/core/stats-logger.php';
check('so their daily stats hashes differ, which is what uniques counts',
      snapsmack_hash_ip($visitor) !== snapsmack_hash_ip($visitor2), $failures);
check('and the same visitor hashes the same way twice, so uniques still work',
      snapsmack_hash_ip($visitor) === snapsmack_hash_ip($visitor), $failures);

// The old function's exact failure: a public REMOTE_ADDR was trusted outright.
as_request(['REMOTE_ADDR' => $cf_edge]);
check('a Cloudflare edge with no CF header falls back to the edge, not a guess',
      snap_trusted_client_ip() === $cf_edge, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — the forgeable headers are still ignored on a direct hit\n";
// ─────────────────────────────────────────────────────────────────────────────
// SECAUDIT 035's rule, which this change must not weaken: a peer we do not
// trust may claim to be anyone, and its claims are discarded.
as_request(['REMOTE_ADDR' => $visitor, 'HTTP_CF_CONNECTING_IP' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.1, 10.0.0.2']);
check('an untrusted peer claiming CF-Connecting-IP is not believed',
      snap_trusted_client_ip() === $visitor, $failures);
check('nor its X-Forwarded-For',
      snap_trusted_client_ip() !== '192.0.2.1', $failures);

as_request(['REMOTE_ADDR' => 'not-an-ip']);
check('a missing or malformed peer address does not become someone else',
      snap_trusted_client_ip() === '0.0.0.0', $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the country lookup is given the resolved address\n";
// ─────────────────────────────────────────────────────────────────────────────
// snapsmack_geoip_country() prefers a code the WEBSERVER resolved, so on this
// box it returns from the header path before any address is used. What can be
// checked without a GeoIP database is that it ACCEPTS the resolved address
// rather than resolving one of its own.
$r = new ReflectionFunction('snapsmack_geoip_country');
check('snapsmack_geoip_country() takes the client address as a parameter',
      $r->getNumberOfParameters() === 1, $failures);
check('and it is optional, so the existing header path is unchanged',
      $r->getNumberOfRequiredParameters() === 0, $failures);

as_request(['REMOTE_ADDR' => $cf_edge, 'HTTP_CF_CONNECTING_IP' => $visitor,
            'HTTP_CF_IPCOUNTRY' => 'CA']);
check('a webserver-provided country code is still used',
      snapsmack_geoip_country() === 'CA', $failures);
unset($_SERVER['HTTP_CF_IPCOUNTRY']);
check('and a reserved code is still rejected rather than stored',
      (static function (): bool {
          $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
          $out = snapsmack_geoip_country();
          unset($_SERVER['HTTP_CF_IPCOUNTRY']);
          return $out === null;
      })(), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the duplicate resolver is gone, and no call site re-reads the peer\n";
// ─────────────────────────────────────────────────────────────────────────────
check('snapsmack_client_ip() no longer exists anywhere',
      !function_exists('snapsmack_client_ip'), $failures);

$stats  = ip_code_only((string)file_get_contents($root . '/core/stats-logger.php'));
$reauth = ip_code_only((string)file_get_contents($root . '/core/reauth.php'));

check('core/stats-logger.php defines no second resolver',
      !str_contains($stats, 'function snapsmack_client_ip'), $failures);
check('and asks core/client-ip.php instead',
      str_contains($stats, "require_once __DIR__ . '/client-ip.php'")
      && str_contains($stats, 'snap_trusted_client_ip('), $failures);
check('the stats hash is taken from the resolved address, not REMOTE_ADDR',
      str_contains($stats, '$ip       = snap_trusted_client_ip($pdo)'), $failures);
check('and nothing in the stats logger reads REMOTE_ADDR for itself any more',
      !str_contains($stats, "REMOTE_ADDR"), $failures);

// THE CALL SITE MUST PASS THE RESOLVED ADDRESS, not leave the country lookup
// to resolve one of its own. A mutation that dropped the argument survived an
// earlier version of this file, and it is not harmless: the internal fallback
// runs WITHOUT $pdo, so a site whose proxy is something other than Cloudflare
// -- one that set trusted_proxies by hand -- would fall back to trusting
// loopback only and geolocate the proxy instead of the visitor. Cloudflare
// sites would be fine, which is exactly how this would have gone unnoticed.
check('the country lookup is handed the address already resolved with $pdo',
      str_contains($stats, 'snapsmack_geoip_country($ip)'), $failures);

check('core/reauth.php loads the resolver',
      str_contains($reauth, "require_once __DIR__ . '/client-ip.php'"), $failures);
check('and the audit line uses it',
      str_contains($reauth, 'snap_trusted_client_ip()'), $failures);

// THE CLI CASE, preserved. A step-up from the command line has no peer
// address, and the audit line used to say 'cli'. It still must: '0.0.0.0'
// would read as a real request from an address nobody can look up.
check("a step-up with no peer address is still audited as 'cli'",
      str_contains($reauth, "? snap_trusted_client_ip() : 'cli'"), $failures);

// The budget keys on the user, not the address. If that ever changes to an
// address key, a shared CDN edge becomes a shared lockout bucket and this
// ticket's reasoning stops holding, so it is pinned here rather than assumed.
check("the step-up budget still keys on the user, not the address",
      substr_count($reauth, "\$key = 'uid:' . \$uid") >= 2
      && !str_contains($reauth, "\$key = snap_trusted_client_ip"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1437 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1437 PASS — two visitors through one CDN edge are two visitors, the\n";
echo "country lookup sees them, and the audit log names them.\n";
echo "NOT COVERED: no GeoIP database on this box, so the .mmdb lookup path is\n";
echo "reached but its result is not asserted; and no live request through a\n";
echo "real Cloudflare edge has been made. Both need a deployed site.\n";
// ===== SNAPSMACK EOF =====
