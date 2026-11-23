<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1481 finding 15 — the public-timeline fallback is cached briefly.
 *
 * THE FAULT. sv_public_timeline() runs when the owner's local or global tab is
 * empty, and made TWO live JSON requests per view -- the Pixelfed endpoint,
 * then the Mastodon one as a fallback -- from two callers (pixel.php:146,
 * smack-pixelfed.php:202), with no cache. Refreshing an empty tab was two
 * round trips against someone else's server, every time.
 *
 * Re-verified at caebe17b before changing anything: no cache in the function,
 * and both call sites passing no handle to one with.
 *
 * WHAT THIS PROVES, by counting requests rather than asserting a call: a cold
 * call fetches, a warm one does not, an expired one fetches again, and the
 * cache is keyed so the local tab cannot serve the global tab's content. The
 * empty result is cached too -- a dead instance is the case that would
 * otherwise be re-fetched twice on every refresh.
 *
 * NOT COVERED: real HTTP. sv_fetch_json() is stubbed, which is the seam the
 * request count is taken at.
 */

$root = dirname(__DIR__);
$failures = [];

function pt_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

/** Every outbound request is recorded, so the saving is measured. */
$GLOBALS['pt_requests'] = [];
function sv_fetch_json(string $url) {
    $GLOBALS['pt_requests'][] = $url;
    return $GLOBALS['pt_body'] ?? null;
}
function sv_account_actor_id(array $acct): string { return (string)($acct['id'] ?? ''); }
function sv_client_plain_text(string $html): string { return strip_tags($html); }

$src = (string)file_get_contents($root . '/core/fediverse.php');
$start = strpos($src, 'function sv_public_timeline(');
if ($start === false) { fwrite(STDERR, "missing sv_public_timeline\n"); exit(1); }
$end  = strpos($src, "\nfunction ", $start + 1);
$body = substr($src, $start, $end - $start);
eval(substr($body, 0, strrpos($body, "\n}") + 2));

/**
 * sqlite that understands the one MySQL upsert the code under test runs.
 *
 * MY FIRST VERSION OF THIS FIXTURE WAS WORTHLESS AND SAID SO IN ITS OWN
 * FOOTER. sqlite rejects ON DUPLICATE KEY UPDATE; the production write is
 * wrapped in a try/catch because a cache is a convenience; so the write
 * silently never happened, and three assertions that depended on it failed
 * while the code was correct. A test whose stated limitation contradicts its
 * own assertions is not a test.
 *
 * So the dialect is translated here. Shim the database, not the code under
 * test -- the same choice as teaching sqlite NOW() elsewhere in this suite.
 * Deliberately narrow: exactly the statement this function issues, so it
 * cannot quietly paper over a different query later.
 */
class PtPdo extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace(
            'ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_val = excluded.setting_val',
            $query
        );
        return parent::prepare($query, $options);
    }
}

function pt_pdo(): PDO {
    $pdo = new PtPdo('sqlite::memory:', null, null,
                     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    return $pdo;
}

/** One status with one image, the minimum sv_public_timeline will keep. */
function pt_body(string $id): array {
    return [[
        'uri' => $id, 'url' => $id, 'created_at' => '2026-11-20T00:00:00Z',
        'content' => '<p>Hello</p>',
        'media_attachments' => [['type' => 'image', 'url' => 'https://img.invalid/1.jpg']],
        'account' => ['acct' => 'someone@remote.invalid', 'display_name' => 'Someone',
                      'avatar' => 'https://img.invalid/a.png', 'id' => 'https://remote.invalid/users/someone'],
    ]];
}

echo "SS-1481 #15 — the public-timeline fallback is cached\n\n";

$host = 'remote.invalid';

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — NO HANDLE MEANS NO CACHE: behaviour is unchanged\n";
// ─────────────────────────────────────────────────────────────────────────────
// The cache is opt-in. A caller that passes nothing must behave exactly as it
// did, or this change would alter paths it was never meant to touch.
$GLOBALS['pt_body'] = pt_body('https://remote.invalid/p/1');
$GLOBALS['pt_requests'] = [];
$a = sv_public_timeline($host, true, 30);
$b = sv_public_timeline($host, true, 30);
pt_check('without a database handle both calls fetch',
         count($GLOBALS['pt_requests']) === 2, $failures);
pt_check('and both return the item', count($a) === 1 && count($b) === 1, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE SAVING, COUNTED\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo = pt_pdo();
$GLOBALS['pt_requests'] = [];
$cold = sv_public_timeline($host, true, 30, $pdo, 600);
$coldCount = count($GLOBALS['pt_requests']);
pt_check('COLD: the timeline is fetched', $coldCount >= 1, $failures);
pt_check('and an item comes back', count($cold) === 1, $failures);

$GLOBALS['pt_requests'] = [];
$warm = sv_public_timeline($host, true, 30, $pdo, 600);
pt_check('WARM: nothing is fetched at all',
         $GLOBALS['pt_requests'] === [], $failures);
pt_check('and the same item is returned from the cache',
         $warm === $cold && count($warm) === 1, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — IT EXPIRES\n";
// ─────────────────────────────────────────────────────────────────────────────
// A cache that never expires is a different bug. A zero TTL must also mean
// "no cache", so the behaviour can be turned off at the call site.
$GLOBALS['pt_requests'] = [];
sv_public_timeline($host, true, 30, $pdo, 0);
pt_check('a zero TTL fetches, so the cache can be switched off',
         $GLOBALS['pt_requests'] !== [], $failures);

// Age the stored row past its window rather than waiting.
$row = $pdo->query("SELECT setting_key, setting_val FROM snap_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$aged = json_decode((string)$row['setting_val'], true);
$aged['at'] = time() - 3600;
$pdo->prepare('UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?')
    ->execute([json_encode($aged), $row['setting_key']]);
$GLOBALS['pt_requests'] = [];
sv_public_timeline($host, true, 30, $pdo, 600);
pt_check('an entry older than the window is re-fetched',
         $GLOBALS['pt_requests'] !== [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE LOCAL TAB CANNOT SERVE THE GLOBAL TAB\n";
// ─────────────────────────────────────────────────────────────────────────────
// Two tabs, two different queries, so a shared key would show the owner the
// wrong feed. That is the mistake worth guarding here.
$pdo2 = pt_pdo();
$GLOBALS['pt_requests'] = [];
sv_public_timeline($host, true, 30, $pdo2, 600);     // local
$GLOBALS['pt_requests'] = [];
sv_public_timeline($host, false, 30, $pdo2, 600);    // global
pt_check('the global tab fetches rather than reusing the local tab\'s entry',
         $GLOBALS['pt_requests'] !== [], $failures);
pt_check('and the two tabs keep separate entries',
         (int)$pdo2->query('SELECT COUNT(*) FROM snap_settings')->fetchColumn() === 2, $failures);

// A different host must not share either.
$GLOBALS['pt_requests'] = [];
sv_public_timeline('other.invalid', true, 30, $pdo2, 600);
pt_check('and a different host fetches too',
         $GLOBALS['pt_requests'] !== [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — AN EMPTY RESULT IS CACHED, AND A BAD ENTRY IS A MISS\n";
// ─────────────────────────────────────────────────────────────────────────────
// A dead instance is the case that would otherwise be re-fetched twice on every
// single refresh, so caching nothing is the point rather than an oversight.
$pdo3 = pt_pdo();
$GLOBALS['pt_body'] = [];
$GLOBALS['pt_requests'] = [];
$empty = sv_public_timeline($host, true, 30, $pdo3, 600);
pt_check('an instance with nothing to show returns nothing', $empty === [], $failures);
$GLOBALS['pt_requests'] = [];
sv_public_timeline($host, true, 30, $pdo3, 600);
pt_check('and that empty answer is served from the cache, not re-fetched twice',
         $GLOBALS['pt_requests'] === [], $failures);

// Corrupt the row: a cache that cannot be read must fetch, never throw.
$pdo3->exec("UPDATE snap_settings SET setting_val = 'not json at all'");
$GLOBALS['pt_requests'] = [];
$threw = false;
try { sv_public_timeline($host, true, 30, $pdo3, 600); } catch (Throwable $e) { $threw = true; }
pt_check('an unreadable entry is a miss, not an error',
         !$threw && $GLOBALS['pt_requests'] !== [], $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1481 #15 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1481 #15 PASS — a warm call makes no request, the entry expires, the\n";
echo "local and global tabs and separate hosts keep separate entries, an empty\n";
echo "answer is cached, and an unreadable entry falls back to a live fetch.\n";
echo "NOT COVERED: real HTTP; sv_fetch_json() is the seam requests are counted\n";
echo "at. The fixture translates the one MySQL upsert this function issues
";
echo "into sqlite's form, so the write path really runs here rather than
";
echo "being swallowed as a tolerated dialect error.
";
// ===== SNAPSMACK EOF =====
