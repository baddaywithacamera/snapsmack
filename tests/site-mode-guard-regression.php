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

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: site-mode guard regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
