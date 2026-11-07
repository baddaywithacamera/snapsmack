<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * A renamed or reshaped skin control carries its saved value forward. (SS-1185)
 *
 * NEW HORIZON's shared archive controls replaced older keys: browse_cols became
 * archive_columns (a rename), and the archive_frame_style preset select became
 * archive_border_width / _color / _style (a reshape). skin-settings.php resolves
 * values by EXACT key, so without a migration a live site's saved choice would
 * be orphaned and its grid would change on upgrade -- which the release ruling
 * forbids. snapsmack_skin_migrated_value() + the 'migrated_from' / 'migrated_map'
 * manifest fields carry the old value forward at READ time (nothing is written).
 *
 * This runs against the REAL new-horizon manifest, so it also proves the manifest
 * declares the migration the way the mechanism expects.
 */

require_once dirname(__DIR__) . '/core/skin-settings.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}
function resolve(array $scoped): array {
    $s = $scoped;
    snapsmack_apply_skin_settings($s, 'new-horizon');
    return $s;
}

// RENAME: browse_cols -> archive_columns, clamped to the new 2-8 range.
cx((resolve(['new-horizon__browse_cols' => '6'])['archive_columns'] ?? null) === '6',
   'browse_cols 6 carries to archive_columns 6');
cx((resolve(['new-horizon__browse_cols' => '10'])['archive_columns'] ?? null) === '8',
   'a browse_cols value above the new max clamps to 8 (no grid jump out of range)');
cx((resolve(['new-horizon__browse_cols' => '1'])['archive_columns'] ?? null) === '2',
   'a browse_cols value below the new min clamps to 2');

// RESHAPE: archive_frame_style preset -> border width / colour / style.
$mw = resolve(['new-horizon__archive_frame_style' => 'medium_white']);
cx(($mw['archive_border_width'] ?? null) === '3', 'frame medium_white -> border width 3');
cx(($mw['archive_border_color'] ?? null) === '#ffffff', 'frame medium_white -> border colour #ffffff');
cx(($mw['archive_border_style'] ?? null) === 'solid', 'frame medium_white -> border style solid');
$none = resolve(['new-horizon__archive_frame_style' => 'none']);
cx(($none['archive_border_width'] ?? null) === '0', 'frame none -> border width 0');
cx(($none['archive_border_style'] ?? null) === 'none', 'frame none -> border style none');

// No old value -> the new control's own default (an untouched site is unchanged).
$fresh = resolve([]);
cx(($fresh['archive_columns'] ?? null) === '4', 'no saved value -> archive_columns default 4');
cx(($fresh['archive_border_width'] ?? null) === '1', 'no saved value -> border width default 1');
cx(($fresh['archive_border_color'] ?? null) === '#666666', 'no saved value -> border colour default #666666');

// An explicit new value always wins over the migration.
cx((resolve(['new-horizon__archive_columns' => '5', 'new-horizon__browse_cols' => '2'])['archive_columns'] ?? null) === '5',
   'an explicit archive_columns value wins over the migrated browse_cols');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
