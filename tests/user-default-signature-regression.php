<?php
declare(strict_types=1);

/**
 * Per-user default signature regression test.
 *
 * Verifies the snap_users default signature schema, defensive ALTER helper,
 * session loading in core/auth-smack.php, per-user picker control, and
 * self-scoped smack-profile.php access.
 */

$root = dirname(__DIR__);
require_once $root . '/core/signature-contract.php';

$pass = 0;
$fail = 0;
function ox(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok    {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}\n";
    }
}

echo "Per-user default signature regression\n";

// 1. snap_user_signature_default_for()
ox(snap_user_signature_default_for([], 'light') === 0, 'unset light default returns 0');
ox(snap_user_signature_default_for([], 'dark') === 0, 'unset dark default returns 0');
ox(snap_user_signature_default_for(['default_signature_light_image_id' => null], 'light') === 0, 'NULL light default returns 0');
ox(snap_user_signature_default_for(['default_signature_light_image_id' => '-5'], 'light') === 0, 'negative light default returns 0');
ox(snap_user_signature_default_for(['default_signature_light_image_id' => 'abc'], 'light') === 0, 'non-numeric light default returns 0');
ox(snap_user_signature_default_for(['default_signature_light_image_id' => '413'], 'light') === 413, 'valid light default returns int id');
ox(snap_user_signature_default_for(['default_signature_dark_image_id' => 2], 'dark') === 2, 'valid dark default returns int id');
ox(snap_user_signature_default_for(['default_signature_light_image_id' => 413], 'sepia') === 0, 'unknown surface returns 0');

// 2. Defensive ALTER helper on an un-migrated snap_users table
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE snap_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    preferred_skin TEXT DEFAULT "default-dark",
    ui_mode TEXT NOT NULL DEFAULT "bigwheel"
)');
$pdo->exec('CREATE TABLE snap_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER DEFAULT NULL,
    img_status TEXT NOT NULL DEFAULT "published",
    img_title TEXT DEFAULT "",
    img_file TEXT DEFAULT "",
    img_date TEXT DEFAULT "2026-01-01"
)');
$pdo->exec("INSERT INTO snap_images (id, post_id, img_status, img_title, img_file) VALUES (2, NULL, 'published', 'Sean Sig', 'uploads/sig.png')");

snap_user_signature_ensure_columns($pdo);
snap_user_signature_ensure_columns($pdo); // idempotent second call

$cols = $pdo->query("PRAGMA table_info('snap_users')")->fetchAll(PDO::FETCH_COLUMN, 1);
ox(in_array('default_signature_light_image_id', $cols, true), 'defensive ALTER adds default_signature_light_image_id');
ox(in_array('default_signature_dark_image_id', $cols, true), 'defensive ALTER adds default_signature_dark_image_id');

// 3. Picker HTML output (empty default, stored choice, missing image warning, no inline styles)
ob_start();
snap_user_signature_default_control($pdo, []);
$htmlEmpty = (string)ob_get_clean();
ox(str_contains($htmlEmpty, 'name="default_signature_light_image_id"'), 'renders light signature picker');
ox(str_contains($htmlEmpty, 'name="default_signature_dark_image_id"'), 'renders dark signature picker');
ox(str_contains($htmlEmpty, '<option value="" selected>&mdash; NONE &mdash;</option>'), 'empty default selects NONE');
ox(!str_contains($htmlEmpty, 'style='), 'no inline style attributes in picker');

ob_start();
snap_user_signature_default_control($pdo, [
    'default_signature_light_image_id' => 2,
    'default_signature_dark_image_id'  => 9999,
]);
$htmlSet = (string)ob_get_clean();
ox(str_contains($htmlSet, '<option value="2" selected>#2 Sean Sig</option>'), 'stored light signature stays selected');
ox(str_contains($htmlSet, '<option value="9999" selected>#9999 &mdash; IMAGE NOT FOUND</option>'), 'missing dark signature warns without dropping id');

// 4. Canonical schema + auth-smack + smack-profile source invariants
$schema = (string)file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
ox((bool)preg_match('/`default_signature_light_image_id`\s+int\s+unsigned\s+DEFAULT\s+NULL/i', $schema),
    'canonical schema declares default_signature_light_image_id DEFAULT NULL');
ox((bool)preg_match('/`default_signature_dark_image_id`\s+int\s+unsigned\s+DEFAULT\s+NULL/i', $schema),
    'canonical schema declares default_signature_dark_image_id DEFAULT NULL');

$auth = (string)file_get_contents($root . '/core/auth-smack.php');
ox(str_contains($auth, "user_default_signature_light_image_id") && str_contains($auth, "user_default_signature_dark_image_id"),
    'auth-smack loads per-user default signature ids into $_SESSION');
ox(!str_contains($auth, "'smack-profile.php'"), 'smack-profile.php is not admin-restricted in auth-smack');

$profile = (string)file_get_contents($root . '/smack-profile.php');
ox(str_contains($profile, 'snap_user_signature_ensure_columns($pdo)'), 'smack-profile runs defensive ALTER');
ox(str_contains($profile, "\$_SESSION['user_id']"), 'smack-profile scopes updates to logged-in user_id');
ox(str_contains($profile, '// ===== SNAPSMACK EOF ====='), 'smack-profile carries EOF marker');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

// ===== SNAPSMACK EOF =====
