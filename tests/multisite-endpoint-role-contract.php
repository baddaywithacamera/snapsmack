<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-embedded line must be: // ===== SNAPSMACK EOF =====

/**
 * The key does not say which direction it came from. The role check does.
 *
 * SS-1055. The generic inbound gate in core/multisite-api.php is
 *
 *     WHERE api_key_local = ? AND status = 'active'
 *
 * and it does not look at the matched row's ROLE. Both ends of a relationship
 * store the same key pair in the same columns -- the spoke mints
 * api_key_local (hub->spoke) and api_key_remote (spoke->hub) and returns both,
 * and the hub writes them into its own spoke row. So ONE SECRET
 * AUTHENTICATES INBOUND AT BOTH ENDS, and a spoke presenting the hub->spoke
 * key to the hub passes the gate as a role='spoke' caller.
 *
 * What keeps a spoke out of the privileged operations is therefore not the
 * key. It is the per-endpoint role check. Sixteen endpoints behind that gate
 * do not have one, four of which write.
 *
 * Muse found the first instances independently from a fetched copy; Codex
 * corroborated two on current source; I parsed the whole file for this. The
 * credit matters because I had flagged the directionality question and said
 * plainly that I had NOT established what it reached -- this test is the
 * establishing, and it is structural rather than executed.
 *
 * WHAT THIS TEST IS FOR. Not to assert the sixteen are safe. They are not
 * asserted either way: they are NAMED, with what they do, so that a new
 * endpoint cannot quietly join them. Same shape as the inline-JS rule Sean
 * approved -- the list only shrinks, and anything new fails immediately.
 *
 * WHAT IT CANNOT SEE, said rather than discovered later: the write detector
 * matches direct SQL on snap_ tables inside the endpoint body -- DML and now
 * ALTER/CREATE, after Muse's review found a schema change this pattern had
 * called a read. An endpoint that changes state through a HELPER FUNCTION
 * still reads as "reads" here. So "reads" means "no direct SQL in this
 * block", not "has no effect".
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ep_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/**
 * Every endpoint block, keyed resource[/sub_action]#METHOD, with whether it
 * sits behind the generic gate and whether it checks the caller's role.
 * Brace-matched so each body is the endpoint's own and not its neighbour's.
 */
function ep_parse(string $src): array {
    $gateAt = strpos($src, "WHERE api_key_local = ? AND status = 'active'");
    $gateLine = $gateAt === false ? PHP_INT_MAX : substr_count(substr($src, 0, $gateAt), "\n") + 1;

    preg_match_all('/if\s*\(\s*\$resource\s*===\s*\'([^\']+)\'(.*?)\)\s*\{/s',
        $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $out = [];
    foreach ($m as $hit) {
        $res  = $hit[1][0];
        $cond = $hit[2][0];
        $at   = $hit[0][1];

        $sub = preg_match('/\$sub_action\s*===\s*\'([^\']+)\'/', $cond, $sm) ? '/' . $sm[1] : '';
        $meth = preg_match('/\$method\s*===\s*\'([A-Z]+)\'/', $cond, $mm) ? $mm[1] : 'ANY';
        $key = $res . $sub . '#' . $meth;
        $line = substr_count(substr($src, 0, $at), "\n") + 1;

        // brace-walk from this block's opening brace
        $i = strpos($src, '{', $at + 2);
        $depth = 0; $end = $i;
        for ($n = strlen($src); $i !== false && $i < $n; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        $body = substr($src, $at, $end - $at + 1);

        $out[$key] = [
            'line'       => $line,
            'behindGate' => $line > $gateLine,
            'hasRole'    => (bool) preg_match('/\$node\[\'role\'\]/', $body),
            // ALTER and CREATE are in here because Muse's review caught what
            // this pattern could not see: the 'comments' preamble runs an
            // ALTER TABLE, and a detector matching only row changes called it
            // a read. An endpoint that migrates SCHEMA is a writer.
            'writes'     => (bool) preg_match(
                '/\b(INSERT|UPDATE|DELETE|REPLACE)\s+(INTO\s+)?`?snap_|\b(ALTER|CREATE)\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?snap_/i',
                $body),
        ];
    }
    return $out;
}

$src = (string) file_get_contents($root . '/core/multisite-api.php');
$eps = ep_parse($src);

echo "The parser finds the endpoints at all\n";
ep_test(count($eps) >= 35, 'at least 35 endpoint blocks were parsed (found ' . count($eps) . ')');
ep_test(isset($eps['handshake#POST']), 'the handshake is among them, so the parse reached the top of the file');
ep_test(isset($eps['auth/sso-token#POST']), 'and the SSO token endpoint, so it reached the bottom');

echo "\nMUTATION PROOF: the detector fires, rather than being trusted\n";
// A detector that never fires passes everything. These two synthetic blocks
// prove it distinguishes a gated endpoint from an un-gated one.
$fakeGated = "<?php\n" . str_repeat("\n", 400)
    . "WHERE api_key_local = ? AND status = 'active'\n"
    . "if (\$resource === 'invented' && \$method === 'POST') {\n"
    . "    if (\$node['role'] !== 'hub') { ms_err('nope', 403); }\n"
    . "    \$pdo->prepare(\"UPDATE snap_settings SET setting_val = ''\")->execute();\n"
    . "}\n";
$fakeOpen = str_replace("    if (\$node['role'] !== 'hub') { ms_err('nope', 403); }\n", '', $fakeGated);

$g = ep_parse($fakeGated);
$o = ep_parse($fakeOpen);
ep_test(($g['invented#POST']['hasRole'] ?? null) === true,
    'a synthetic endpoint WITH a role check is seen as gated');
ep_test(($o['invented#POST']['hasRole'] ?? null) === false,
    'and the SAME endpoint with the check deleted is seen as un-gated');
ep_test(($o['invented#POST']['behindGate'] ?? null) === true,
    'and is correctly placed behind the generic gate');
ep_test(($o['invented#POST']['writes'] ?? null) === true,
    'and its write is detected');

/**
 * OPEN BY OMISSION, not by decision.
 *
 * These sixteen sit behind the generic gate with no role check, so a
 * spoke-role caller presenting the hub->spoke key reaches them. They are
 * listed to be shrunk. Nothing here is an assertion that they are safe.
 *
 * Direction is noted where I traced the legitimate caller, because a role
 * gate in the wrong place takes the fleet down -- see SS-1055 acceptance.
 */
$OPEN_BY_OMISSION = [
    'run-crons#ANY'              => 'WRITES snap_settings. CRONOMETER calls it; which key it presents is UNCONFIRMED.',
    'fediverse/status#GET'       => 'reads',
    'fediverse/relay-join#POST'  => 'WRITES snap_settings. Sets the relay URL and joins signed with the target key. Hub->spoke; gateable.',
    'fediverse/relay-admit#POST' => 'no direct SQL in the block, which is not the same as no effect',
    'heartbeat#GET'              => 'reads. DIRECTION NOT ESTABLISHED -- do not gate until it is.',
    'comments#ANY'               => 'NOT AN ENDPOINT: a schema preamble that runs an ALTER TABLE to add snap_comments.is_spam. Reachable by any authenticated caller with no role check. All five real comment routes -- pending, list, action, get, reply -- ARE hub-gated, writers included.',
    'posts/recent#GET'           => 'reads. Hub->spoke; gateable.',
    'stats/daily#GET'            => 'reads. Hub->spoke; gateable.',
    'updates/status#GET'         => 'reads',
    'backup/status#GET'          => 'reads',
    'backup/log#GET'             => 'reads',
    'backup/config#GET'          => 'reads. Exposes cloud provider and folder id.',
    'backup/export#GET'          => 'reads A FULL SQL DUMP, held only by the owner consent flag. SUYB calls it with the scoped key -- do NOT gate naively.',
    'backup/report#POST'         => 'WRITES snap_settings. No caller anywhere in this repository; unknown is not unused.',
    'blogroll/list#GET'          => 'reads. Hub->spoke; gateable.',
    'disconnect#POST'            => 'WRITES snap_multisite_nodes AND clears hub_controls_*. Hub->spoke; gateable.',
];

echo "\nNo endpoint joins the un-gated set without being named\n";
$openNow = [];
foreach ($eps as $key => $e) {
    if ($e['behindGate'] && !$e['hasRole']) $openNow[] = $key;
}
sort($openNow);
$allowed = array_keys($OPEN_BY_OMISSION);
sort($allowed);

$new = array_values(array_diff($openNow, $allowed));
ep_test($new === [],
    'no NEW un-gated endpoint behind the gate'
    . ($new === [] ? '' : ': ' . implode(', ', $new) . ' -- add a role check, or name it and open a ticket'));

$stale = array_values(array_diff($allowed, $openNow));
ep_test($stale === [],
    'no stale rows: the list only shrinks'
    . ($stale === [] ? '' : ', so delete ' . implode(', ', $stale) . ' -- gated now, or gone'));

ep_test(count($openNow) <= count($OPEN_BY_OMISSION),
    'the un-gated count never grows (now ' . count($openNow) . ', listed ' . count($OPEN_BY_OMISSION) . ')');

echo "\nThe privileged writes that ARE gated stay gated\n";
// The fault was an omission, so the cure is not just naming the holes: it is
// stopping an existing gate from being removed by a later "simplification".
foreach ([
    'provision-key#POST'   => 'minting an OH SNAP key',
    'posts/create#POST'    => 'creating a post on another site',
    'settings/push#POST'   => 'pushing settings',
    'auth/sso-token#POST'  => 'issuing an SSO token',
    'maintenance/set#POST' => 'putting a site into maintenance',
    'comments/action#POST'  => 'moderating comments',
    'skins/reinstall#POST' => 'reinstalling a skin',
    'updates/track#POST'   => 'writing update state',
    'blogroll/sync#POST'   => 'rewriting the blogroll',
] as $key => $what) {
    ep_test(($eps[$key]['hasRole'] ?? false) === true,
        "{$what} ({$key}) still checks the caller's role");
}

echo "\nThe pre-gate endpoints keep authenticating their own, directional way\n";
// handshake/ping/peers/search match api_key_remote or an enrollment grant,
// which IS directional. Moving any of them onto the generic gate would widen
// the fault rather than narrow it.
foreach (['handshake#POST', 'ping#GET', 'peers/list#GET', 'search#POST'] as $key) {
    if (!isset($eps[$key])) { ep_test(false, "{$key} was not found; the pre-gate set changed"); continue; }
    ep_test($eps[$key]['behindGate'] === false,
        "{$key} is still in front of the generic gate");
}
ep_test(substr_count($src, 'api_key_remote = ?') >= 3,
    'and at least three of them still match api_key_remote, the directional key');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Multisite endpoint role contract: FAIL ({$fail}).\n";
    exit(1);
}
echo "Multisite endpoint role contract: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
