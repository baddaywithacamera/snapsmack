<?php
/**
 * A Scroll Time beacon can only write to the hit it was served for, and it
 * cannot claim more time than has actually passed.
 *
 * SS-1083. The dwell route is unauthenticated BY DESIGN -- it is a first-party
 * beacon that must survive page unload -- and it took the hit id from the
 * request body. snap_stats.id is an auto-increment record id that is PUBLISHED
 * to every visitor, because the logger emits data-hit="<id>" so the engine can
 * use it. So a caller did not have to guess anything: it read its own id off
 * the page and could walk neighbouring ids, and within the six-hour window on
 * a non-bot hit set any of them to the thirty-minute cap.
 *
 * TWO GUARDS, BECAUSE THE TOKEN ALONE IS NOT ENOUGH AND IT IS IMPORTANT TO BE
 * HONEST ABOUT THAT. The token is printed on the page beside the id, so a
 * visitor who was served the page has both. It therefore stops ONE caller
 * writing to ANOTHER hit -- the neighbour walking -- and does nothing about a
 * visitor inflating their own. The elapsed-time clamp is what addresses the
 * second: a hit that is ten seconds old cannot carry thirty minutes of
 * engagement, whoever asks.
 *
 * What this does NOT claim to do: stop a determined visitor from slowly
 * inflating their own single hit in real time, or rate-limit the endpoint.
 * Those are separate decisions and are not in this repair.
 *
 * Runs the real functions against an in-memory SQLite stand-in. The recorder's
 * query uses MySQL date functions, so the stand-in PDO rewrites them the same
 * way tests/postmodel-born-a-post-regression.php rewrites FOR UPDATE -- the SQL
 * is EXECUTED here, not merely read.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function dwell_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

/** Source with comments stripped, so a check can never match prose about itself. */
function dwell_code(string $path): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

// ── Static: each piece of the chain is present in CODE, not in a comment ────
$logger = dwell_code("$root/core/stats-logger.php");
$api    = dwell_code("$root/core/stats-api.php");
$engine = (string)file_get_contents("$root/assets/js/ss-engine-scrolltime.js");

dwell_test(str_contains($logger, 'function snapsmack_dwell_token'),
    'core/stats-logger.php has no snapsmack_dwell_token()');
dwell_test(str_contains($logger, 'hash_hmac'),
    'the token is not an HMAC');
dwell_test(str_contains($logger, 'data-token'),
    'the emitted tracker tag carries no data-token for the engine to send');
dwell_test(str_contains($logger, 'function snapsmack_dwell_token_ok'),
    'there is no testable verification function; the decision is buried in the route');
dwell_test(str_contains($logger, 'hash_equals'),
    'the token is compared without hash_equals');
dwell_test(str_contains($logger, 'LEAST') && str_contains($logger, 'TIMESTAMPDIFF'),
    'the recorder does not clamp the reported time to the time that has actually passed');
dwell_test(str_contains($api, 'snapsmack_dwell_token_ok'),
    'the dwell route does not verify the token');
dwell_test(str_contains($engine, 'data-token') || str_contains($engine, 'dataset.token'),
    'the beacon engine never reads the token off its own script tag');
// NOT just "the word token appears somewhere". The first version of this check
// was str_contains($engine, 'token'), and it stayed green when the token was
// deleted from the payload -- because the variable that fetches it is still
// declared a few lines up. The token has to be IN WHAT IS SENT.
dwell_test((bool)preg_match('/JSON\.stringify\(\s*\{[^}]*\btoken\b/', $engine),
    'the beacon engine reads the token and then does not put it in the payload');
// The route's header described an unfixed exploit in a file that ships to every
// site. Once the repair lands, that text must describe the guard instead.
dwell_test(!str_contains((string)file_get_contents("$root/core/stats-api.php"), 'NOT FIXED'),
    'core/stats-api.php still ships a comment saying the beacon is NOT FIXED');

// ── Live: the real functions against a stand-in database ────────────────────
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    /** Rewrites the MySQL date/compare functions the recorder uses. */
    class DwellTestPdo extends PDO {
        #[\ReturnTypeWillChange]
        public function prepare($sql, $options = []) {
            $sql = str_replace(
                ['GREATEST(', 'LEAST(', 'DATE_SUB(NOW(), INTERVAL 6 HOUR)'],
                ['MAX(',      'MIN(',   "datetime('now','-6 hours')"],
                $sql
            );
            $sql = preg_replace(
                '/TIMESTAMPDIFF\(\s*SECOND\s*,\s*hit_at\s*,\s*NOW\(\)\s*\)/i',
                "(strftime('%s','now') - strftime('%s',hit_at))",
                $sql
            ) ?? $sql;
            return parent::prepare($sql, $options);
        }
    }
    $pdo = new DwellTestPdo('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT, setting_val TEXT)");
    $pdo->exec("CREATE TABLE snap_stats (id INTEGER PRIMARY KEY, is_bot INT DEFAULT 0,
        hit_at TEXT, dwell_ms INT NULL, page_type TEXT)");
    // A real, per-install salt, so the token is not signed with the shipped default.
    $pdo->exec("INSERT INTO snap_settings VALUES ('download_salt', 'a-real-per-install-salt-value')");
    $GLOBALS['pdo'] = $pdo;

    $fresh  = static fn(): string => gmdate('Y-m-d H:i:s');
    $ago    = static fn(int $s): string => gmdate('Y-m-d H:i:s', time() - $s);
    $insert = $pdo->prepare("INSERT INTO snap_stats (id, is_bot, hit_at, page_type) VALUES (?,?,?,'archive')");
    $dwell  = static function (PDO $p, int $id): ?int {
        $v = $p->query("SELECT dwell_ms FROM snap_stats WHERE id = $id")->fetchColumn();
        return $v === null || $v === false ? null : (int)$v;
    };

    // ONLY the logger is loaded here, deliberately. It is what a public page
    // render loads, and it is responsible for pulling in the salt helper it
    // signs with. An earlier version of this test required secret-store.php
    // itself, which defined the function in the test's process and made the
    // check blind to the logger failing to load it -- so removing that require
    // from production code left the suite green while every page would have
    // emitted an empty token.
    require_once "$root/core/stats-logger.php";

    //  id  bot  age
    $insert->execute([101, 0, $ago(600)]);   // ten minutes old, human
    $insert->execute([102, 0, $ago(600)]);   // its neighbour
    $insert->execute([103, 0, $fresh()]);    // brand new
    $insert->execute([104, 1, $ago(600)]);   // a bot
    $insert->execute([105, 0, $ago(25200)]); // seven hours old

    // -- the token itself
    $t101 = snapsmack_dwell_token(101, $pdo);
    $t102 = snapsmack_dwell_token(102, $pdo);
    dwell_test($t101 !== '' && strlen($t101) >= 32, 'the token is empty or too short to be a signature');
    dwell_test($t101 === snapsmack_dwell_token(101, $pdo), 'the token is not stable for one hit');
    dwell_test($t101 !== $t102, 'two different hits share a token, so one covers the other');
    dwell_test(snapsmack_dwell_token(0, $pdo) === '', 'a nonexistent hit still gets a token');

    // -- the verification decision
    dwell_test(snapsmack_dwell_token_ok(101, $t101, $pdo) === true,
        'the correct token for the correct hit was refused');
    dwell_test(snapsmack_dwell_token_ok(101, '', $pdo) === false,
        'a beacon with NO token was accepted');
    dwell_test(snapsmack_dwell_token_ok(101, 'not-a-real-token', $pdo) === false,
        'a beacon with a junk token was accepted');
    dwell_test(snapsmack_dwell_token_ok(102, $t101, $pdo) === false,
        'A NEIGHBOUR HIT ACCEPTED ANOTHER HIT S TOKEN -- this is the walk SS-1083 describes');

    // -- the elapsed clamp, which is what stops an instant cap
    snapsmack_record_dwell($pdo, 103, 1800000);          // brand new hit claims 30 minutes
    $got = $dwell($pdo, 103);
    dwell_test($got !== null && $got < 120000,
        'a hit seconds old recorded minutes of engagement: got ' . var_export($got, true));

    snapsmack_record_dwell($pdo, 101, 1800000);          // ten-minute-old hit claims 30 minutes
    $got = $dwell($pdo, 101);
    dwell_test($got !== null && $got <= 640000,
        'a ten-minute-old hit recorded more than ten minutes: got ' . var_export($got, true));

    // -- an honest value still lands, and the guard has broken nothing
    snapsmack_record_dwell($pdo, 102, 45000);            // 45s on a ten-minute-old hit
    dwell_test($dwell($pdo, 102) === 45000,
        'an honest, plausible reading was clamped or refused: got ' . var_export($dwell($pdo, 102), true));
    snapsmack_record_dwell($pdo, 102, 20000);            // a later, shorter sample
    dwell_test($dwell($pdo, 102) === 45000,
        'a shorter later beacon lowered the recorded time; it must converge on the maximum');

    // -- THE EMITTED TAG MUST CARRY A REAL TOKEN, not an empty attribute.
    // This is the regression that would hurt most. The engine now refuses to
    // send without a token, so if the emitter ever produces data-token="" --
    // because the salt helper was not reachable, say -- Scroll Time goes
    // silently to zero everywhere and every other check here still passes.
    if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
    $GLOBALS['settings'] = ['site_mode' => 'photoblog'];
    $GLOBALS['snapsmack_hit'] = ['id' => 101, 'page_type' => 'archive'];
    ob_start(); snapsmack_scrolltime_tag($GLOBALS['settings']); $tag = (string)ob_get_clean();
    dwell_test(str_contains($tag, 'data-hit="101"'), 'the tracker tag lost its hit id');
    dwell_test((bool)preg_match('/data-token="([0-9a-f]{32,})"/', $tag, $m),
        'the tracker tag emitted an EMPTY data-token, so no beacon can ever report');
    dwell_test(isset($m[1]) && $m[1] === snapsmack_dwell_token(101, $pdo),
        'the token on the page is not the token the route will expect');

    // -- the guards that were already there must still hold
    snapsmack_record_dwell($pdo, 104, 30000);
    dwell_test($dwell($pdo, 104) === null, 'a bot hit recorded engagement time');
    snapsmack_record_dwell($pdo, 105, 30000);
    dwell_test($dwell($pdo, 105) === null, 'a hit seven hours old still accepted a beacon');
} else {
    $failures[] = 'NOT CHECKED: no sqlite driver, so none of the live behaviour ran';
}

if ($failures) {
    fwrite(STDERR, "FAIL dwell beacon binding (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS dwell beacon is bound to its hit and cannot outrun the clock\n";
// ===== SNAPSMACK EOF =====
