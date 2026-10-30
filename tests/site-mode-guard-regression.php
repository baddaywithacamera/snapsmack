<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * A posting tool must never be able to change what KIND of site this is.
 *
 * The incident (2026-08-06, fauxlaroid.fyi): snap_api_enforce_mode() responded to
 * a mode mismatch by silently rewriting snap_settings.site_mode to whatever the
 * calling tool wanted. SYBU's Connect fetches categories through
 * smack-post-solo.php, which declares photoblog-only — so pressing Connect turned
 * a 1,076-post GramOfSmack blog into a photo blog. Every gram upload then failed
 * 409, and because the skin gallery hides gram skins while the mode is wrong, the
 * site could not be switched back from the admin at all. Recovery needed a signed
 * VAX package.
 *
 * Two halves are pinned here: the server may only auto-set the mode on an EMPTY
 * site, and its refusal must tell the owner how to actually change it.
 */

$failures = [];
$checks   = 0;
function m_ok(bool $ok, string $msg): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $msg;
}

$src = file_get_contents(__DIR__ . '/../core/api-auth.php');
$skin = file_get_contents(__DIR__ . '/../smack-skin.php');
$guard = file_get_contents(__DIR__ . '/../core/mode-guard.php');

// ── The auto-flip must be gated on the site being empty ─────────────────────
m_ok(str_contains($src, '$has_content'),
     'the emptiness check is gone — a tool could convert an established site again');
m_ok(preg_match('/count\(\$allowed\) === 1 && !\$has_content/', $src) === 1,
     'the auto-set is no longer gated on an empty site');
m_ok(str_contains($src, 'COUNT(*) FROM snap_posts') && str_contains($src, 'COUNT(*) FROM snap_images'),
     'emptiness is not measured from both posts and images');

// Fail closed: if the count cannot be read, assume the site HAS content.
m_ok(preg_match('/catch \(PDOException \$e\) \{\s*\n\s*\$has_content = true;/', $src) === 1,
     'a failed count no longer defaults to "treat as established" — it must fail closed');

// ── The refusal must be actionable and true ─────────────────────────────────
// There is no site-mode control in Settings; the mode follows the active skin.
// Telling the owner to look in Settings is what turned a five-minute problem
// into an hour of hunting.
m_ok(!preg_match('/mode to.*in Settings, then re-post/i', $src),
     'the refusal still points at a Settings control that does not exist');
m_ok(str_contains($src, 'how_to_change'),
     'the refusal no longer tells the owner how to change the mode');
m_ok(str_contains($src, 'follows the active skin'),
     'the refusal no longer explains that the mode follows the skin');

m_ok(substr_count($skin, 'snap_mode_conflict($pdo,') >= 2,
     'one of the two skin activation paths can still switch an established site mode');
m_ok(str_contains($skin, "require_once 'core/mode-guard.php'"),
     'skin administration no longer loads the content-shape guard');
m_ok(str_contains($skin, 'No skin or mode setting was changed.'),
     'Customize refusal no longer confirms that the save was safely aborted');

// Since 539D, SMACKONEOUT and GRAMOFSMACK both create post-backed single-image
// rows. The guard must trust an established install's saved mode instead of
// pretending the shared post/pivot shape proves which authoring mode made it.
m_ok(str_contains($guard, "setting_key = 'site_mode'"),
     'mode guard no longer reads the established install mode');
m_ok(str_contains($guard, "in_array(\$saved_mode, ['photoblog', 'carousel', 'smacktalk', 'smackthemup'], true)"),
     'mode guard no longer validates the saved mode before trusting it');
m_ok(str_contains($guard, 'unified post tables cannot safely infer'),
     'mode guard again claims ambiguous single-image rows reveal their authoring mode');

// ── The client half: SYBU must not reach a photoblog endpoint by accident ───
$poster = file_get_contents(__DIR__ . '/../tools/sybu/poster.py');
m_ok(str_contains($poster, 'allow_legacy_scrape: bool = False'),
     'the smack-post-solo.php scrape is no longer opt-in — Connect could convert a gram site');
m_ok(!preg_match('/except Exception:\s*\n\s*pass\s*#\s*Fall through to HTML scrape/', $poster),
     'the silent swallow is back — a failed fetch would look like "no categories"');
m_ok(str_contains($poster, 'Could not load site data'),
     'a failed site-data fetch no longer surfaces its reason');

// ── Which endpoints can trigger a mode change at all ────────────────────────
// Recorded so a new single-mode endpoint is a deliberate choice, not a surprise.
$single_mode = [];
foreach (glob(__DIR__ . '/../*.php') as $f) {
    $s = file_get_contents($f);
    if (preg_match("/SNAP_API_REQUIRE_MODE'\]\s*=\s*'([a-z]+)'/", $s, $m)) {
        $single_mode[basename($f)] = $m[1];
    }
}
m_ok(count($single_mode) <= 1,
     'more endpoints now declare a single required mode (' . implode(', ', array_keys($single_mode))
     . ') — each one can set the mode on an empty site, so confirm that is intended');

// ── Which endpoints a smacktalk (longform) site may be worked on through ───
//
// SS-1019: COLD STORAGE could not sync a longform site because sybu-images.php
// answered 409 on it. Added 30 October 2026 on the owner's decision. These
// checks exist because the two SYBU endpoints are NOT the same case and the
// next reader will assume they are:
//
//   sybu-data.php   read-only metadata. Its own comment justifies smacktalk
//                   on exactly that ground.
//   sybu-images.php has a POST branch that writes title/description/alt/colour
//                   onto an image row. Allowing smacktalk here widened a
//                   WRITE surface, so the remaining control is the scoped
//                   sybu Bearer key, not the mode.
//
// So this is recorded, not generalised. Any further endpoint that adds
// smacktalk fails the last check below and has to say why in the ticket.
$mode_lists = [];
foreach (glob(__DIR__ . '/../*.php') as $f) {
    $s = file_get_contents($f);
    if (preg_match("/SNAP_API_REQUIRE_MODE'\]\s*=\s*\[([^\]]*)\]/", $s, $m)) {
        $mode_lists[basename($f)] = array_map(
            static fn (string $v): string => trim($v, " \t'\""),
            explode(',', $m[1])
        );
    }
}

m_ok(isset($mode_lists['sybu-images.php'])
     && in_array('smacktalk', $mode_lists['sybu-images.php'], true),
     'sybu-images.php no longer accepts smacktalk — COLD STORAGE goes back to 409 on a longform site (SS-1019)');

m_ok(isset($mode_lists['sybu-images.php'])
     && in_array('photoblog', $mode_lists['sybu-images.php'], true)
     && in_array('carousel', $mode_lists['sybu-images.php'], true),
     'sybu-images.php dropped photoblog or carousel while adding smacktalk — that is a narrowing, not the widening that was asked for');

m_ok(isset($mode_lists['sybu-data.php'])
     && in_array('smacktalk', $mode_lists['sybu-data.php'], true),
     'sybu-data.php no longer accepts smacktalk — the read side has to work for the write side to be useful');

// The mode guard itself is what must never be loosened, and it is the thing
// that keeps a site from flipping its own mode under a tool.
$guard_src = file_get_contents(__DIR__ . '/../core/api-auth.php');
m_ok(str_contains($guard_src, 'http_response_code(409)'),
     'api-auth.php no longer answers 409 on a mode mismatch — the fauxlaroid.fyi lockout guard is gone');

$smacktalk_endpoints = [];
foreach ($mode_lists as $name => $modes) {
    if (in_array('smacktalk', $modes, true)) $smacktalk_endpoints[] = $name;
}
sort($smacktalk_endpoints);
m_ok($smacktalk_endpoints === ['sybu-data.php', 'sybu-images.php'],
     'the set of endpoints a longform site can be worked on through changed to ('
     . implode(', ', $smacktalk_endpoints)
     . ') — only those two were decided, so a new one needs its own say-so and its own reason');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: site-mode guard regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
