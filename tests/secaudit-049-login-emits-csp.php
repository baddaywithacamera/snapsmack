<?php
/**
 * SECAUDIT 049 — the login portal must emit the security headers (CSP/HSTS).
 *
 * Measured fault (Codex, fleet sweep): 30 CMS installs all carry HSTS and a
 * homepage CSP, but 15 login responses carried NO Content-Security-Policy.
 * Root cause: snap-in.php (the login portal — there is no login.php on dev)
 * required only db/auth-recovery/totp/client-ip and never loaded the
 * core/constants.php -> skin-manifest.php chain that pulls in
 * core/http-security-headers.php on every other page, so on installs that rely
 * on the PHP headers (not server-level config) the login response had no CSP.
 * It was also never classified as an admin page, so even once wired it would
 * have received the public CSP instead of the stricter admin one.
 *
 * This is a STRUCTURAL regression. CLI cannot observe header() output (the
 * header file's emit block is skipped under PHP_SAPI==='cli'), so the live
 * behaviour is verified by measuring login responses on the fleet. Here we lock
 * the wiring that produces that behaviour so it cannot silently regress.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root   = dirname(__DIR__);
$fails  = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got === $want) {
        echo "ok: $label\n";
    } else {
        $fails++;
        fwrite(STDERR, "FAIL: $label -- got " . var_export($got, true)
            . ", want " . var_export($want, true) . "\n");
    }
}

$snapin = @file_get_contents("$root/snap-in.php");
$hdr    = @file_get_contents("$root/core/http-security-headers.php");
check("snap-in.php is readable", is_string($snapin) && $snapin !== '', true);
check("http-security-headers.php is readable", is_string($hdr) && $hdr !== '', true);

// 1. The login portal loads the shared security-headers file.
check("snap-in.php requires core/http-security-headers.php",
    (bool) preg_match('/require_once\s+[\'"]core\/http-security-headers\.php[\'"]\s*;/', (string) $snapin),
    true);

// 2. It is loaded BEFORE any output: the require must sit above the first
//    heredoc / echo / print / closing PHP tag that would send bytes and trip
//    headers_sent(). We approximate "before output" by requiring it to appear
//    before the first DOCTYPE the login form prints.
$reqPos       = strpos((string) $snapin, "require_once 'core/http-security-headers.php'");
$firstDoctype = stripos((string) $snapin, '<!doctype');
check("header require sits before the login page's first DOCTYPE output",
    $reqPos !== false && $firstDoctype !== false && $reqPos < $firstDoctype,
    true);

// 3. snap-in.php is classified as an admin page, so it gets the stricter admin
//    CSP (connect-src 'self'; object-src 'none'; base-uri 'self';
//    frame-ancestors 'self'), not the public one.
check("admin classification list includes snap-in.php",
    (bool) preg_match('/in_array\s*\(\s*\$entry\s*,\s*\[[^\]]*\bsnap-in\.php\b[^\]]*\]/', (string) $hdr),
    true);

// 4. The admin branch still emits the locked-down admin CSP string.
check("admin CSP string is present and unchanged",
    strpos((string) $hdr, "connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'") !== false,
    true);

// 5. There is no login.php on dev; snap-in.php IS the login portal. If one is
//    ever reintroduced, this test does not fail — it only asserts that the real
//    portal we ship is the one covered. (Documented so the 'login.php' token in
//    the list is understood as legacy, not the live portal.)
check("snap-in.php exists at repo root (the real login portal)",
    is_file("$root/snap-in.php"), true);

if ($fails) {
    fwrite(STDERR, "\n$fails assertion(s) FAILED\n");
    exit(1);
}
echo "\nSECAUDIT 049 login-CSP wiring: all checks passed\n";

// ===== SNAPSMACK EOF =====
