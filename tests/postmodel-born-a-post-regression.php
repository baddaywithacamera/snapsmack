<?php
/**
 * Post model: every path that publishes a photograph on a photoblog makes a post.
 *
 * FLKR FCKR import, SMACKTHEMUP and the hub's posts/create still inserted bare
 * snap_images rows after 0.7.539 plugged the hand-posting path. They now call
 * snap_postmodel_wrap_image() (core/post-model.php), which copies the Maintenance
 * CONVERT PHOTOS TO POSTS action one photo at a time. 2026-09-26.
 *
 * Runs the real function against SQLite (MySQL-only "FOR UPDATE" is stripped by
 * the stand-in connection); needs pdo_sqlite, otherwise only the static part runs.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function pm_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

// ── Static: every publishing path calls the wrapper ─────────────────────────
foreach (['core/flkrfckr-api.php', 'core/smackthemup-api.php', 'core/multisite-api.php'] as $f) {
    pm_test(strpos(file_get_contents("$root/$f"), 'snap_postmodel_wrap_image(') !== false,
        "$f publishes a photo without making its post");
}
$repair = file_get_contents("$root/smack-maintenance.php");
$helper = file_get_contents("$root/core/post-model.php");
foreach (["post_type, status, created_at", "fedi_published_at, is_sensitive, content_warning",
          "'fit', 50, 50, 100", 'UPDATE snap_images SET post_id = ? WHERE id = ? AND post_id IS NULL',
          "!== 'photoblog'"] as $needle) {
    pm_test(strpos($repair, $needle) !== false && strpos($helper, $needle) !== false,
        "wrapper and repair action disagree on: $needle");
}

// ── Live ────────────────────────────────────────────────────────────────────
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP live part: pdo_sqlite not available\n");
} else {
    class PmTestPdo extends PDO {
        #[\ReturnTypeWillChange]
        public function prepare($sql, $options = []) {
            return parent::prepare(str_replace('FOR UPDATE', '', $sql), $options);
        }
    }
    $pdo = new PmTestPdo('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT, setting_val TEXT)");
    $pdo->exec("CREATE TABLE snap_images (id INTEGER PRIMARY KEY, post_id INT, img_slug TEXT, img_description TEXT,
        img_status TEXT, img_date TEXT, allow_comments INT, allow_download INT, download_url TEXT, sort_order INT,
        fedi_published_at TEXT, is_sensitive INT, content_warning TEXT)");
    $pdo->exec("CREATE TABLE snap_posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT UNIQUE, description TEXT,
        post_type TEXT, status TEXT, created_at TEXT, allow_comments INT, allow_download INT, download_url TEXT,
        panorama_rows INT, post_img_size_pct INT, post_border_px INT, post_border_color TEXT, post_bg_color TEXT,
        post_shadow INT, fedi_enabled INT, sort_order INT, fedi_published_at TEXT, is_sensitive INT, content_warning TEXT)");
    $pdo->exec("CREATE TABLE snap_post_images (post_id INT, image_id INT, sort_position INT, is_cover INT,
        img_size_pct INT, img_border_px INT, img_border_color TEXT, img_bg_color TEXT, img_shadow INT,
        img_crop_mode TEXT, img_focus_x INT, img_focus_y INT, img_zoom INT)");
    $add = $pdo->prepare("INSERT INTO snap_images (id, img_slug, img_description, img_status, img_date)
        VALUES (?, ?, 'a caption', 'published', '2026-09-26 10:00:00')");
    $add->execute([1, 'rusty-gate']);
    $add->execute([2, 'rusty-gate-two']);
    $pdo->exec("INSERT INTO snap_posts (id, slug) VALUES (90, 'rusty-gate')");   // slug already taken

    require_once "$root/core/post-model.php";

    $pdo->exec("INSERT INTO snap_settings VALUES ('site_mode', 'gram')");
    pm_test(snap_postmodel_wrap_image($pdo, 1) === 0, 'wrapped a photo on a GRAMOFSMACK site');
    pm_test((int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn() === 1, 'gram site got a post row');

    $pdo->exec("UPDATE snap_settings SET setting_val='photoblog'");
    $pid = snap_postmodel_wrap_image($pdo, 1);
    $post = $pdo->query("SELECT * FROM snap_posts WHERE id = $pid")->fetch();
    pm_test($pid > 0 && $post, 'photoblog photo did not get a post');
    pm_test(($post['slug'] ?? '') === 'rusty-gate-p1', 'taken slug did not fall back to <slug>-p<id>');
    pm_test(($post['post_type'] ?? '') === 'single' && ($post['status'] ?? '') === 'published'
        && ($post['created_at'] ?? '') === '2026-09-26 10:00:00', 'post does not carry type/status/date from the photo');
    pm_test((int)$pdo->query("SELECT post_id FROM snap_images WHERE id = 1")->fetchColumn() === $pid, 'photo not attached to its post');
    pm_test((int)$pdo->query("SELECT COUNT(*) FROM snap_post_images WHERE post_id = $pid AND image_id = 1 AND is_cover = 1")->fetchColumn() === 1,
        'cover pivot missing');
    pm_test(snap_postmodel_wrap_image($pdo, 1) === $pid, 'second call made another post');
    pm_test((int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn() === 2, 'repeat call added rows');
    pm_test((string)$pdo->query("SELECT img_slug FROM snap_images WHERE id = 1")->fetchColumn() === 'rusty-gate',
        'federation identity (img_slug) was changed');

    // Inside a caller's transaction, a rollback takes the post with it.
    $pdo->beginTransaction();
    snap_postmodel_wrap_image($pdo, 2);
    pm_test($pdo->inTransaction(), 'wrapper closed the caller\'s transaction');
    $pdo->rollBack();
    pm_test((int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn() === 2, 'rollback left a post behind');
}

if ($failures) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: photos published by import / SMACKTHEMUP / hub are born posts\n";

// ===== SNAPSMACK EOF =====
