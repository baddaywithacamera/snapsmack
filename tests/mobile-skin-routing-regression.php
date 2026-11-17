<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Phones get the phone skin; large tablets get the desktop skin.
 *
 * Sean's rule, as the PM set it: a PHONE is routed to the mobile skin
 * (PHOTOGRAM for photo and carousel sites, TELEGRAM for SMACKTALK), and a
 * LARGE TABLET is NOT -- it receives the active desktop skin (e.g. GLIDE). The
 * decision is made by device class and site mode only; it must never be special
 * -cased on which desktop skin is active.
 *
 * snapsmack_is_mobile() is the device gate and is CALLED here for real against
 * representative user agents and the Sec-CH-UA-Mobile client hint. The public
 * selection block in index.php is also checked, bounded to itself, to prove it
 * keys on is_mobile() and site mode and names no desktop skin.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** Set the request headers snapsmack_is_mobile() reads, then call it. */
function is_mobile_for(?string $ua, ?string $chMobile = null): bool {
    unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_SEC_CH_UA_MOBILE']);
    if ($ua !== null) $_SERVER['HTTP_USER_AGENT'] = $ua;
    if ($chMobile !== null) $_SERVER['HTTP_SEC_CH_UA_MOBILE'] = $chMobile;
    return snapsmack_is_mobile();
}

cx(function_exists('snapsmack_is_mobile'), 'snapsmack_is_mobile() is available to call');

// Representative user agents.
$iphone      = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$android_ph  = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
$ipad_modern = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
$android_tab = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$desktop     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

echo "PHONES are mobile\n";
cx(is_mobile_for($iphone)     === true, 'an iPhone is a phone');
cx(is_mobile_for($android_ph) === true, 'an Android phone (UA carries the Mobile token) is a phone');

echo "\nLARGE TABLETS are NOT mobile (they keep the desktop skin)\n";
cx(is_mobile_for($ipad_modern) === false, 'a modern iPad (desktop-class UA, no Mobile token) is treated as desktop');
cx(is_mobile_for($android_tab) === false, 'an Android tablet (no Mobile token) is treated as desktop');
cx(is_mobile_for($desktop)     === false, 'a desktop browser is not a phone');

echo "\nThe Sec-CH-UA-Mobile client hint is authoritative\n";
cx(is_mobile_for($iphone, '?0') === false, 'Desktop-site on a phone (hint ?0) overrides the phone UA');
cx(is_mobile_for($desktop, '?1') === true, 'the phone hint ?1 is honoured regardless of UA');

echo "\nThe phone skins exist and are the configured mobile skins\n";
cx(defined('SNAPSMACK_MOBILE_SKIN') && SNAPSMACK_MOBILE_SKIN === 'photogram',
   'PHOTOGRAM is the default mobile skin');
cx(is_dir($root . '/skins/photogram'), 'the PHOTOGRAM skin is installed');
cx(is_dir($root . '/skins/telegram'), 'the TELEGRAM skin is installed');

echo "\nThe public selection keys on device and mode, with no per-desktop-skin exception\n";
$idx = (string)file_get_contents($root . '/index.php');
$at  = strpos($idx, '$_snapsmack_mobile_skin = SNAPSMACK_MOBILE_SKIN;');
cx($at !== false, 'the mobile-skin selection block was located in index.php');
$end = $at === false ? false : strpos($idx, '$active_skin = $_snapsmack_mobile_skin;', (int)$at);
cx($end !== false, 'and the block ends where it assigns the active skin');
$block = ($at === false || $end === false) ? '' : substr($idx, (int)$at, (int)$end - (int)$at + 40);

cx(str_contains($block, 'snapsmack_is_mobile()'),
   'the block gates on snapsmack_is_mobile() (the phone/large-tablet decision)');
cx(str_contains($block, "'smacktalk'") && str_contains($block, "'telegram'"),
   'SMACKTALK sites route to TELEGRAM; every other mode keeps PHOTOGRAM');
// No desktop skin slug may appear: the routing is device plus mode, never a
// per-skin exception. Checked against the skins actually installed.
$deskExceptions = [];
foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $slug = basename($dir);
    if ($slug === 'photogram' || $slug === 'telegram') continue;
    if (str_contains($block, "'" . $slug . "'")) $deskExceptions[] = $slug;
}
cx($deskExceptions === [],
   'no desktop skin is named in the selection block (no skin-specific exception)'
   . ($deskExceptions === [] ? '' : ': ' . implode(', ', $deskExceptions)));

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
