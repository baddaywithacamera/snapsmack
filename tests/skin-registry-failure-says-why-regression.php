<?php
declare(strict_types=1);

/**
 * WHEN THE SKIN REGISTRY CANNOT BE REACHED, THE MESSAGE MUST SAY WHY.
 *
 * Codex hit this on pixhellated.ca: every skin setting write was refused for
 * want of a signed-package integrity record, and the built-in recovery failed
 * with "Could not reach the skin registry. Check your server's outbound
 * HTTPS." That sentence was all anyone got.
 *
 * And it was the only sentence available. skin_registry_fetch() captured
 * curl's HTTP code and error text and then THREW BOTH AWAY, so a 403 from the
 * CDN, an expired CA bundle, a redirect loop, a DNS failure, a timeout, and a
 * server with no curl and allow_url_fopen off all produced identical output.
 * The owner is told to check his firewall for a fault that may not be his
 * firewall. That is the opposite of users knowing what we know.
 *
 * WHAT THIS FILE CAN AND CANNOT PROVE. It makes real calls, no stubs. But the
 * PHP that runs these tests has NO curl extension and cannot open https://
 * streams, so THE CURL BRANCH IS NOT EXERCISED HERE and no assertion is made
 * about HTTP codes or a successful fetch. Those need a host with curl. What is
 * proved is the contract: a failure names the URL, names the transport that
 * failed, and two different faults do not produce the same sentence.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');

// THE DEFAULT IS PINNED TO A DEAD URL FOR THIS FILE, DELIBERATELY.
//
// A later change added a fallback: when the configured URL fails and differs
// from the built-in default, the default is tried once. That is right, and it
// is what un-strands a site with a stale setting -- but it meant this file's
// original premise evaporated. Every "unreachable" URL it used now recovers
// via the real default and returns a registry, so there was no error left to
// inspect and five of its six checks failed. The behaviour was correct and MY
// TEST WAS THE THING THAT HAD GONE STALE.
//
// skin-registry.php only defines this constant if it is not already defined,
// so pinning it here makes configured == default, which is the one case with
// nowhere to fall back to. That is the genuine total-failure path, and it is
// the only honest way to assert on a failure message now.
if (!defined('SKIN_REGISTRY_DEFAULT_URL')) {
    define('SKIN_REGISTRY_DEFAULT_URL', 'https://127.0.0.1:1/registry.json');
}
require_once $root . '/core/skin-registry.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$hasCurl = function_exists('curl_init');
$hasStreams = (bool)ini_get('allow_url_fopen');
echo "THIS HOST: curl " . ($hasCurl ? 'present' : 'ABSENT')
   . ", allow_url_fopen " . ($hasStreams ? 'on' : 'off') . "\n";
if (!$hasCurl) {
    echo "  (so the curl branch below is NOT exercised on this host -- stated, not hidden)\n";
}

echo "\nA FAILURE NAMES THE URL AND THE REASON\n";
$dead = 'https://127.0.0.1:1/registry.json';     // nothing listens on port 1
$r = skin_registry_fetch($dead, true);
cx(isset($r['error']), 'an unreachable host is reported as an error');
cx(str_contains((string)($r['error'] ?? ''), $dead),
   'the message NAMES THE URL it tried, which a wrong skin_registry_url makes essential');
cx((string)($r['error'] ?? '') !== 'Could not reach the skin registry. Check your server\'s outbound HTTPS.',
   'it is no longer the single blame-the-firewall sentence');
cx(str_contains((string)($r['error'] ?? ''), 'curl') || str_contains((string)($r['error'] ?? ''), 'fopen')
   || str_contains((string)($r['error'] ?? ''), 'file_get_contents'),
   'and it names which transport failed');
echo "        -> " . (string)($r['error'] ?? '') . "\n";

echo "\nTWO DIFFERENT FAULTS DO NOT GIVE ONE MESSAGE\n";
$missing = 'https://snapsmack.ca/releases/skins/no-such-registry-here.json';
$r2 = skin_registry_fetch($missing, true);
cx(isset($r2['error']), 'a registry that is not there is also an error');
cx((string)($r['error'] ?? 'a') !== (string)($r2['error'] ?? 'b'),
   'an unreachable host and a missing file produce DIFFERENT messages');
cx(str_contains((string)($r2['error'] ?? ''), $missing),
   'and the second one names its own URL, not the first one');

echo "\nA SERVER THAT CANNOT MAKE REQUESTS AT ALL SAYS SO\n";
// This host is exactly that case, which is why it can be asserted here.
if (!$hasCurl) {
    cx(str_contains((string)($r['error'] ?? ''), 'curl is not installed'),
       'a host with no curl is told that, instead of being told to check its firewall');
} else {
    echo "  --    skipped: this host HAS curl, so the no-curl message cannot be observed here\n";
}

echo "\nTHE MESSAGE CARRIES NOTHING IT SHOULD NOT\n";
foreach (['password', 'api_key', 'secret', 'token'] as $leak) {
    cx(stripos((string)($r['error'] ?? ''), $leak) === false,
       "no '{$leak}' in the message");
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
