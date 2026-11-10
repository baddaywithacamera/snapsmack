<?php
declare(strict_types=1);

/**
 * SS-1070. A scoped-key image metadata edit must be attributable and reversible.
 *
 * The GYSS gyss/batch-update endpoint accepted field-scoped writes under a
 * desktop key and recorded NOTHING — a mistaken or bulk edit could not be
 * attributed to anyone, and the prior values were gone so it could not be put
 * back. The fix adds snap_image_edit_audit (shipped in the canonical schema, so
 * schema-sync creates it) and records, per successful edit: the actor (key id,
 * key type, user), the new values, and the PRIOR values.
 *
 * Functional for the schema parse; static for the handler wiring (the live write
 * path needs a DB + a valid GYSS key).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function ea_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// ── Canonical schema: the audit table exists with the columns that make an edit
//    attributable and reversible ───────────────────────────────────────────────
$schema = (string) file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
$audit_ddl = '';
if (preg_match('/CREATE TABLE IF NOT EXISTS `snap_image_edit_audit`.*?;/s', $schema, $m)) {
    $audit_ddl = $m[0];
}
ea_test($audit_ddl !== '', 'snap_image_edit_audit is declared in the canonical schema');
foreach (['image_id', 'actor_key_id', 'actor_key_type', 'actor_user_id', 'changed', 'prior', 'edited_at'] as $col) {
    ea_test(str_contains($audit_ddl, "`{$col}`"), "snap_image_edit_audit has a {$col} column");
}

// ── schema-sync picks it up, so existing installs get the table (no hot-path DDL) ─
require_once $root . '/core/schema-sync.php';
$tables = snap_parse_canonical_schema();
ea_test(isset($tables['snap_image_edit_audit']),
    'schema-sync parses snap_image_edit_audit (it is created on install/update)');

// ── Handler wiring: the batch-update path records the edit ──────────────────────
$api = (string) file_get_contents($root . '/core/gyss-api.php');
$at = strpos($api, "if (\$resource === 'batch-update'");
$body = '';
if ($at !== false) {
    $i = strpos($api, '{', $at);
    $depth = 0; $end = $i;
    for ($n = strlen($api); $i !== false && $i < $n; $i++) {
        if ($api[$i] === '{') $depth++;
        elseif ($api[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $body = substr($api, $at, $end - $at + 1);
}
ea_test($body !== '', 'the batch-update handler was located');
ea_test(str_contains($body, 'INSERT INTO snap_image_edit_audit'),
    'the handler writes an audit row for the edit');
ea_test(str_contains($body, "\$api_key_row['id']")
    && str_contains($body, "\$api_key_row['key_type']")
    && str_contains($body, "\$api_key_row['user_id']"),
    'the audit records the actor (key id, key type, user)');
ea_test(str_contains($body, '$iea_prior') && str_contains($body, '$iea_changed'),
    'the audit records both prior and changed values');

// Prior hashtags must be captured BEFORE snap_sync_tags rewrites them.
$cap = strpos($body, '$iea_prior_hashtags = gy_hashtags_by_image');
$sync = strpos($body, 'snap_sync_tags($pdo');   // the real call, not the comment that names it
ea_test($cap !== false && ($sync === false || $cap < $sync),
    'prior hashtags are captured before the tag sync overwrites them');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "SS-1070 image-edit audit: FAIL ({$fail}).\n";
    exit(1);
}
echo "SS-1070 image-edit audit: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
