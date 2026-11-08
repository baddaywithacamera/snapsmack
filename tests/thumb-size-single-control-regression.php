<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Exactly one thumbnail-size control per skin (Finding 2).
 *
 * Thumbnail size is presentation, so a skin owns it via its own control that
 * emits --ss-thumb-size (NEW HORIZON's archive_thumb_size, SS-1185). The CMS's
 * only job is to HIDE the global Archive Appearance thumb_size control on skins
 * that declare their own, so the owner sees one control, not two -- and the CMS
 * must NOT feed its thumb_size value into any skin variable (that is CSS's job).
 *
 * The decision is manifest-driven (an option whose property is --ss-thumb-size),
 * never a skin-name list. This checks the detection against the REAL manifests
 * and locks the admin wiring in source.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

// The exact predicate the admin page uses: does this skin declare its own
// thumbnail-size control (an option emitting --ss-thumb-size)?
function skin_declares_thumb_size(string $root, string $slug): bool {
    $path = $root . '/skins/' . $slug . '/manifest.json';
    if (!is_file($path)) return false;
    $m = json_decode((string)file_get_contents($path), true);
    if (!is_array($m)) return false;
    foreach ($m['options'] ?? [] as $o) {
        if (is_array($o) && ($o['property'] ?? '') === '--ss-thumb-size') return true;
    }
    return false;
}

// DETECTION against the real manifests.
cx(skin_declares_thumb_size($root, 'new-horizon'),
   'NEW HORIZON declares its own thumbnail-size control -> global control hides there');
cx(!skin_declares_thumb_size($root, 'galleria'),
   'a skin without its own control (galleria) -> global control stays shown');

// Exactly one place owns the decision, and it is driven by the manifest, not a
// hard-coded skin list: at least one skin declares the control, and the admin
// page keys off the property, not a name.
$declaring = array_filter(
    array_map('basename', array_map('dirname', glob($root . '/skins/*/manifest.json') ?: [])),
    static fn(string $slug): bool => skin_declares_thumb_size($root, $slug)
);
cx(in_array('new-horizon', $declaring, true),
   'the manifest-driven set of skins that own thumbnail size includes new-horizon');

// SOURCE: the admin page computes the flag from the property and hides the
// global control when set.
$src = (string)file_get_contents($root . '/smack-appearance-archive.php');
cx((bool)preg_match('/\$skin_has_own_thumb_size[\s\S]{0,200}\$o\[.property.\][\s\S]{0,40}--ss-thumb-size/', $src),
   'the admin page derives $skin_has_own_thumb_size from a manifest option property --ss-thumb-size');
cx((bool)preg_match('/if\s*\(\s*!\$skin_has_own_thumb_size\s*\)[\s\S]{0,400}name="settings\[thumb_size\]"/', $src),
   'the global thumb_size <select> is rendered only when the skin has no control of its own');
cx(!(bool)preg_match('/settings\[thumb_size\][\s\S]{0,120}--(ss-thumb-size|thumb-width)/', $src),
   'the admin page never feeds its thumb_size value into a skin variable');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
