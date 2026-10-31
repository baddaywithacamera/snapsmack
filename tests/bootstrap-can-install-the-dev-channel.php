<?php
/**
 * The one-file bootstrap deployer can install the development channel, says
 * which channel it is on, and refuses a stale dev feed.
 *
 * SS-1075. The deployer's release feed was a single hardcoded line pointing at
 * the stable manifest. There was no channel argument, no track parameter and
 * no dev feed anywhere in its 429 lines, so the documented one-file install
 * path -- upload one file, open it in a browser -- could only ever produce a
 * 0.7.670 site, which the strict security model refuses on all thirty skins.
 *
 * THE DEFAULT DOES NOT CHANGE. The FEDISTRUCTURE bootstrap defaults to dev;
 * copying that here would change what every public installer user receives,
 * and that is a product decision, not a repair. This adds the switch only.
 *
 * RUN FOR REAL, NOT READ. The deployer refuses outright when core/ exists, so
 * it cannot be rendered from the repository root. Each case copies the single
 * file into an empty scratch directory -- which is exactly how an operator
 * uses it -- and renders it with a GET, then reads the channel off the page.
 * No network call is made: the deployer only fetches on POST.
 *
 * Run:  php tests/bootstrap-can-install-the-dev-channel.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function boot_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

/** Source with comments stripped, so no check can match prose about itself. */
function boot_code(string $path): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

/** Render the deployer alone in an empty directory, as an operator would. */
function boot_render(string $root, string $query): string {
    $dir = sys_get_temp_dir() . '/ss-boot-' . bin2hex(random_bytes(6));
    @mkdir($dir, 0777, true);
    @copy("$root/setup.php", "$dir/setup.php");
    $script = $dir . '/_render.php';
    file_put_contents($script,
        '<?php parse_str(' . var_export($query, true) . ', $_GET);'
      . '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]="example.test";'
      . '$_SERVER["REQUEST_URI"]="/setup.php"; $_SERVER["SCRIPT_NAME"]="/setup.php";'
      . 'ob_start(); include ' . var_export($dir . '/setup.php', true) . '; echo ob_get_clean();');
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
    @rmdir($dir);
    return $out;
}

$setup = boot_code("$root/setup.php");

// ── The switch exists and the dev feed is reachable from it ────────────────
boot_test(str_contains($setup, 'latest-dev.json'),
    'setup.php knows nothing about the development feed, so the one-file path '
  . 'can still only ever install stable');
boot_test(str_contains($setup, "'track'") || str_contains($setup, '"track"'),
    'setup.php has no track switch');

// ── A STALE DEV FEED IS REFUSED. Without a floor the switch could be aimed
//    at an ancient D tag and quietly install it. ──────────────────────────
// THE FLOOR IS EXECUTED, NOT READ. Looking for the words "version_compare" in
// the file stayed green when the comparison was replaced with if(false) -- a
// defanged guard that still spells correctly. The rule is lifted out of the
// deploy handler so it can be called here with real versions.
boot_test((bool)preg_match('/MINIMUM_DEV_VERSION/', $setup),
    'there is no minimum version floor, so the dev switch could install an ancient build');
boot_test((bool)preg_match('/function\s+setup_dev_feed_is_stale\s*\(/', $setup),
    'the floor is inline and cannot be called, so nothing can prove it still bites');
boot_test((bool)preg_match('/setup_dev_feed_is_stale\s*\(\s*\$manifest_version\s*\)/', $setup),
    'the deploy handler does not consult the floor it defines');

if (preg_match("/define\(\s*'SETUP_MINIMUM_DEV_VERSION'\s*,\s*'([^']+)'/", $setup, $minM)
    && preg_match('/(function\s+setup_dev_feed_is_stale\s*\(.*?\n\})/s', $setup, $fnM)) {
    if (!defined('SETUP_MINIMUM_DEV_VERSION')) define('SETUP_MINIMUM_DEV_VERSION', $minM[1]);
    if (!function_exists('setup_dev_feed_is_stale')) eval($fnM[1]);

    $floor = $minM[1];                           // e.g. 0.7.899D
    $below = preg_replace_callback('/(\d+)(D?)$/i',
        static fn(array $m): string => ((int)$m[1] - 1) . $m[2], $floor);

    boot_test(setup_dev_feed_is_stale('') === true, 'a manifest with NO version was accepted');
    boot_test(setup_dev_feed_is_stale('0.7.184D') === true, 'an ancient dev build was accepted');
    boot_test(setup_dev_feed_is_stale($below) === true, "a build below the floor ($below) was accepted");
    boot_test(setup_dev_feed_is_stale($floor) === false, "the floor itself ($floor) was refused");
    boot_test(setup_dev_feed_is_stale('0.7.973D') === false, 'the CURRENT dev build was refused as stale');
    boot_test(setup_dev_feed_is_stale('0.7.973') === false, 'a version without the D suffix was refused');
} else {
    $failures[] = 'could not read the floor constant and function out of setup.php to test them';
}

// ── THE HUB REFUSES A REQUEST WITH NO USER AGENT. Measured today: the public
//    release host answered 403 to a default client and 200 to a named one. The
//    deployer sent no agent at all, so the one-file path would have failed at
//    the first fetch with a message the operator cannot act on. ────────────
boot_test(str_contains($setup, 'CURLOPT_USERAGENT'),
    'the deployer sends no User-Agent, and the release host refuses those');
// Checked on the VALUE, not on how it is spelled at the call site. The first
// version looked for a string literal next to CURLOPT_USERAGENT and failed
// when the agent was quite properly lifted into a constant -- a check that
// pins style rather than behaviour.
boot_test((bool)preg_match("/define\(\s*'SETUP_USER_AGENT'\s*,\s*'(SnapSmack[^']*)'/", $setup, $uaM),
    'there is no named SnapSmack user agent defined');
boot_test(isset($uaM[1]) && $uaM[1] !== '' && stripos($uaM[1], 'snapsmack') === 0,
    'the user agent does not identify itself as SnapSmack: '
  . (isset($uaM[1]) ? $uaM[1] : 'none'));
// And both fetch paths must actually use it, not just declare it.
boot_test((bool)preg_match('/CURLOPT_USERAGENT\s*,\s*SETUP_USER_AGENT/', $setup),
    'cURL declares a user agent constant and does not send it');
boot_test((bool)preg_match("/'user_agent'\s*=>\s*SETUP_USER_AGENT/", $setup),
    'the no-cURL fallback declares a user agent constant and does not send it');
// The fallback path has to identify itself too, or a host without cURL is
// refused for a reason nobody can see.
boot_test(str_contains($setup, 'stream_context_create') || str_contains($setup, 'user_agent'),
    'the no-cURL fallback still sends PHP default headers, so it will be refused');

// ── Failing loudly, and never half-installing ──────────────────────────────
// The sequence already guards every step on $error and unlinks the package if
// verification fails. What must not regress is that extraction is gated.
boot_test((bool)preg_match('/if\s*\(!\$error\)\s*\{\s*\$zip\s*=\s*new ZipArchive/', $setup),
    'extraction is no longer gated on there being no error; a refused or '
  . 'unverified download could be unpacked over the directory');

// ── Rendered, in an empty directory, as an operator actually uses it ───────
$plain = boot_render($root, '');
$dev   = boot_render($root, 'track=dev');

boot_test(stripos($plain, 'fatal error') === false && stripos($dev, 'fatal error') === false,
    'the deployer does not render: ' . substr(strip_tags($plain . $dev), 0, 180));
boot_test(stripos($plain, 'STABLE') !== false,
    'the default render does not say it is on the stable channel; the operator '
  . 'cannot tell what they are about to install');
boot_test(stripos($dev, 'DEV') !== false,
    'asking for the dev channel does not produce a dev render');
// THE DEFAULT MUST NOT MOVE. Changing what the public one-file path installs
// is a product decision and is deliberately not in this repair.
boot_test(stripos($plain, '>DEV<') === false && !preg_match('/Channel:\s*<[^>]*>\s*DEV/i', $plain),
    'the DEFAULT channel has become dev; that changes what every public '
  . 'installer user receives and is not this repair to make');

if ($failures) {
    fwrite(STDERR, "FAIL bootstrap dev channel (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS the bootstrap can install the dev channel, says which, and refuses a stale feed\n";
// ===== SNAPSMACK EOF =====
