<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Remove the forum God key from a site's settings. (SS-1510)
 *
 * smack-forum.php used to ask the owner to paste FORUM_MOD_KEY into their own
 * site so the panel could create boards, and stored it in plaintext in
 * snap_settings as forum_mod_key. That is the one secret that outranks every
 * administrator on the whole forum, sitting in the settings table of every
 * site that wanted to manage a board. The panel no longer needs it: it acts
 * as the site, with the key the site registered with.
 *
 * Removing the row from the code is not the same as removing it from a live
 * database. This does the second half.
 *
 * IT DOES NOT ROTATE THE KEY. Deleting a copy is not the same as making the
 * secret worthless, and this script cannot reach the forum server. If the
 * value has ever been exposed, rotating FORUM_MOD_KEY in the forum's
 * config.php is the real repair and only Sean or Codex-with-Sean can do it.
 * The script says so when it finds one.
 *
 * Usage, from the site root:
 *     php tools/forum-cli/drop-forum-mod-key.php          # report only
 *     php tools/forum-cli/drop-forum-mod-key.php --delete # actually remove it
 *
 * Reports by default and changes nothing. The owner asks for the deletion.
 */

$root = dirname(__DIR__, 2);
$apply = in_array('--delete', $argv, true);

// db.php is generated at the site root at install time (core/sample.db.php is
// the template) and defines $pdo. It is not in the repository, so this script
// only runs on a real install — which is the only place the row can exist.
$db = $root . '/db.php';
if (!is_file($db)) {
    fwrite(STDERR, "No db.php at {$db}. Run this from a real install's root.\n");
    exit(1);
}
require_once $db;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not provide a PDO connection.\n");
    exit(1);
}

$stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'forum_mod_key' LIMIT 1");
$stmt->execute();
$value = $stmt->fetchColumn();

if ($value === false) {
    echo "forum_mod_key: not present. Nothing to remove.\n";
    exit(0);
}

// The value itself is never printed. Its length and first characters are
// enough to tell one key from another in a log without putting the secret
// into a terminal, a screenshot or a room message.
$shown = substr((string)$value, 0, 4);
printf("forum_mod_key: PRESENT (%d characters, begins \"%s…\").\n", strlen((string)$value), $shown);

if (!$apply) {
    echo "\nReport only. Nothing was changed.\n";
    echo "Run again with --delete to remove it from this site's settings.\n";
    echo "\nTHEN ROTATE IT. Deleting this copy does not make the secret worthless.\n";
    echo "The key is shared, it outranks every forum administrator, and it has\n";
    echo "been sitting in a database table. Rotating FORUM_MOD_KEY in the forum\n";
    echo "server's config.php is the actual repair.\n";
    exit(0);
}

$pdo->prepare("DELETE FROM snap_settings WHERE setting_key = 'forum_mod_key'")->execute();

$check = $pdo->prepare("SELECT COUNT(*) FROM snap_settings WHERE setting_key = 'forum_mod_key'");
$check->execute();
if ((int)$check->fetchColumn() !== 0) {
    fwrite(STDERR, "The row is still present after the delete. Nothing else was done.\n");
    exit(1);
}

echo "Removed. This site no longer stores the forum God key.\n";
echo "\nSTILL TO DO, and it is the part that matters: rotate FORUM_MOD_KEY in\n";
echo "the forum server's config.php. The old value was readable here and may\n";
echo "be readable in backups of this database.\n";

// ===== SNAPSMACK EOF =====
