<?php
/**
 * SNAPSMACK - System maintenance
 *
 * Performs database optimizations, taxonomy cleanup, and asset synchronization.
 * Clears orphaned mappings and defragments core tables to maintain performance.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

$log = [];

// Action can arrive via POST (form submits) or GET (read-only view links such
// as the .htaccess viewer further down). Read it once here so every handler —
// inside or outside the POST block — sees a defined value. (Was: undefined
// $action warning + dead htaccess-view links on GET.)
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- ACTION HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // REGISTRY SYNC
    // Removes ghost entries in the mapping table for images that have been deleted.
    if ($action === 'sync_cats') {
        $stmt = $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id NOT IN (SELECT id FROM snap_images)");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $log[] = "SUCCESS: Purged $deleted orphaned category mappings.";
    }

    // FIX ALBUM + CATEGORY COVERS
    // Auto-assigns a UNIQUE cover for every album and category that has no manual
    // pick. Rules live in core/cover-assign.php: most faves wins, views break the
    // tie, no two albums (or two categories) share a cover, a manual pick
    // (cover_image_id) overrides all. Writes featured_post_id — the column the
    // public album/category grids actually read. Without this the grids fall back
    // to newest-image-per-album, which happily repeats covers across albums.
    // Idempotent — re-run any time covers look duplicated or wrong.
    if ($action === 'recompute_covers') {
        set_time_limit(120);
        ini_set('memory_limit', '256M');
        require_once __DIR__ . '/core/cover-assign.php';
        try {
            $report = snapsmack_recompute_covers($pdo);
            $tally = function (array $rows, string $mode): int {
                return count(array_filter($rows, fn($r) => ($r['mode'] ?? '') === $mode));
            };
            $a = $report['albums']     ?? [];
            $c = $report['categories'] ?? [];
            $log[] = sprintf(
                "SUCCESS: Covers recomputed. Albums — %d auto, %d manual, %d with no available image. "
                . "Categories — %d auto, %d manual, %d with no available image.",
                $tally($a, 'auto'), $tally($a, 'manual'), $tally($a, 'none'),
                $tally($c, 'auto'), $tally($c, 'manual'), $tally($c, 'none')
            );
        } catch (Throwable $e) {
            $log[] = "ERROR: Cover recompute failed — " . htmlspecialchars($e->getMessage()) . ".";
        }
    }

    // VAX INJECTOR
    // Applies a signed VAX database package sent by SnapSmack support. The package
    // code + one-time token ARE the credential: core/smack-vax.php fetches the
    // payload from snapsmack.ca, verifies the Ed25519 signature + token + expiry +
    // replay guard, and only then runs the SQL. This panel just relays pkg+token to
    // that hardened endpoint (loopback) and surfaces its result — no logic dup, and
    // it works even while SMACKBACK is locked down (smack-vax.php is already tracked).
    if ($action === 'apply_vax') {
        $vax_pkg   = trim($_POST['vax_pkg']   ?? '');
        $vax_token = trim($_POST['vax_token'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $vax_pkg)) {
            $log[] = "ERROR: VAX package code is missing or malformed.";
        } elseif (!preg_match('/^[a-fA-F0-9]{32,128}$/', $vax_token)) {
            $log[] = "ERROR: VAX token is missing or malformed (expect 32–128 hex characters).";
        } else {
            $vax_url = rtrim(BASE_URL, '/') . '/core/smack-vax.php?pkg=' . rawurlencode($vax_pkg);
            $vax_ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content'       => 'token=' . rawurlencode($vax_token),
                    'timeout'       => 25,
                    'ignore_errors' => true,   // capture the body on non-200 too
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $vax_resp = @file_get_contents($vax_url, false, $vax_ctx);
            $vax_status = 0;
            // PHP 8.4+ deprecates the $http_response_header magic variable in
            // favour of http_get_last_response_headers(); prefer it where it
            // exists, fall back to the variable on older runtimes.
            $vax_hdrs = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?? [])
                : ($http_response_header ?? []);
            if (isset($vax_hdrs[0]) && preg_match('#\s(\d{3})\s#', $vax_hdrs[0], $vm)) {
                $vax_status = (int) $vm[1];
            }
            if ($vax_resp === false) {
                $log[] = "ERROR: Could not reach the VAX endpoint (" . htmlspecialchars($vax_url) . "). Check the site URL and that core/smack-vax.php is present.";
            } elseif ($vax_status === 200 || stripos($vax_resp, 'VAX OK') !== false) {
                $log[] = "SUCCESS: " . htmlspecialchars(trim($vax_resp));
            } else {
                $log[] = "VAX REJECTED (HTTP {$vax_status}): " . htmlspecialchars(trim($vax_resp));
            }
        }
    }

    // DB OPTIMIZATION
    // Forces MySQL to defragment and optimize core operational tables.
    if ($action === 'optimize') {
        $pdo->query("OPTIMIZE TABLE snap_images, snap_categories, snap_image_cat_map");
        $log[] = "SUCCESS: Database tables optimized and defragmented.";
    }

    // REBUILD CRAWLER FILES — robots.txt + llms.txt regenerated from the current
    // AI-training policy WITHOUT re-saving Global Configuration. One source of
    // truth: core/site-files.php (same output as the settings save).
    if ($action === 'rebuild_robots') {
        require_once __DIR__ . '/core/site-files.php';
        $s = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $res = snapsmack_write_site_files($s);
        $log[] = $res['robots']
            ? "SUCCESS: robots.txt and llms.txt regenerated from the current AI-training policy."
            : "ERROR: Could not write robots.txt — check write permissions on the site root.";
    }

    // REBUILD SITEMAP — drop the cached pages and warm it with a loopback hit so
    // the next crawler gets a fresh index immediately, not a cold-cache rebuild.
    if ($action === 'rebuild_sitemap') {
        require_once __DIR__ . '/core/site-files.php';
        $cleared = snapsmack_rebuild_sitemap();
        @file_get_contents(rtrim(BASE_URL, '/') . '/sitemap.php', false,
            stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]));
        $log[] = "SUCCESS: Sitemap rebuilt (cleared {$cleared} cached page(s) and regenerated).";
    }

    // FEED ORDER — RANDOMIZE / RESTORE CHRONOLOGICAL (step-up: password + 2FA)
    // Both rewrite every post's sort_order, so they sit behind a re-auth gate to
    // prevent an accidental click. Trigrams stay glued as whole rows either way.
    if ($action === 'randomize_feed' || $action === 'restore_chrono') {
        require_once 'core/reauth.php';
        require_once 'core/trigram.php';
        $ra = reauth_verify($pdo, (string)($_POST['reauth_password'] ?? ''), (string)($_POST['reauth_totp'] ?? ''));
        if (!$ra['ok']) {
            $log[] = "ERROR: " . htmlspecialchars($ra['error']);
        } else {
            try {
                $mode = ($action === 'randomize_feed') ? 'shuffle' : 'chrono';
                $n = feed_relayout($pdo, $mode);
                $log[] = ($mode === 'shuffle')
                    ? "SUCCESS: Feed randomized — {$n} published posts reshuffled, trigrams kept intact."
                    : "SUCCESS: Feed restored to chronological order (newest first) — {$n} published posts, trigrams kept intact.";
            } catch (Throwable $e) {
                $log[] = "ERROR: Feed reorder failed — " . htmlspecialchars($e->getMessage()) . ".";
            }
        }
    }

    // FORCE MOBILE SKIN UPDATE
    // Mobile-only skins (Photogram) are hidden from the gallery, so they can only
    // self-heal via the updater when the registry version is HIGHER than installed.
    // This forces a fresh reinstall from the registry regardless of version —
    // skin_registry_install() removes the existing dir first, so it's a safe
    // in-place overwrite. We clear the 10-minute registry session cache first so
    // we pull the freshly published registry, not a stale copy.
    if ($action === 'force_mobile_skin_update') {
        require_once 'core/skin-registry.php';
        if (function_exists('skin_registry_clear_cache')) {
            skin_registry_clear_cache();
        }
        $remote = skin_registry_fetch(SKIN_REGISTRY_DEFAULT_URL);
        if (!empty($remote['error']) || empty($remote['skins'])) {
            $log[] = "ERROR: Could not fetch the skin registry — " . htmlspecialchars($remote['error'] ?? 'no skins returned') . ".";
        } else {
            $mobile_slugs = (defined('SNAPSMACK_MOBILE_SKIN') && SNAPSMACK_MOBILE_SKIN !== '')
                ? [SNAPSMACK_MOBILE_SKIN]
                : [];
            if (!$mobile_slugs) {
                $log[] = "NOTICE: No mobile skin is configured (SNAPSMACK_MOBILE_SKIN is empty).";
            }
            foreach ($mobile_slugs as $slug) {
                $entry = $remote['skins'][$slug] ?? null;
                if (!$entry || empty($entry['download_url'])) {
                    $log[] = "WARNING: \"" . htmlspecialchars($slug) . "\" is not in the registry (or has no download URL). Republish the registry and retry.";
                    continue;
                }
                $result = skin_registry_install(
                    $slug,
                    $entry['download_url'],
                    $entry['signature'] ?? '',
                    defined('SNAPSMACK_RELEASE_PUBKEY') ? SNAPSMACK_RELEASE_PUBKEY : ''
                );
                if (!empty($result['success'])) {
                    $log[] = "SUCCESS: Force-reinstalled mobile skin \"" . htmlspecialchars($slug) . "\" (v" . htmlspecialchars($entry['version'] ?? '?') . ") from the registry.";
                    // Recompile its option CSS against the fresh manifest — the
                    // mobile skin is never active, so the normal save-time
                    // compiler never runs for it (core/skin-compile-mobile.php).
                    require_once __DIR__ . '/core/skin-compile-mobile.php';
                    try {
                        if (snapsmack_compile_mobile_css($pdo)) {
                            $log[] = "SUCCESS: Recompiled \"" . htmlspecialchars($slug) . "\" option CSS (custom_css_mobile).";
                        }
                    } catch (Throwable $e) {
                        $log[] = "WARNING: Option CSS recompile failed — " . htmlspecialchars($e->getMessage());
                    }
                } else {
                    $log[] = "ERROR: \"" . htmlspecialchars($slug) . "\" — " . htmlspecialchars($result['message'] ?? 'install failed') . ".";
                }
            }
        }
    }

    // ASSET SYNC
    // Regenerates missing thumbnails and deletes physical files not found in the DB.
    // Batched at 25 images per run to avoid flattening shared hosting.
    if ($action === 'sync_assets') {
        set_time_limit(120);
        ini_set('memory_limit', '256M');

        $batch_size = 25;
        $offset = max(0, (int)($_POST['batch_offset'] ?? 0));

        $total_images = (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn();
        $images = $pdo->prepare("SELECT id, img_title, img_file FROM snap_images ORDER BY id LIMIT ? OFFSET ?");
        $images->bindValue(1, $batch_size, PDO::PARAM_INT);
        $images->bindValue(2, $offset, PDO::PARAM_INT);
        $images->execute();
        $batch = $images->fetchAll(PDO::FETCH_ASSOC);

        $registered_paths = [];
        $fixed_square = 0;
        $fixed_aspect = 0;
        $db_backfilled = 0;

        // Prepared statement for backfilling recovery metadata
        $backfill_stmt = $pdo->prepare("
            UPDATE snap_images
            SET img_thumb_square = ?, img_thumb_aspect = ?, img_checksum = ?
            WHERE id = ?
        ");

        // Shared generator + per-image focal crop for missing-thumb rebuilds.
        require_once __DIR__ . '/core/thumb-generator.php';
        $sync_pi_stmt = $pdo->prepare(
            "SELECT img_focus_x, img_focus_y, img_zoom
             FROM snap_post_images WHERE image_id = ?
             ORDER BY post_id DESC LIMIT 1"
        );

        foreach ($batch as $img) {
            $file = $img['img_file'];
            if (!file_exists($file)) continue;

            $registered_paths[] = realpath($file);
            $path_info = pathinfo($file);
            $thumb_dir = $path_info['dirname'] . '/thumbs';

            // Ensure thumbs directory exists
            if (!is_dir($thumb_dir)) {
                mkdir($thumb_dir, 0755, true);
            }

            // Expected thumbnail locations
            $sq_thumb = $thumb_dir . '/t_' . $path_info['basename'];
            $aspect_thumb = $thumb_dir . '/a_' . $path_info['basename'];

            // Register existing thumbs as valid
            if (file_exists($sq_thumb)) $registered_paths[] = realpath($sq_thumb);
            if (file_exists($aspect_thumb)) $registered_paths[] = realpath($aspect_thumb);

            // Rebuild missing thumbnails — focal-aware via the shared generator.
            // (Was an inline centre-crop that ignored the image's saved focal
            // point/zoom, so a repair pass silently reverted curated crops.)
            $need_square = !file_exists($sq_thumb);
            $need_aspect = !file_exists($aspect_thumb);

            if ($need_square || $need_aspect) {
                $sync_pi_stmt->execute([$img['id']]);
                $sync_pi = $sync_pi_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $result = snapsmack_generate_thumbs(
                    $file, __DIR__, 400, 600,
                    max(0,   min(100, (int)($sync_pi['img_focus_x'] ?? 50))),
                    max(0,   min(100, (int)($sync_pi['img_focus_y'] ?? 50))),
                    max(100, min(300, (int)($sync_pi['img_zoom']    ?? 100)))
                );
                if ($result) {
                    if ($need_square && file_exists($sq_thumb)) {
                        $registered_paths[] = realpath($sq_thumb);
                        $fixed_square++;
                    }
                    if ($need_aspect && file_exists($aspect_thumb)) {
                        $registered_paths[] = realpath($aspect_thumb);
                        $fixed_aspect++;
                    }
                }
            }

            // --- BACKFILL RECOVERY METADATA ---
            // Always ensure DB has thumb paths and checksum, even if thumbs already existed.
            $rel_sq  = ltrim(str_replace('\\', '/', $sq_thumb), './');
            $rel_asp = ltrim(str_replace('\\', '/', $aspect_thumb), './');
            $checksum = hash_file('sha256', $file);

            $backfill_stmt->execute([$rel_sq, $rel_asp, $checksum, $img['id']]);
            $db_backfilled++;
        }

        $next_offset = $offset + $batch_size;
        $has_more = $next_offset < $total_images;
        $batch_end = min($next_offset, $total_images);

        // Only prune orphans on the final batch (needs full registered_paths from all images)
        $purged_orphans = 0;
        if (!$has_more && is_dir('img_uploads')) {
            // Build full registered path list for orphan scan
            $all_images = $pdo->query("SELECT img_file FROM snap_images")->fetchAll(PDO::FETCH_COLUMN);
            $all_registered = [];
            foreach ($all_images as $afile) {
                if (!file_exists($afile)) continue;
                $all_registered[] = realpath($afile);
                $api = pathinfo($afile);
                $atd = $api['dirname'] . '/thumbs';
                $asq = $atd . '/t_' . $api['basename'];
                $aas = $atd . '/a_' . $api['basename'];
                if (file_exists($asq)) $all_registered[] = realpath($asq);
                if (file_exists($aas)) $all_registered[] = realpath($aas);
            }

            $upload_dir = new RecursiveDirectoryIterator('img_uploads');
            $iterator = new RecursiveIteratorIterator($upload_dir);
            foreach ($iterator as $file_info) {
                if ($file_info->isFile()) {
                    $f_path = $file_info->getRealPath();
                    $ext = strtolower($file_info->getExtension());
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && !in_array($f_path, $all_registered)) {
                        unlink($f_path);
                        $purged_orphans++;
                    }
                }
            }
        }

        $msg = "BATCH {$offset}–{$batch_end} of {$total_images}: Generated {$fixed_square} square + {$fixed_aspect} aspect thumbs. Backfilled {$db_backfilled} DB recovery records.";
        if (!$has_more) {
            $msg .= " Purged {$purged_orphans} orphan files. <strong>ALL DONE.</strong>";
        } else {
            $msg .= " <strong>" . ($total_images - $batch_end) . " images remaining.</strong>";
        }
        $log[] = "SUCCESS: " . $msg;

        // Flag for the UI to show the continue button
        $asset_sync_has_more = $has_more;
        $asset_sync_next_offset = $next_offset;
    }


    // REGENERATE ALL THUMBNAILS
    // Force-regenerates square + aspect thumbs AND the fediverse bake (p_) for
    // every image in the DB using core/thumb-generator.php. Unlike sync_assets,
    // this overwrites existing thumbs. Focal-aware: each image's saved crop
    // (img_focus_x/y, img_zoom) and frame styling from snap_post_images drive
    // the square crop and the bake — same render the carousel editor's Save
    // produces, so a bulk run here is the "regen everything" button.
    if ($action === 'regen_thumbs') {
        set_time_limit(120);
        ini_set('memory_limit', '256M');
        require_once __DIR__ . '/core/thumb-generator.php';

        $batch_size = 25;
        $offset     = max(0, (int)($_POST['batch_offset'] ?? 0));
        $total      = (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn();
        $batch_end  = min($offset + $batch_size, $total);
        $has_more   = $batch_end < $total;
        $next_offset = $batch_end;

        $images = $pdo->prepare("SELECT id, img_file FROM snap_images ORDER BY id LIMIT ? OFFSET ?");
        $images->execute([$batch_size, $offset]);
        $rows = $images->fetchAll(PDO::FETCH_ASSOC);

        // Per-image crop + frame styling from the post that carries the image.
        // Images not attached to any post regen with defaults (centre crop,
        // unframed bake). If an image somehow sits in multiple posts, the most
        // recent post's styling wins.
        $pi_stmt = $pdo->prepare(
            "SELECT img_focus_x, img_focus_y, img_zoom, img_size_pct,
                    img_border_px, img_border_color, img_bg_color
             FROM snap_post_images WHERE image_id = ?
             ORDER BY post_id DESC LIMIT 1"
        );

        $upd = $pdo->prepare("UPDATE snap_images SET img_thumb_square=?, img_thumb_aspect=? WHERE id=?");
        $done  = 0;
        $baked = 0;
        $fail  = 0;
        foreach ($rows as $row) {
            $pi_stmt->execute([$row['id']]);
            $pi = $pi_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $fx = max(0,   min(100, (int)($pi['img_focus_x'] ?? 50)));
            $fy = max(0,   min(100, (int)($pi['img_focus_y'] ?? 50)));
            $zm = max(100, min(300, (int)($pi['img_zoom']    ?? 100)));

            // 400px square matches the live t_ inventory (Grid tiles render at
            // 305px); 600px aspect is the generator default.
            $result = snapsmack_generate_thumbs($row['img_file'], __DIR__, 400, 600, $fx, $fy, $zm);
            if (!$result) {
                $fail++;
                continue;
            }
            $upd->execute([$result['sq_path'], $result['asp_path'], $row['id']]);
            $done++;

            // Fediverse bake (p_) — curated 1080² square that federates.
            if (function_exists('snapsmack_generate_fedi_bake')) {
                $bake = snapsmack_generate_fedi_bake($row['img_file'], __DIR__, [
                    'size_pct'     => (int)($pi['img_size_pct']        ?? 100),
                    'border_px'    => (int)($pi['img_border_px']       ?? 0),
                    'border_color' => (string)($pi['img_border_color'] ?? '#000000'),
                    'bg_color'     => (string)($pi['img_bg_color']     ?? '#ffffff'),
                ], $fx, $fy, $zm);
                if ($bake) $baked++;
            }
        }

        $msg = "BATCH {$offset}–{$batch_end} of {$total}: Regenerated {$done} thumb sets + {$baked} fediverse bakes.";
        if ($fail)    $msg .= " {$fail} skipped (missing/unreadable source).";
        if (!$has_more) $msg .= " <strong>ALL DONE.</strong>";
        else            $msg .= " <strong>" . ($total - $batch_end) . " images remaining.</strong>";
        $log[] = "SUCCESS: " . $msg;

        $regen_thumbs_has_more    = $has_more;
        $regen_thumbs_next_offset = $next_offset;
    }

    // SCHEMA HEALTH CHECK
    // Diffs the live DB against the canonical target schema defined here.
    // Generates ALTER TABLE / CREATE TABLE SQL for anything missing and optionally runs it.
    if ($action === 'schema_check' || $action === 'schema_fix') {

        // ── Target schema ────────────────────────────────────────────────────
        // Column name → ADD COLUMN definition (used for ALTER TABLE).
        // Only missing columns/tables are reported — extra columns are ignored.
        $target = [

            'snap_images' => [
                'id'                  => "int NOT NULL AUTO_INCREMENT",
                'img_title'           => "varchar(255) NOT NULL",
                'img_slug'            => "varchar(255) NOT NULL",
                'img_description'     => "text",
                'img_film'            => "varchar(100) DEFAULT NULL",
                'img_date'            => "datetime NOT NULL",
                'img_file'            => "varchar(255) NOT NULL",
                'img_exif'            => "text",
                'img_download_url'    => "varchar(500) DEFAULT NULL",
                'img_download_count'  => "int unsigned NOT NULL DEFAULT '0'",
                'img_width'           => "int DEFAULT '0'",
                'img_height'          => "int DEFAULT '0'",
                'img_status'          => "enum('published','draft') DEFAULT 'published'",
                'img_orientation'     => "int DEFAULT '0'",
                'allow_comments'      => "tinyint(1) DEFAULT '1'",
                'allow_download'      => "tinyint(1) NOT NULL DEFAULT '1'",
                'download_url'        => "varchar(512) NOT NULL DEFAULT ''",
                'img_thumb_square'    => "varchar(255) DEFAULT NULL",
                'img_thumb_aspect'    => "varchar(255) DEFAULT NULL",
                'img_checksum'        => "varchar(64) DEFAULT NULL",
                'img_display_options' => "text DEFAULT NULL",
                'post_id'             => "int DEFAULT NULL",
                'sort_order'          => "int NOT NULL DEFAULT '0'",
            ],

            'snap_categories' => [
                'id'             => "int NOT NULL AUTO_INCREMENT",
                'cat_name'       => "varchar(100) NOT NULL",
                'cat_slug'       => "varchar(100) NOT NULL",
                'cat_description'=> "text",
                'cover_image_id' => "int DEFAULT NULL",
            ],

            'snap_albums' => [
                'id'               => "int NOT NULL AUTO_INCREMENT",
                'album_name'       => "varchar(255) NOT NULL",
                'album_description'=> "text",
                'cover_image_id'   => "int DEFAULT NULL",
            ],

            'snap_image_cat_map'   => ['image_id' => "int NOT NULL", 'cat_id'   => "int NOT NULL"],
            'snap_image_album_map' => ['image_id' => "int NOT NULL", 'album_id' => "int NOT NULL"],

            'snap_pages' => [
                'id'          => "int NOT NULL AUTO_INCREMENT",
                'slug'        => "varchar(100) NOT NULL",
                'title'       => "varchar(255) NOT NULL",
                'content'     => "longtext",
                'image_asset' => "varchar(255) DEFAULT ''",
                'is_active'   => "tinyint(1) DEFAULT '1'",
                'menu_order'  => "int DEFAULT '0'",
                'created_at'  => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_settings' => [
                'setting_key' => "varchar(100) NOT NULL",
                'setting_val' => "text",
            ],

            'snap_comments' => [
                'id'             => "int NOT NULL AUTO_INCREMENT",
                'img_id'         => "int NOT NULL",
                'comment_author' => "varchar(100) DEFAULT NULL",
                'comment_email'  => "varchar(150) DEFAULT NULL",
                'comment_text'   => "text",
                'comment_date'   => "datetime DEFAULT CURRENT_TIMESTAMP",
                'comment_ip'     => "varchar(45) DEFAULT NULL",
                'is_approved'    => "tinyint(1) DEFAULT '0'",
            ],

            'snap_users' => [
                'id'             => "int NOT NULL AUTO_INCREMENT",
                'username'       => "varchar(50) NOT NULL",
                'password_hash'  => "varchar(255) NOT NULL",
                'user_role'      => "varchar(20) NOT NULL DEFAULT 'editor'",
                'email'          => "varchar(100) DEFAULT NULL",
                'preferred_skin' => "varchar(100) DEFAULT 'default-dark'",
            ],

            'snap_blogroll' => [
                'id'               => "int NOT NULL AUTO_INCREMENT",
                'peer_name'        => "varchar(255) NOT NULL",
                'peer_url'         => "varchar(255) NOT NULL",
                'cat_id'           => "int DEFAULT NULL",
                'peer_rss'         => "varchar(255) DEFAULT NULL",
                'peer_desc'        => "text",
                'sort_order'       => "int NOT NULL DEFAULT '0'",
                'rss_last_fetched' => "datetime DEFAULT NULL",
                'rss_last_updated' => "datetime DEFAULT NULL",
            ],

            'snap_assets' => [
                'id'             => "int NOT NULL AUTO_INCREMENT",
                'asset_name'     => "varchar(255) NOT NULL",
                'asset_path'     => "varchar(500) NOT NULL",
                'asset_checksum' => "varchar(64) DEFAULT NULL",
                'created_at'     => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_migrations' => [
                'id'         => "int unsigned NOT NULL AUTO_INCREMENT",
                'migration'  => "varchar(200) NOT NULL",
                'applied_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_rate_limits' => [
                'id'           => "int unsigned NOT NULL AUTO_INCREMENT",
                'ip'           => "varchar(45) NOT NULL",
                'action'       => "varchar(50) NOT NULL",
                'count'        => "int unsigned NOT NULL DEFAULT 1",
                'window_start' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_likes' => [
                'id'         => "int unsigned NOT NULL AUTO_INCREMENT",
                'post_id'    => "int unsigned NOT NULL",
                'user_id'    => "int unsigned NOT NULL",
                'created_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_posts' => [
                'id'                 => "int NOT NULL AUTO_INCREMENT",
                'title'              => "varchar(500) NOT NULL",
                'slug'               => "varchar(600) NOT NULL",
                'description'        => "text DEFAULT NULL",
                'post_type'          => "enum('single','carousel','panorama') NOT NULL DEFAULT 'single'",
                'status'             => "varchar(20) NOT NULL DEFAULT 'published'",
                'created_at'         => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                'updated_at'         => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                'allow_comments'     => "tinyint(1) NOT NULL DEFAULT 1",
                'allow_download'     => "tinyint(1) NOT NULL DEFAULT 0",
                'download_url'       => "varchar(500) DEFAULT NULL",
                'download_count'     => "int NOT NULL DEFAULT 0",
                'panorama_rows'      => "tinyint NOT NULL DEFAULT 1",
                'import_source'      => "varchar(50) DEFAULT NULL",
                'import_id'          => "varchar(200) DEFAULT NULL",
                'post_img_size_pct'  => "tinyint unsigned NOT NULL DEFAULT 100",
                'post_border_px'     => "tinyint unsigned NOT NULL DEFAULT 0",
                'post_border_color'  => "char(7) NOT NULL DEFAULT '#000000'",
                'post_bg_color'      => "char(7) NOT NULL DEFAULT '#ffffff'",
                'post_shadow'        => "tinyint unsigned NOT NULL DEFAULT 0",
            ],

            'snap_post_images' => [
                'id'               => "int NOT NULL AUTO_INCREMENT",
                'post_id'          => "int NOT NULL",
                'image_id'         => "int NOT NULL",
                'sort_position'    => "smallint NOT NULL DEFAULT 0",
                'is_cover'         => "tinyint(1) NOT NULL DEFAULT 0",
                'grid_col'         => "tinyint DEFAULT NULL",
                'grid_row'         => "tinyint DEFAULT NULL",
                'img_size_pct'     => "tinyint unsigned NOT NULL DEFAULT 100",
                'img_border_px'    => "tinyint unsigned NOT NULL DEFAULT 0",
                'img_border_color' => "char(7) NOT NULL DEFAULT '#000000'",
                'img_bg_color'     => "char(7) NOT NULL DEFAULT '#ffffff'",
                'img_shadow'       => "tinyint unsigned NOT NULL DEFAULT 0",
            ],

            'snap_post_cat_map'   => ['post_id' => "int NOT NULL", 'cat_id'  => "int NOT NULL"],
            'snap_post_album_map' => ['post_id' => "int NOT NULL", 'album_id'=> "int NOT NULL"],

            'snap_tags' => [
                'id'         => "int unsigned AUTO_INCREMENT",
                'tag'        => "varchar(100) NOT NULL",
                'slug'       => "varchar(100) NOT NULL",
                'use_count'  => "int unsigned DEFAULT 0",
                'created_at' => "timestamp DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_image_tags' => [
                'id'         => "int unsigned AUTO_INCREMENT",
                'image_id'   => "int unsigned NOT NULL",
                'tag_id'     => "int unsigned NOT NULL",
                'created_at' => "timestamp DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_community_users' => [
                'id'             => "int unsigned NOT NULL AUTO_INCREMENT",
                'username'       => "varchar(50) NOT NULL",
                'display_name'   => "varchar(100) DEFAULT NULL",
                'email'          => "varchar(150) NOT NULL",
                'password_hash'  => "varchar(255) NOT NULL",
                'avatar_url'     => "varchar(500) DEFAULT NULL",
                'bio'            => "text DEFAULT NULL",
                'status'         => "enum('active','unverified','suspended') NOT NULL DEFAULT 'unverified'",
                'email_verified' => "tinyint(1) NOT NULL DEFAULT 0",
                'last_seen_at'   => "datetime DEFAULT NULL",
                'created_at'     => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_community_sessions' => [
                'id'         => "int unsigned NOT NULL AUTO_INCREMENT",
                'user_id'    => "int unsigned NOT NULL",
                'token'      => "varchar(64) NOT NULL",
                'expires_at' => "datetime NOT NULL",
                'ip'         => "varchar(45) DEFAULT NULL",
                'user_agent' => "varchar(500) DEFAULT NULL",
                'created_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_community_tokens' => [
                'id'         => "int unsigned NOT NULL AUTO_INCREMENT",
                'user_id'    => "int unsigned NOT NULL",
                'token'      => "varchar(64) NOT NULL",
                'type'       => "varchar(30) NOT NULL",
                'expires_at' => "datetime NOT NULL",
                'created_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_community_comments' => [
                'id'           => "int unsigned NOT NULL AUTO_INCREMENT",
                'post_id'      => "int unsigned NOT NULL",
                'user_id'      => "int unsigned NULL DEFAULT NULL",
                'guest_name'   => "varchar(100) NULL DEFAULT NULL",
                'guest_email'  => "varchar(200) NULL DEFAULT NULL",
                'comment_text' => "text NOT NULL",
                'status'       => "enum('visible','hidden','deleted') NOT NULL DEFAULT 'visible'",
                'ip'           => "varchar(45) NULL DEFAULT NULL",
                'created_at'   => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],

            'snap_reactions' => [
                'id'            => "int unsigned NOT NULL AUTO_INCREMENT",
                'post_id'       => "int unsigned NOT NULL",
                'user_id'       => "int unsigned NOT NULL",
                'reaction_code' => "varchar(20) NOT NULL",
                'created_at'    => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
            ],
        ];

        // ── Query live DB ─────────────────────────────────────────────────────
        $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();

        $live_tables = $pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'snap_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $live_tables = array_flip($live_tables);

        $live_cols_raw = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'snap_%'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $live_cols = [];
        foreach ($live_cols_raw as $r) {
            $live_cols[$r['TABLE_NAME']][$r['COLUMN_NAME']] = true;
        }

        // ── Build diff ────────────────────────────────────────────────────────
        $missing_tables  = [];   // table => true
        $missing_columns = [];   // [table, column, def]

        foreach ($target as $table => $columns) {
            if (!isset($live_tables[$table])) {
                $missing_tables[$table] = true;
            } else {
                foreach ($columns as $col => $def) {
                    if (!isset($live_cols[$table][$col])) {
                        $missing_columns[] = ['table' => $table, 'col' => $col, 'def' => $def];
                    }
                }
            }
        }

        $total_issues = count($missing_tables) + count($missing_columns);

        if ($action === 'schema_check') {
            if ($total_issues === 0) {
                $log[] = "SUCCESS: Schema is healthy — all " . count($target) . " tables and their expected columns are present.";
            } else {
                $schema_issues = ['tables' => $missing_tables, 'columns' => $missing_columns];
            }
        }

        if ($action === 'schema_fix') {
            if ($total_issues === 0) {
                $log[] = "SUCCESS: Nothing to fix — schema is already healthy.";
            } else {
                $fix_ok  = [];
                $fix_err = [];

                // Create missing tables using CREATE TABLE IF NOT EXISTS.
                // Build a minimal but valid CREATE TABLE from the column definitions.
                foreach ($missing_tables as $table => $_) {
                    $cols_sql = [];
                    foreach ($target[$table] as $col => $def) {
                        $cols_sql[] = "`$col` $def";
                    }
                    // Add a PRIMARY KEY on `id` if present and auto_increment
                    $has_pk = isset($target[$table]['id']) &&
                              stripos($target[$table]['id'], 'AUTO_INCREMENT') !== false;
                    $pk_clause = $has_pk ? ",\n    PRIMARY KEY (`id`)" : '';
                    $sql = "CREATE TABLE IF NOT EXISTS `$table` (\n    "
                         . implode(",\n    ", $cols_sql)
                         . "$pk_clause\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                    try {
                        $pdo->exec($sql);
                        $fix_ok[] = "Created table <code>$table</code>";
                    } catch (Exception $e) {
                        $fix_err[] = "Failed to create <code>$table</code>: " . $e->getMessage();
                    }
                }

                // Add missing columns.
                foreach ($missing_columns as $item) {
                    $sql = "ALTER TABLE `{$item['table']}` ADD COLUMN `{$item['col']}` {$item['def']}";
                    try {
                        $pdo->exec($sql);
                        $fix_ok[] = "Added <code>{$item['table']}.{$item['col']}</code>";
                    } catch (Exception $e) {
                        // Column may have just appeared (race) — check if it's a duplicate error.
                        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                            $fix_ok[] = "Skipped <code>{$item['table']}.{$item['col']}</code> (already exists)";
                        } else {
                            $fix_err[] = "Failed <code>{$item['table']}.{$item['col']}</code>: " . $e->getMessage();
                        }
                    }
                }

                // Seed sort_order on snap_images if it was just added and all rows are 0.
                $all_zero = (int)$pdo->query(
                    "SELECT COUNT(*) FROM snap_images WHERE sort_order != 0"
                )->fetchColumn() === 0;
                $has_rows = (int)$pdo->query(
                    "SELECT COUNT(*) FROM snap_images"
                )->fetchColumn() > 0;
                if ($all_zero && $has_rows) {
                    $pdo->exec("SET @r := 0");
                    $pdo->exec("UPDATE snap_images SET sort_order = (@r := @r + 1) ORDER BY img_date DESC");
                    $fix_ok[] = "Seeded <code>snap_images.sort_order</code> from existing date order";
                }

                if (!empty($fix_ok)) {
                    $log[] = "SUCCESS: Applied " . count($fix_ok) . " fix(es):<br>&nbsp;&nbsp;• " . implode("<br>&nbsp;&nbsp;• ", $fix_ok);
                }
                if (!empty($fix_err)) {
                    $log[] = "WARNING: " . count($fix_err) . " fix(es) failed:<br>&nbsp;&nbsp;• " . implode("<br>&nbsp;&nbsp;• ", $fix_err);
                }
            }
        }
    }

    // HTACCESS REPAIR
    // Checks root .htaccess and img_uploads/.htaccess, reports issues, repairs on demand.
    if ($action === 'htaccess_check' || $action === 'htaccess_repair') {
        $htaccess_path   = __DIR__ . '/.htaccess';
        $uploads_htaccess = __DIR__ . '/img_uploads/.htaccess';
        $htaccess_marker = '# SNAPSMACK-HTACCESS-RULES';
        $issues = [];

        // --- Diagnose root .htaccess ---
        if (!file_exists($htaccess_path)) {
            $issues[] = "Root .htaccess is <strong>missing entirely</strong>.";
        } else {
            $content = file_get_contents($htaccess_path);

            if (strpos($content, $htaccess_marker) === false) {
                $issues[] = "SnapSmack rules block is <strong>missing</strong> (marker not found).";
            } else {
                // Check individual critical sections
                $checks = [
                    'HTTPS redirect'         => 'RewriteCond %{HTTPS} !=on',
                    'Proxy-aware HTTPS'      => 'X-Forwarded-Proto',
                    'Clean URL router'       => 'RewriteRule ^([a-zA-Z0-9_-]+)$ index.php',
                    'snap-in named route'    => 'RewriteRule ^snap-in$',
                    'SMACKVERSE webfinger'   => 'well-known/webfinger',
                    'SMACKVERSE AP routes'   => 'smackverse.php?appath=',
                    'Security headers'       => 'X-Frame-Options',
                    'Sensitive files'        => 'FilesMatch "(^\\.ht',
                    'Core PHP blocking'      => 'FilesMatch "^(db|auth|constants',
                    'Directory listings'     => 'Options -Indexes',
                    'Probe Guard (auto-ban)' => 'probe-ban.php',
                    'Asset caching'          => 'mod_expires.c',
                    'GZIP compression'       => 'mod_deflate.c',
                    'Custom error pages'     => 'ErrorDocument 404',
                ];
                foreach ($checks as $label => $needle) {
                    if (strpos($content, $needle) === false) {
                        $issues[] = "<strong>{$label}</strong> rule is missing or damaged.";
                    }
                }
            }
        }

        // --- Diagnose img_uploads/.htaccess ---
        if (!is_dir(__DIR__ . '/img_uploads')) {
            // Not an issue — directory may not exist yet
        } elseif (!file_exists($uploads_htaccess)) {
            $issues[] = "Upload directory PHP execution block is <strong>missing</strong> (img_uploads/.htaccess).";
        } else {
            $upl_content = file_get_contents($uploads_htaccess);
            if (strpos($upl_content, 'Deny from all') === false) {
                $issues[] = "Upload directory .htaccess exists but <strong>PHP blocking rule is missing</strong>.";
            }
        }

        // --- Report or Repair ---
        if ($action === 'htaccess_check') {
            if (empty($issues)) {
                $log[] = "SUCCESS: All .htaccess rules verified — no issues detected.";
            } else {
                $log[] = "WARNING: Found " . count($issues) . " issue(s):<br>&nbsp;&nbsp;• " . implode("<br>&nbsp;&nbsp;• ", $issues)
                       . "<br>Use <strong>REPAIR</strong> to fix.";
            }
        }

        if ($action === 'htaccess_repair') {
            $repaired = [];

            // Rebuild root .htaccess SnapSmack block
            $existing = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';

            // Strip old SnapSmack block if present (everything from marker to end-of-block)
            if (strpos($existing, $htaccess_marker) !== false) {
                // Pass 1: strip from marker to end of file (no separator dependency).
                $existing = preg_replace(
                    '/' . preg_quote($htaccess_marker, '/') . '.*$/s',
                    '',
                    $existing
                );
                // Pass 2: strip any trailing separator lines and blank lines left behind.
                $lines = explode("\n", rtrim($existing));
                while (!empty($lines) && preg_match('/^(# ─+)?$/', rtrim(end($lines)))) {
                    array_pop($lines);
                }
                $existing = implode("\n", $lines) . "\n";
            }

            // Read canonical SnapSmack rules from core/htaccess-template.
            // Single source of truth in git — edit the template, not this file.
            $template_path = __DIR__ . '/core/htaccess-template';
            if (!file_exists($template_path)) {
                $log[] = 'ERROR: core/htaccess-template missing — cannot repair. '
                       . 'Pull a fresh release package or restore from git.';
                return;
            }
            $snapsmack_rules = "\n" . rtrim(file_get_contents($template_path)) . "\n";

            @file_put_contents($htaccess_path, $existing . $snapsmack_rules, LOCK_EX);
            $repaired[] = "Root .htaccess SnapSmack rules regenerated";

            // Rebuild img_uploads/.htaccess
            if (is_dir(__DIR__ . '/img_uploads')) {
                $upload_block = "<FilesMatch \"\\.php$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n";
                @file_put_contents($uploads_htaccess, $upload_block, LOCK_EX);
                $repaired[] = "Upload directory PHP execution block restored";
            }

            $log[] = "SUCCESS: " . implode(". ", $repaired) . ".";
        }
    }
}


    // HTACCESS VIEW — read-only display of .htaccess or template
    if ($action === 'htaccess_view_live' || $action === 'htaccess_view_template') {
        if ($action === 'htaccess_view_live') {
            $htaccess_view_path  = __DIR__ . '/.htaccess';
            $htaccess_view_label = 'Live .htaccess on this server';
        } else {
            $htaccess_view_path  = __DIR__ . '/core/htaccess-template';
            $htaccess_view_label = 'core/htaccess-template (canonical rules in git)';
        }
        if (file_exists($htaccess_view_path)) {
            $htaccess_view_content = file_get_contents($htaccess_view_path);
        } else {
            $log[] = "ERROR: " . htmlspecialchars($htaccess_view_path) . " not found.";
            $htaccess_view_content = null;
        }
    }

$htaccess_view_content = $htaccess_view_content ?? '';
$htaccess_view_label = $htaccess_view_label ?? '';
$page_title = "System Maintenance";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM MAINTENANCE</h2>

    <?php foreach($log as $entry): ?>
        <div class="alert alert-success">> <?php echo $entry; ?></div>
    <?php endforeach; ?>

    <div class="dash-grid">
        <div class="box box-flex">
            <h3>CLEAN ORPHAN DATA</h3>
            <p class="skin-desc-text">Purges leftover category and album mappings for images that no longer exist. Fixes count mismatches without touching any files.</p>
            <form method="POST">
                <input type="hidden" name="action" value="sync_cats">
                <button type="submit" class="btn-smack btn-block">CLEAN DATABASE</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>FIX ALBUM &amp; CATEGORY COVERS</h3>
            <p class="skin-desc-text">Auto-assigns a unique cover to every album and category that doesn't have a manually chosen one. Most-liked image wins, views break the tie, and no two albums (or two categories) share the same cover. Manual cover picks are always kept. Run this whenever album or category covers look duplicated or wrong.</p>
            <form method="POST">
                <input type="hidden" name="action" value="recompute_covers">
                <button type="submit" class="btn-smack btn-block">FIX COVERS</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>OPTIMIZE TABLES</h3>
            <p class="skin-desc-text">Defragments MySQL tables and rebuilds indexes. Speeds up queries across the dashboard and public site.</p>
            <form method="POST">
                <input type="hidden" name="action" value="optimize">
                <button type="submit" class="btn-smack btn-block">OPTIMIZE</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>REBUILD ROBOTS.TXT</h3>
            <p class="skin-desc-text">Regenerates robots.txt and llms.txt from your current AI-training policy &mdash; no need to re-save Global Configuration. Use it to refresh a stale file, e.g. after changing the policy or clearing a CDN cache.</p>
            <form method="POST">
                <input type="hidden" name="action" value="rebuild_robots">
                <button type="submit" class="btn-smack btn-block">REBUILD ROBOTS.TXT</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>REBUILD SITEMAP.XML</h3>
            <p class="skin-desc-text">Clears the cached sitemap and regenerates it now, so crawlers get an up-to-date index of your posts and images immediately instead of waiting for the hourly refresh.</p>
            <form method="POST">
                <input type="hidden" name="action" value="rebuild_sitemap">
                <button type="submit" class="btn-smack btn-block">REBUILD SITEMAP.XML</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>&#127922; RANDOMIZE FEED</h3>
            <p class="skin-desc-text">Shuffles the running order of your published feed. Trigrams stay glued as a set &mdash; only each set's position moves, never its L/M/R order. This rewrites every post's position, so it's guarded: enter your password and 2FA code to fire it. (Use Restore below to undo.)</p>
            <form method="POST" onsubmit="return confirm('Randomize the whole feed order? Trigrams stay intact. You can restore chronological order afterward.');">
                <input type="hidden" name="action" value="randomize_feed">
                <div class="reauth-row" style="display:flex; gap:10px; margin:8px 0;">
                    <label style="flex:1;">PASSWORD<br><input type="password" name="reauth_password" autocomplete="off" style="width:100%;"></label>
                    <label style="flex:0 0 120px;">2FA CODE<br><input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" style="width:100%;"></label>
                </div>
                <button type="submit" class="btn-smack btn-block">RANDOMIZE FEED</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>&#8634; RESTORE CHRONOLOGICAL</h3>
            <p class="skin-desc-text">Puts the feed back in date order, newest first &mdash; undoes a randomize. Trigrams stay glued as whole rows. Same guard: password + 2FA required so it can't be hit by accident.</p>
            <form method="POST" onsubmit="return confirm('Restore the feed to chronological order (newest first)? Trigrams stay intact.');">
                <input type="hidden" name="action" value="restore_chrono">
                <div class="reauth-row" style="display:flex; gap:10px; margin:8px 0;">
                    <label style="flex:1;">PASSWORD<br><input type="password" name="reauth_password" autocomplete="off" style="width:100%;"></label>
                    <label style="flex:0 0 120px;">2FA CODE<br><input type="text" name="reauth_totp" inputmode="numeric" autocomplete="off" style="width:100%;"></label>
                </div>
                <button type="submit" class="btn-smack btn-block">RESTORE ORDER</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>REBUILD THUMBNAILS</h3>
            <p class="skin-desc-text">Regenerates missing thumbnails in batches of 25. Orphan image files with no database record are deleted on the final batch.</p>
            <?php if (!empty($asset_sync_has_more)): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="sync_assets">
                    <input type="hidden" name="batch_offset" value="<?php echo $asset_sync_next_offset; ?>">
                    <button type="submit" class="btn-smack btn-block btn-backup">CONTINUE (BATCH <?php echo $asset_sync_next_offset; ?>+)</button>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="sync_assets">
                    <input type="hidden" name="batch_offset" value="0">
                    <button type="submit" class="btn-smack btn-block">REBUILD</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="box box-flex">
            <h3>REGENERATE ALL THUMBNAILS</h3>
            <p class="skin-desc-text">Force-regenerates square and aspect thumbnails plus the SMACKVERSE fediverse bake for every image, overwriting existing ones. Honours each image's saved focal point, zoom, and frame styling. Use after changing thumbnail settings or to backfill bakes for posts made before SMACKVERSE.</p>
            <?php if (!empty($regen_thumbs_has_more)): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="regen_thumbs">
                    <input type="hidden" name="batch_offset" value="<?php echo $regen_thumbs_next_offset; ?>">
                    <button type="submit" class="btn-smack btn-block btn-backup">CONTINUE (BATCH <?php echo $regen_thumbs_next_offset; ?>+)</button>
                </form>
            <?php else: ?>
                <form method="POST"
                      onsubmit="return confirm('This will overwrite all existing thumbnails. Continue?')">
                    <input type="hidden" name="action" value="regen_thumbs">
                    <input type="hidden" name="batch_offset" value="0">
                    <button type="submit" class="btn-smack btn-block">REGENERATE ALL THUMBNAILS</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="box box-flex">
            <h3>FORCE MOBILE SKIN UPDATE</h3>
            <p class="skin-desc-text">Reinstalls the mobile-only skin (Photogram) from the skin registry, ignoring version checks. Photogram is hidden from the gallery and normally only self-updates when the registry version is newer &mdash; use this to force it into sync after republishing the registry.</p>
            <form method="POST"
                  onsubmit="return confirm('This will reinstall the mobile skin from the registry, overwriting the current copy. Continue?')">
                <input type="hidden" name="action" value="force_mobile_skin_update">
                <button type="submit" class="btn-smack btn-block">FORCE MOBILE SKIN UPDATE</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>VAX INJECTOR</h3>
            <p class="skin-desc-text">Apply a signed VAX database package sent to you by SnapSmack support. Paste the package code and its one-time token, then apply. The package's Ed25519 signature, token and expiry are verified before any SQL runs &mdash; an invalid, expired or already-used package is rejected. Works even while SMACKBACK is locked down.</p>
            <form method="POST" onsubmit="return confirm('Apply this VAX package to the live database? It runs signed SQL and cannot be undone.')">
                <input type="hidden" name="action" value="apply_vax">
                <label class="skin-desc-text" for="vax_pkg">Package code</label>
                <input type="text" id="vax_pkg" name="vax_pkg" autocomplete="off" spellcheck="false"
                       placeholder="e.g. tsohn-captions-fix" style="width:100%;padding:8px;margin:4px 0 10px;box-sizing:border-box;font-family:monospace;">
                <label class="skin-desc-text" for="vax_token">One-time token</label>
                <input type="text" id="vax_token" name="vax_token" autocomplete="off" spellcheck="false"
                       placeholder="32–128 character hex token" style="width:100%;padding:8px;margin:4px 0 10px;box-sizing:border-box;font-family:monospace;">
                <button type="submit" class="btn-smack btn-block">APPLY VAX PACKAGE</button>
            </form>
        </div>
    </div>

    <div class="box mt-30">
        <h3>SCHEMA HEALTH</h3>
        <p class="skin-desc-text">Compares the live database against the current codebase schema. Detects missing tables and columns regardless of which version you upgraded from or which migrations you ran. No migration files needed — just check and fix.</p>

        <?php if (!empty($schema_issues)): ?>
            <?php $total = count($schema_issues['tables']) + count($schema_issues['columns']); ?>
            <div class="schema-report">
                <p class="schema-count"><strong><?php echo $total; ?> issue<?php echo $total !== 1 ? 's' : ''; ?> found:</strong></p>

                <?php if (!empty($schema_issues['tables'])): ?>
                    <p class="schema-section-label">MISSING TABLES</p>
                    <ul class="schema-list">
                        <?php foreach ($schema_issues['tables'] as $tbl => $_): ?>
                            <li><code><?php echo htmlspecialchars($tbl); ?></code> — table does not exist</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($schema_issues['columns'])): ?>
                    <p class="schema-section-label">MISSING COLUMNS</p>
                    <ul class="schema-list">
                        <?php foreach ($schema_issues['columns'] as $item): ?>
                            <li><code><?php echo htmlspecialchars($item['table']); ?></code> → <code><?php echo htmlspecialchars($item['col']); ?></code> <span class="dim">(<?php echo htmlspecialchars($item['def']); ?>)</span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="schema_fix">
                    <button type="submit" class="btn-smack">APPLY ALL FIXES</button>
                </form>
            </div>
        <?php else: ?>
            <div class="schema-actions">
                <form method="POST">
                    <input type="hidden" name="action" value="schema_check">
                    <button type="submit" class="btn-smack">RUN SCHEMA CHECK</button>
                </form>
                <form method="POST"
                      onsubmit="return confirm('This will apply all schema fixes automatically. Run the check first if you want to preview what will change. Continue?')">
                    <input type="hidden" name="action" value="schema_fix">
                    <button type="submit" class="btn-smack btn-backup">APPLY FIXES DIRECTLY</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="dash-grid dash-grid-2 mt-30">
        <div class="box box-flex">
            <h3>HTACCESS DIAGNOSTICS</h3>
            <p class="skin-desc-text">Verifies that root and upload directory .htaccess files contain all required SnapSmack rules — HTTPS, clean URLs, security headers, PHP blocking, Probe Guard, caching, and compression.</p>
            <form method="POST">
                <input type="hidden" name="action" value="htaccess_check">
                <button type="submit" class="btn-smack btn-block">RUN CHECK</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>HTACCESS REPAIR</h3>
            <p class="skin-desc-text">Strips any damaged SnapSmack block and writes a clean copy of all rules from <code>core/htaccess-template</code>. Preserves any non-SnapSmack rules added by your host. Restores upload directory PHP execution block.</p>
            <form method="POST" onsubmit="return confirm('This will regenerate the SnapSmack .htaccess rules. Any manual edits inside the SnapSmack block will be replaced. Continue?')">
                <input type="hidden" name="action" value="htaccess_repair">
                <button type="submit" class="btn-smack btn-block">REPAIR</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>VIEW LIVE .HTACCESS</h3>
            <p class="skin-desc-text">Shows the contents of this server's root <code>.htaccess</code> file. Read-only — useful for verifying what's actually deployed and comparing against the canonical template.</p>
            <form method="POST">
                <input type="hidden" name="action" value="htaccess_view_live">
                <button type="submit" class="btn-smack btn-block">VIEW LIVE</button>
            </form>
        </div>

        <div class="box box-flex">
            <h3>VIEW TEMPLATE</h3>
            <p class="skin-desc-text">Shows the canonical <code>core/htaccess-template</code> file shipped with SnapSmack. This is what REPAIR writes into the live <code>.htaccess</code>.</p>
            <form method="POST">
                <input type="hidden" name="action" value="htaccess_view_template">
                <button type="submit" class="btn-smack btn-block">VIEW TEMPLATE</button>
            </form>
        </div>
    </div>

    <?php if (!empty($htaccess_view_content)): ?>
    <div class="box mt-30">
        <h3><?php echo htmlspecialchars($htaccess_view_label); ?></h3>
        <pre style="background:#0c0c0c;color:#cfcfcf;padding:14px;border-radius:4px;overflow:auto;max-height:560px;font-family:monospace;font-size:12px;line-height:1.5;border:1px solid #2a2a2a;"><?php echo htmlspecialchars($htaccess_view_content); ?></pre>
    </div>
    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
