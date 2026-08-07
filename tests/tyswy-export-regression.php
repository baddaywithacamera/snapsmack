<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * TAKE YOUR SHIT WITH YOU — export boundary regression.
 *
 * The per-type column allowlists in core/tyswy-api.php decide what leaves the
 * building. They are the whole security boundary of this feature, and they fail
 * silently in the worst direction: nobody notices an export that includes too
 * much until it is already on someone's disk. So the boundary is asserted here
 * rather than left to review.
 *
 * Spec section 9 names what must never leave: password hashes, TOTP secrets,
 * API keys and OAuth tokens, signing keys, database/SMTP credentials, sessions,
 * CSRF data, IP-ban material, raw IP addresses and security fingerprints.
 */

define('TYSWY_NO_DISPATCH', true);

// The handler requires core/db.php, which connects. Provide the definitions it
// needs without a database: this test only exercises pure functions and the
// static registry.
if (!class_exists('PDO')) { fwrite(STDERR, "PDO missing\n"); exit(1); }

$src = file_get_contents(__DIR__ . '/../core/tyswy-api.php');

$failures = [];
$checks   = 0;
function t_ok(bool $ok, string $msg): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $msg;
}

// Extract the registry + canonical helper without executing the file's requires.
// (Cheap and dependency-free: the registry is a pure literal function.)
$start = strpos($src, 'function tyswy_types()');
$end   = strpos($src, 'function tyswy_live_cols');
if ($start === false || $end === false) {
    fwrite(STDERR, "FAIL\n- could not locate tyswy_types() in the handler\n");
    exit(1);
}
eval(substr($src, $start, $end - $start));

$canon_start = strpos($src, 'function tyswy_canonical');
$canon_end   = strpos($src, 'function tyswy_public_setting_keys');
eval(substr($src, $canon_start, $canon_end - $canon_start));

$keys_start = strpos($src, 'function tyswy_public_setting_keys');
$keys_end   = strpos($src, 'function tyswy_settings_public');
eval(substr($src, $keys_start, $keys_end - $keys_start));

// ── No allowlist may name a secret-shaped column ────────────────────────────
// One line, no /x — a line break inside an alternation list silently creates an
// empty alternative ("private||signing") which matches every string, so the test
// passes nothing and fails everything. Keep it flat and readable instead.
$forbidden = '/(password|passwd|totp|secret|token|api_key|apikey|private|signing|csrf|session|nonce|salt|kek|recovery_code|fp_hash|ip_hash|guest_hash)/i';
// Self-check FIRST: a guard regex that matches nothing (or everything) passes
// silently and protects nothing. Prove it discriminates before trusting it.
foreach (['password_hash', 'totp_secret', 'api_key', 'fp_hash', 'ip_hash', 'guest_hash'] as $bad) {
    t_ok(preg_match($forbidden, $bad) === 1, "guard regex fails to catch '{$bad}' — it is not protecting anything");
}
foreach (['img_title', 'created_at', 'sort_order', 'comment_text', 'peer_url'] as $good) {
    t_ok(preg_match($forbidden, $good) === 0, "guard regex falsely flags '{$good}' — it would match everything");
}

foreach (tyswy_types() as $type => $def) {
    foreach (($def['cols'] ?? []) as $col) {
        t_ok(!preg_match($forbidden, $col),
             "record type '{$type}' exports a secret-shaped column: {$col}");
    }
    t_ok(!empty($def['table']) && !empty($def['id']),
         "record type '{$type}' is missing table/id");
}

// ── Named exclusions, asserted individually so a regression is legible ──────
$types = tyswy_types();

t_ok(!in_array('ip', $types['comments']['cols'], true),
     'comments export raw commenter IP addresses');
t_ok(!in_array('fp_hash', $types['comments']['cols'], true),
     'comments export browser fingerprints');
t_ok(in_array('status', $types['comments']['cols'], true),
     'comments lost their moderation state (spec section 9 requires it)');
t_ok(!in_array('guest_hash', $types['reactions']['cols'], true),
     'reactions export a third-party fingerprint');

// Carousel order is sacred (spec section 8, GRAMOFSMACK).
foreach (['sort_position', 'is_cover', 'grid_col', 'grid_row'] as $c) {
    t_ok(in_array($c, $types['post_images']['cols'], true),
         "post_images lost {$c} — carousel arrangement would not survive the export");
}

// EXIF is preserved deliberately (SECAUDIT 040 section 6).
t_ok(in_array('img_exif', $types['images']['cols'], true),
     'images no longer export EXIF — that is a settled preservation decision');

// ── snap_settings must be an explicit allowlist, never a pattern ────────────
$deny = '/(key|secret|token|password|hash|salt|kek|private|smtp|credential|api|hub|totp)/i';
foreach (tyswy_public_setting_keys() as $k) {
    t_ok(!preg_match($deny, $k), "public settings allowlist contains a secret-shaped key: {$k}");
}
t_ok(str_contains($src, 'setting_key IN ('),
     'settings are no longer fetched by explicit allowlist');
t_ok(!preg_match('/SELECT\s+setting_key\s*,\s*setting_val\s+FROM\s+snap_settings\s*(?:;|")/i', $src),
     'settings appear to be dumped wholesale');

// ── The API must stay read-only ─────────────────────────────────────────────
t_ok(str_contains($src, "tyswy_error(405, 'read_only'"),
     'the GET-only guard is gone — this API must expose no write operation');
foreach (['snap_posts','snap_images','snap_post_images','snap_albums','snap_categories',
          'snap_collections','snap_community_comments','snap_pages'] as $tbl) {
    t_ok(!preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?' . $tbl . '`?/i', $src),
         "the export API writes to a content table ({$tbl}) — it must be read-only");
}

// ── Server-load rules from spec section 2 ───────────────────────────────────
t_ok(str_contains($src, 'MYSQL_ATTR_USE_BUFFERED_QUERY, false'),
     'the stream no longer uses an unbuffered cursor — PHP would hold the chunk in memory');
foreach (['ZipArchive', 'PharData', 'glob(', 'scandir(', 'RecursiveDirectoryIterator'] as $banned) {
    t_ok(!str_contains($src, $banned),
         "the export API uses {$banned} — it must never build an archive or walk the media tree");
}
t_ok(str_contains($src, 'TYSWY_CHUNK_MAX'), 'chunk bound is missing');

// ── Media path containment reuses the audited helper ────────────────────────
t_ok(str_contains($src, 'snap_api_safe_upload_path($rel)'),
     'media serving no longer contains the stored path (SECAUDIT 040 finding C)');
t_ok(str_contains($src, "tyswy_error(403, 'https_required'"),
     'HTTPS enforcement is gone');
t_ok(str_contains($src, "key_type = 'tyswy'"),
     'the key scope is no longer pinned to tyswy');

// ── Canonical serialization must be order-stable ────────────────────────────
$a = tyswy_canonical(['b' => 2, 'a' => 1, 'c' => 3]);
$b = tyswy_canonical(['c' => 3, 'a' => 1, 'b' => 2]);
t_ok($a === $b, 'canonical serialization is not key-order stable — hashes would not reproduce');
t_ok(hash('sha256', $a) === hash('sha256', $b), 'canonical hashes differ for the same record');
t_ok(str_contains(tyswy_canonical(['u' => 'caf\u{00e9}']), 'caf'),
     'unicode is mangled by canonical serialization');

// ── Route + key type are actually wired ─────────────────────────────────────
$api = file_get_contents(__DIR__ . '/../api.php');
t_ok(str_contains($api, "strpos(\$route, 'tyswy') === 0"), 'api.php does not route tyswy');
$keys = file_get_contents(__DIR__ . '/../smack-api-keys.php');
t_ok(str_contains($keys, "'tyswy'"), 'smack-api-keys.php cannot mint a tyswy key');
t_ok(preg_match("/\\\$backup_key = in_array\\(\\\$key_type, \\['suyb', 'sybu', 'tyswy'\\]/", $keys) === 1,
     'tyswy is not on the 3-month standing-utility expiry tier');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: TYSWY export boundary regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
