<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Adding a spoke is a fleet action, so it needs step-up. It had none at all.
 *
 * SS-1053. Found on 29 October while verifying SECAUDIT 061 finding B against
 * current source rather than trusting the report, which had not noticed it.
 *
 * THE INCONSISTENCY WAS THE EVIDENCE. In smack-multisite.php:
 *
 *   gen_reg_token              calls reauth_verify()   gated
 *   the hub permissions block  calls reauth_verify()   gated when enabling
 *   register_spoke             called NOTHING          not gated
 *
 * So an authenticated admin session on the hub could add a fleet member
 * without re-entering a password or a 2FA code. gen_reg_token's own comment
 * already stated the principle the codebase had agreed -- "JOINING a hub
 * grants a remote party admin-level access to this site, so it is gated by
 * step-up auth" -- and adding a spoke is the same class of action in the
 * other direction: it grants THIS hub admin-level access to another site.
 *
 * WHY IT IS GATED EVEN THOUGH SEAN'S LAPTOP IS ASSUMED TO BE SEAN. He ruled
 * on 29 October that fleet enrollment assumes the laptop is him and deferred
 * independent presence proof (SECAUDIT 061 §5.5, option 3, recorded as
 * DEFERRED BY OWNER). He asked for this gate anyway. The assumption is about
 * PRESENCE; it was never about skipping the ceremony, and a password plus 2FA
 * still applies on his own machine.
 *
 * These are SOURCE assertions and say so: the handler needs a database, a
 * session and a live spoke to execute. What they prove is structural -- that
 * the gate exists, that it runs BEFORE anything leaves the machine or reaches
 * the node table, and that nothing between the two clears the refusal.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function hs_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$src = (string) file_get_contents($root . '/smack-multisite.php');

/** The register_spoke handler, brace-matched so every claim is about IT. */
function hs_handler(string $src, string $needle): string {
    $at = strpos($src, $needle);
    if ($at === false) return '';
    $i = strpos($src, '{', $at);
    if ($i === false) return '';
    $depth = 0;
    for ($n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($src, $at, $i - $at + 1);
        }
    }
    return '';
}

/** Comments stripped, so ordering is about code and not about prose. */
function hs_code(string $php): string {
    $out = '';
    foreach (token_get_all("<?php " . $php) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$handler = hs_handler($src, "if (isset(\$_POST['register_spoke'])) {");
hs_test($handler !== '', 'the register_spoke handler was located, so the checks below mean something');
$code = hs_code($handler);

echo "The gate exists, and it is the same one every other step-up uses\n";
hs_test(strpos($code, 'reauth_verify($pdo') !== false,
    'register_spoke calls reauth_verify(), which it did not before');
hs_test(strpos($code, "require_once 'core/reauth.php'") !== false,
    'and loads the shared helper rather than rolling its own check');
hs_test(strpos($code, "\$_POST['reauth_password']") !== false
        && strpos($code, "\$_POST['reauth_totp']") !== false,
    'it reads both the password and the 2FA code from the request');
hs_test(stripos($code, 'Spoke registration blocked') !== false,
    'and the refusal says what was blocked, not something the admin was not doing');

echo "\nIt runs BEFORE anything leaves this machine or reaches the node table\n";
$atAuth   = strpos($code, 'reauth_verify($pdo');
$atGuard  = strpos($code, 'if (!isset($err))');
$atRemote = strpos($code, 'handshake');
$atWrite  = strpos($code, 'snap_multisite_nodes');
hs_test($atGuard !== false, 'the existing $err guard is still there');
hs_test($atAuth !== false && $atGuard !== false && $atAuth < $atGuard,
    'the step-up is evaluated before that guard');
hs_test($atRemote !== false && $atGuard < $atRemote,
    'the guard precedes the call to the spoke, so a failed step-up contacts nobody');
hs_test($atWrite !== false && $atGuard < $atWrite,
    'and precedes the write to snap_multisite_nodes, so a failed step-up changes nothing');

echo "\nNothing between the refusal and the write can clear it\n";
// The gate works by setting $err. If anything in this handler unset $err, or
// reassigned it to empty, the refusal would evaporate and the insert would
// run. That is the one way this design fails, so it is pinned.
hs_test(strpos($code, 'unset($err)') === false,
    'the handler never unsets $err');
hs_test(preg_match('/\$err\s*=\s*(?:\'\'|""|null|false)\s*;/i', $code) !== 1,
    'and never blanks it');
hs_test(substr_count($code, '} elseif (empty($spoke_url)') === 1,
    'the field check is an elseif, so it cannot overwrite the step-up refusal with its own message');

echo "\nThe form asks for what the handler requires\n";
// A gate the form cannot satisfy is a broken page, not a secure one.
$formAt = strpos($src, 'name="register_spoke"');
$form   = $formAt === false ? '' : substr($src, max(0, $formAt - 2200), 2400);
hs_test($form !== '', 'the register-spoke form was located');
hs_test(strpos($form, 'name="reauth_password"') !== false,
    'the form has a password field');
hs_test(strpos($form, 'name="reauth_totp"') !== false,
    'and a 2FA field');
hs_test(substr_count($form, 'required') >= 2,
    'and both are required, so the gate is not reached with blanks by accident');
hs_test(stripos($form, 'admin-level access') !== false,
    'and it says WHY it is asking, which is the part that makes the prompt reasonable');

echo "\nAll three fleet actions in this file are gated the same way\n";
// The fault was an inconsistency, so the consistency is what gets pinned.
foreach ([
    "if (isset(\$_POST['gen_reg_token'])) {"   => 'generating a registration token',
    "if (isset(\$_POST['register_spoke'])) {"  => 'registering a spoke',
] as $needle => $label) {
    $h = hs_code(hs_handler($src, $needle));
    hs_test($h !== '' && strpos($h, 'reauth_verify($pdo') !== false,
        "{$label} requires step-up");
}
hs_test(substr_count($src, 'reauth_verify($pdo') >= 3,
    'and the file has at least three step-up gates, where it used to have two');

echo "\nLeaving a hub is still NOT gated, on purpose\n";
// Sean's standing rule, and gen_reg_token's comment states it: leaving only
// reduces access and may be needed in an emergency. A test that quietly
// gated it would be a test that broke an escape hatch.
$leave = hs_code(hs_handler($src, "if (isset(\$_POST['disconnect_hub'])) {"));
hs_test($leave === '' || strpos($leave, 'reauth_verify($pdo') === false,
    'disconnecting from a hub needs no password, because it only reduces access');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Hub spoke registration step-up: FAIL ({$fail}).\n";
    exit(1);
}
echo "Hub spoke registration step-up: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
