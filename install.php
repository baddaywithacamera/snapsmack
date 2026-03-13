<?php
/**
 * SNAPSMACK - First-Run Installer
 * Alpha v0.7.3
 *
 * Single-file setup wizard for fresh SnapSmack deployments. Checks the server
 * environment, creates the database schema, sets up the first admin user, and
 * generates configuration files. Self-deletes on completion.
 *
 * This file has no external dependencies — all CSS is inline.
 */

// --- CONFIGURATION ---
// The version this installer deploys.
$installer_version       = '0.7.3';
$installer_version_label = 'Alpha v0.7.3';

// --- SESSION INIT ---
session_start();

// --- CODEBASE CHECK ---
// The installer configures an existing codebase — it doesn't deploy one.
// If key files are missing, redirect to setup.php (the bootstrap deployer).
if (!file_exists(__DIR__ . '/core/parser.php') || !is_dir(__DIR__ . '/core')) {
    if (file_exists(__DIR__ . '/setup.php')) {
        header('Location: setup.php');
        exit;
    }
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>SnapSmack</title></head><body style="background:#111;color:#eee;font-family:monospace;padding:60px;text-align:center;"><h1>CODEBASE NOT FOUND</h1><p>The SnapSmack application files are missing. Upload the full codebase to this directory first, or use <code>setup.php</code> to deploy from GitHub.</p></body></html>');
}

// --- RECOVERY MODE DETECTION ---
// Allow recovery mode to bypass the safety lock when ?mode=recovery is set.
$recovery_mode = ($_GET['mode'] ?? $_POST['mode'] ?? '') === 'recovery';
$has_existing_db = false;

// --- SAFETY LOCK ---
// If SnapSmack is already installed, refuse to run (unless recovery mode).
if (file_exists(__DIR__ . '/core/db.php')) {
    try {
        require_once __DIR__ . '/core/db.php';
        $check = $pdo->query("SELECT COUNT(*) FROM snap_settings")->fetchColumn();
        if ($check > 0) {
            $has_existing_db = true;
            if (!$recovery_mode) {
                die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>SnapSmack</title></head><body style="background:#111;color:#eee;font-family:monospace;padding:60px;text-align:center;"><h1>SNAPSMACK IS ALREADY INSTALLED</h1><p>Delete <code>install.php</code> from your server, or <a href="install.php?mode=recovery" style="color:#a0ff90;">enter recovery mode</a>.</p></body></html>');
            }
        }
    } catch (Exception $e) {
        // db.php exists but connection fails or table missing — safe to continue
    }
}

// --- CSRF TOKEN ---
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['install_csrf'];

// --- RATE LIMITING ---
// Track failed DB connection attempts to prevent brute-forcing credentials.
if (!isset($_SESSION['db_attempts'])) $_SESSION['db_attempts'] = 0;
if (!isset($_SESSION['db_lockout_until'])) $_SESSION['db_lockout_until'] = 0;

// --- STEP TRACKING ---
// The installer progresses linearly. Each step validates before advancing.
$step = (int)($_POST['step'] ?? 1);
$errors = [];
$success = [];

// Validate CSRF on all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errors[] = 'Invalid session token. Please reload and try again.';
        $step = 1;
    }
}

// --- STEP PROCESSORS ---

// =====================================================================
// STEP 2 HANDLER: Database Connection Test
// =====================================================================
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {

    // Check rate limit
    if (time() < $_SESSION['db_lockout_until']) {
        $wait = $_SESSION['db_lockout_until'] - time();
        $errors[] = "Too many failed attempts. Please wait {$wait} seconds.";
        $step = 2;
    } else {
        $db_host   = trim($_POST['db_host'] ?? 'localhost');
        $db_name   = trim($_POST['db_name'] ?? '');
        $db_user   = trim($_POST['db_user'] ?? '');
        $db_pass   = $_POST['db_pass'] ?? '';
        $db_prefix = trim($_POST['db_prefix'] ?? 'snap_');

        // Sanitize prefix: alphanumeric and underscores only
        $db_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $db_prefix);
        if (empty($db_prefix)) $db_prefix = 'snap_';
        if (substr($db_prefix, -1) !== '_') $db_prefix .= '_';

        if (empty($db_name) || empty($db_user)) {
            $errors[] = 'Database name and username are required.';
            $step = 2;
        } else {
            try {
                $test_dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
                $test_pdo = new PDO($test_dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                // Connection succeeded — store in session and advance
                $_SESSION['db_host']   = $db_host;
                $_SESSION['db_name']   = $db_name;
                $_SESSION['db_user']   = $db_user;
                $_SESSION['db_pass']   = $db_pass;
                $_SESSION['db_prefix'] = $db_prefix;
                $_SESSION['db_attempts'] = 0;
                $step = 3;
            } catch (PDOException $e) {
                $_SESSION['db_attempts']++;
                if ($_SESSION['db_attempts'] >= 5) {
                    $_SESSION['db_lockout_until'] = time() + 60;
                    $_SESSION['db_attempts'] = 0;
                    $errors[] = 'Too many failed attempts. Locked out for 60 seconds.';
                } else {
                    $msg = $e->getMessage();
                    if (strpos($msg, 'Access denied') !== false) {
                        $errors[] = 'Access denied — check your username and password.';
                    } elseif (strpos($msg, 'Unknown database') !== false) {
                        $errors[] = "Database '{$db_name}' does not exist. Create it first in your hosting control panel.";
                    } elseif (strpos($msg, 'getaddrinfo') !== false || strpos($msg, 'No such host') !== false) {
                        $errors[] = "Cannot reach host '{$db_host}'. Check the hostname.";
                    } else {
                        $errors[] = 'Connection failed: ' . htmlspecialchars($msg);
                    }
                }
                $step = 2;
            }
        }
    }
}

// =====================================================================
// STEP 3 HANDLER: Schema Creation
// =====================================================================
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {

    // Re-establish connection from session
    $prefix = $_SESSION['db_prefix'];
    try {
        $dsn = "mysql:host={$_SESSION['db_host']};dbname={$_SESSION['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        $errors[] = 'Database connection lost. Go back and re-enter credentials.';
        $step = 2;
    }

    if (empty($errors)) {
        $tables = [

            // --- PHOTO POSTS ---
            "{$prefix}images" => "CREATE TABLE IF NOT EXISTS `{$prefix}images` (
                `id` int NOT NULL AUTO_INCREMENT,
                `img_title` varchar(255) NOT NULL,
                `img_slug` varchar(255) NOT NULL,
                `img_description` text,
                `img_film` varchar(100) DEFAULT NULL,
                `img_date` datetime NOT NULL,
                `img_file` varchar(255) NOT NULL,
                `img_exif` text,
                `img_download_url` varchar(500) DEFAULT NULL,
                `img_download_count` int unsigned NOT NULL DEFAULT '0',
                `img_width` int DEFAULT '0',
                `img_height` int DEFAULT '0',
                `img_status` enum('published','draft') DEFAULT 'published',
                `img_orientation` int DEFAULT '0',
                `allow_comments` tinyint(1) DEFAULT '1',
                `allow_download` tinyint(1) NOT NULL DEFAULT '1',
                `download_url` varchar(512) NOT NULL DEFAULT '',
                `img_thumb_square` varchar(255) DEFAULT NULL COMMENT 'Relative path to 400x400 square thumbnail (t_ prefix)',
                `img_thumb_aspect` varchar(255) DEFAULT NULL COMMENT 'Relative path to aspect-ratio thumbnail (a_ prefix)',
                `img_checksum` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash of main image file for recovery verification',
                `img_display_options` text DEFAULT NULL COMMENT 'JSON: per-image frame/mat/bevel overrides and extracted colour palette',
                `post_id` int DEFAULT NULL COMMENT 'FK to snap_posts — populated when image is wrapped in a post',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- CATEGORIES ---
            "{$prefix}categories" => "CREATE TABLE IF NOT EXISTS `{$prefix}categories` (
                `id` int NOT NULL AUTO_INCREMENT,
                `cat_name` varchar(100) NOT NULL,
                `cat_slug` varchar(100) NOT NULL,
                `cat_description` text,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- ALBUMS ---
            "{$prefix}albums" => "CREATE TABLE IF NOT EXISTS `{$prefix}albums` (
                `id` int NOT NULL AUTO_INCREMENT,
                `album_name` varchar(255) NOT NULL,
                `album_description` text,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- IMAGE-TO-CATEGORY MAP ---
            "{$prefix}image_cat_map" => "CREATE TABLE IF NOT EXISTS `{$prefix}image_cat_map` (
                `image_id` int NOT NULL,
                `cat_id` int NOT NULL,
                PRIMARY KEY (`image_id`, `cat_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- IMAGE-TO-ALBUM MAP ---
            "{$prefix}image_album_map" => "CREATE TABLE IF NOT EXISTS `{$prefix}image_album_map` (
                `image_id` int NOT NULL,
                `album_id` int NOT NULL,
                PRIMARY KEY (`image_id`, `album_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- STATIC PAGES ---
            "{$prefix}pages" => "CREATE TABLE IF NOT EXISTS `{$prefix}pages` (
                `id` int NOT NULL AUTO_INCREMENT,
                `slug` varchar(100) NOT NULL,
                `title` varchar(255) NOT NULL,
                `content` longtext,
                `image_asset` varchar(255) DEFAULT '',
                `is_active` tinyint(1) DEFAULT '1',
                `menu_order` int DEFAULT '0',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- SETTINGS (KEY-VALUE STORE) ---
            "{$prefix}settings" => "CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
                `setting_key` varchar(100) NOT NULL,
                `setting_val` text,
                PRIMARY KEY (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- COMMENTS ---
            "{$prefix}comments" => "CREATE TABLE IF NOT EXISTS `{$prefix}comments` (
                `id` int NOT NULL AUTO_INCREMENT,
                `img_id` int NOT NULL,
                `comment_author` varchar(100) DEFAULT NULL,
                `comment_email` varchar(150) DEFAULT NULL,
                `comment_text` text,
                `comment_date` datetime DEFAULT CURRENT_TIMESTAMP,
                `comment_ip` varchar(45) DEFAULT NULL,
                `is_approved` tinyint(1) DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `img_id` (`img_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- USERS ---
            "{$prefix}users" => "CREATE TABLE IF NOT EXISTS `{$prefix}users` (
                `id` int NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `password_hash` varchar(255) NOT NULL,
                `user_role` varchar(20) NOT NULL DEFAULT 'editor',
                `email` varchar(100) DEFAULT NULL,
                `preferred_skin` varchar(100) DEFAULT 'default-dark',
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- BLOGROLL ---
            "{$prefix}blogroll" => "CREATE TABLE IF NOT EXISTS `{$prefix}blogroll` (
                `id` int NOT NULL AUTO_INCREMENT,
                `peer_name` varchar(255) NOT NULL,
                `peer_url` varchar(255) NOT NULL,
                `cat_id` int DEFAULT NULL,
                `peer_rss` varchar(255) DEFAULT NULL,
                `peer_desc` text,
                `sort_order` int NOT NULL DEFAULT '0',
                `rss_last_fetched` datetime DEFAULT NULL,
                `rss_last_updated` datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- MEDIA ASSETS ---
            "{$prefix}assets" => "CREATE TABLE IF NOT EXISTS `{$prefix}assets` (
                `id` int NOT NULL AUTO_INCREMENT,
                `asset_name` varchar(255) NOT NULL,
                `asset_path` varchar(500) NOT NULL,
                `asset_checksum` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash for recovery verification',
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- MIGRATION TRACKING ---
            "{$prefix}migrations" => "CREATE TABLE IF NOT EXISTS `{$prefix}migrations` (
                `id`         int unsigned NOT NULL AUTO_INCREMENT,
                `migration`  varchar(200) NOT NULL,
                `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- RATE LIMITER (community prerequisite) ---
            "{$prefix}rate_limits" => "CREATE TABLE IF NOT EXISTS `{$prefix}rate_limits` (
                `id`           int unsigned NOT NULL AUTO_INCREMENT,
                `ip`           varchar(45)  NOT NULL,
                `action`       varchar(50)  NOT NULL,
                `count`        int unsigned NOT NULL DEFAULT 1,
                `window_start` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ip_action` (`ip`, `action`),
                KEY `idx_window` (`window_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- COMMUNITY USERS ---
            "{$prefix}community_users" => "CREATE TABLE IF NOT EXISTS `{$prefix}community_users` (
                `id`             int unsigned NOT NULL AUTO_INCREMENT,
                `username`       varchar(50)  NOT NULL,
                `display_name`   varchar(100) DEFAULT NULL,
                `email`          varchar(150) NOT NULL,
                `password_hash`  varchar(255) NOT NULL,
                `avatar_url`     varchar(500) DEFAULT NULL,
                `bio`            text         DEFAULT NULL,
                `status`         enum('active','unverified','suspended') NOT NULL DEFAULT 'unverified',
                `email_verified` tinyint(1)   NOT NULL DEFAULT 0,
                `last_seen_at`   datetime     DEFAULT NULL,
                `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_username` (`username`),
                UNIQUE KEY `uq_email`    (`email`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- COMMUNITY SESSIONS ---
            "{$prefix}community_sessions" => "CREATE TABLE IF NOT EXISTS `{$prefix}community_sessions` (
                `id`         int unsigned NOT NULL AUTO_INCREMENT,
                `user_id`    int unsigned NOT NULL,
                `token`      varchar(64)  NOT NULL,
                `expires_at` datetime     NOT NULL,
                `ip`         varchar(45)  DEFAULT NULL,
                `user_agent` varchar(500) DEFAULT NULL,
                `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_token`      (`token`),
                KEY `idx_user_id`    (`user_id`),
                KEY `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- COMMUNITY TOKENS (email verify / password reset) ---
            "{$prefix}community_tokens" => "CREATE TABLE IF NOT EXISTS `{$prefix}community_tokens` (
                `id`         int unsigned NOT NULL AUTO_INCREMENT,
                `user_id`    int unsigned NOT NULL,
                `token`      varchar(64)  NOT NULL,
                `type`       varchar(30)  NOT NULL,
                `expires_at` datetime     NOT NULL,
                `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_token`      (`token`),
                KEY `idx_user_type` (`user_id`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- LIKES ---
            "{$prefix}likes" => "CREATE TABLE IF NOT EXISTS `{$prefix}likes` (
                `id`         int unsigned NOT NULL AUTO_INCREMENT,
                `post_id`    int unsigned NOT NULL,
                `user_id`    int unsigned NOT NULL,
                `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_post_user` (`post_id`, `user_id`),
                KEY `idx_post_id` (`post_id`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- REACTIONS ---
            "{$prefix}reactions" => "CREATE TABLE IF NOT EXISTS `{$prefix}reactions` (
                `id`            int unsigned NOT NULL AUTO_INCREMENT,
                `post_id`       int unsigned NOT NULL,
                `user_id`       int unsigned NOT NULL,
                `reaction_code` varchar(20)  NOT NULL,
                `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_post_user` (`post_id`, `user_id`),
                KEY `idx_post_id` (`post_id`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- COMMUNITY COMMENTS (separate from legacy snap_comments) ---
            "{$prefix}community_comments" => "CREATE TABLE IF NOT EXISTS `{$prefix}community_comments` (
                `id`           int unsigned NOT NULL AUTO_INCREMENT,
                `post_id`      int unsigned NOT NULL,
                `user_id`      int unsigned NULL DEFAULT NULL,
                `guest_name`   varchar(100) NULL DEFAULT NULL,
                `guest_email`  varchar(200) NULL DEFAULT NULL,
                `comment_text` text         NOT NULL,
                `status`       enum('visible','hidden','deleted') NOT NULL DEFAULT 'visible',
                `ip`           varchar(45)  NULL DEFAULT NULL,
                `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_post_status` (`post_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- POSTS (container layer wrapping one or more images) ---
            "{$prefix}posts" => "CREATE TABLE IF NOT EXISTS `{$prefix}posts` (
                `id`              int          NOT NULL AUTO_INCREMENT,
                `title`           varchar(500) NOT NULL,
                `slug`            varchar(600) NOT NULL,
                `description`     text         DEFAULT NULL,
                `post_type`       enum('single','carousel','panorama') NOT NULL DEFAULT 'single',
                `status`          varchar(20)  NOT NULL DEFAULT 'published',
                `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `allow_comments`  tinyint(1)   NOT NULL DEFAULT 1,
                `allow_download`  tinyint(1)   NOT NULL DEFAULT 0,
                `download_url`    varchar(500) DEFAULT NULL,
                `download_count`  int          NOT NULL DEFAULT 0,
                `panorama_rows`   tinyint      NOT NULL DEFAULT 1,
                `import_source`   varchar(50)  DEFAULT NULL,
                `import_id`       varchar(200) DEFAULT NULL,
                `post_img_size_pct`   tinyint unsigned NOT NULL DEFAULT 100,
                `post_border_px`      tinyint unsigned NOT NULL DEFAULT 0,
                `post_border_color`   char(7)          NOT NULL DEFAULT '#000000',
                `post_bg_color`       char(7)          NOT NULL DEFAULT '#ffffff',
                `post_shadow`         tinyint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_slug`   (`slug`),
                UNIQUE KEY `uq_import` (`import_source`, `import_id`),
                KEY `idx_status`       (`status`),
                KEY `idx_created_at`   (`created_at`),
                KEY `idx_post_type`    (`post_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- POST-TO-IMAGE MAP ---
            "{$prefix}post_images" => "CREATE TABLE IF NOT EXISTS `{$prefix}post_images` (
                `id`               int          NOT NULL AUTO_INCREMENT,
                `post_id`          int          NOT NULL,
                `image_id`         int          NOT NULL,
                `sort_position`    smallint     NOT NULL DEFAULT 0,
                `is_cover`         tinyint(1)   NOT NULL DEFAULT 0,
                `grid_col`         tinyint      DEFAULT NULL,
                `grid_row`         tinyint      DEFAULT NULL,
                `img_size_pct`     tinyint unsigned NOT NULL DEFAULT 100,
                `img_border_px`    tinyint unsigned NOT NULL DEFAULT 0,
                `img_border_color` char(7)          NOT NULL DEFAULT '#000000',
                `img_bg_color`     char(7)          NOT NULL DEFAULT '#ffffff',
                `img_shadow`       tinyint unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_image`  (`image_id`),
                KEY `idx_post_id` (`post_id`),
                KEY `idx_sort`    (`post_id`, `sort_position`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- POST-TO-CATEGORY MAP ---
            "{$prefix}post_cat_map" => "CREATE TABLE IF NOT EXISTS `{$prefix}post_cat_map` (
                `post_id` int NOT NULL,
                `cat_id`  int NOT NULL,
                PRIMARY KEY (`post_id`, `cat_id`),
                KEY `idx_cat_id` (`cat_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- POST-TO-ALBUM MAP ---
            "{$prefix}post_album_map" => "CREATE TABLE IF NOT EXISTS `{$prefix}post_album_map` (
                `post_id`  int NOT NULL,
                `album_id` int NOT NULL,
                PRIMARY KEY (`post_id`, `album_id`),
                KEY `idx_album_id` (`album_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- HASHTAGS ---
            "{$prefix}tags" => "CREATE TABLE IF NOT EXISTS `{$prefix}tags` (
                `id`         int unsigned AUTO_INCREMENT PRIMARY KEY,
                `tag`        varchar(100) NOT NULL,
                `slug`       varchar(100) NOT NULL,
                `use_count`  int unsigned DEFAULT 0,
                `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- IMAGE-TO-TAG MAP ---
            "{$prefix}image_tags" => "CREATE TABLE IF NOT EXISTS `{$prefix}image_tags` (
                `id`         int unsigned AUTO_INCREMENT PRIMARY KEY,
                `image_id`   int unsigned NOT NULL,
                `tag_id`     int unsigned NOT NULL,
                `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_image_tag` (`image_id`, `tag_id`),
                KEY `idx_tag_id`   (`tag_id`),
                KEY `idx_image_id` (`image_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        $created = [];
        $failed  = [];

        foreach ($tables as $name => $sql) {
            try {
                $pdo->exec($sql);
                $created[] = $name;
            } catch (PDOException $e) {
                $failed[] = "{$name}: " . $e->getMessage();
            }
        }

        if (!empty($failed)) {
            $errors = $failed;
            $step = 3;
        } else {
            $_SESSION['tables_created'] = $created;
            $step = 4; // Advance to admin user creation
        }
    }
}

// =====================================================================
// STEP 4 HANDLER: Admin User Creation
// =====================================================================
if ($step === 4 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_user']) && empty($errors)) {

    $site_name    = trim($_POST['site_name'] ?? '');
    $site_tagline = trim($_POST['site_tagline'] ?? '');
    $site_url     = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $admin_user   = trim($_POST['admin_user'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = $_POST['admin_pass'] ?? '';
    $admin_pass2 = $_POST['admin_pass2'] ?? '';

    if (empty($site_name)) $errors[] = 'Site name is required.';
    if (empty($admin_user)) $errors[] = 'Username is required.';
    if (empty($admin_email) || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($admin_pass) < 12) $errors[] = 'Password must be at least 12 characters.';
    if ($admin_pass !== $admin_pass2) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $prefix = $_SESSION['db_prefix'];
        try {
            $dsn = "mysql:host={$_SESSION['db_host']};dbname={$_SESSION['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (username, email, password_hash, user_role, preferred_skin) VALUES (?, ?, ?, 'admin', 'midnight-lime')");
            $stmt->execute([$admin_user, $admin_email, $hash]);

            $_SESSION['admin_created'] = true;
            $_SESSION['site_name']    = $site_name;
            $_SESSION['site_tagline'] = $site_tagline;
            $_SESSION['site_url']     = $site_url;
            $_SESSION['admin_email']  = $admin_email;
            $step = 5;
        } catch (PDOException $e) {
            $errors[] = 'Failed to create admin user: ' . htmlspecialchars($e->getMessage());
            $step = 4;
        }
    } else {
        $step = 4;
    }
}

// =====================================================================
// STEP 5 HANDLER: Generate Config, Seed Settings, Create Dirs, Finish
// =====================================================================
if ($step === 5 && empty($errors)) {

    $prefix = $_SESSION['db_prefix'];

    // --- GENERATE core/db.php ---
    $db_php = '<?php
/**
 * SNAPSMACK - Core Database Connection
 * ' . $installer_version_label . '
 *
 * Establishes PDO connection to the MySQL database with proper error handling
 * and security settings. Loads constants first to ensure availability across
 * the application.
 */

require_once __DIR__ . \'/constants.php\';

// --- DATABASE CREDENTIALS ---
$host    = ' . var_export($_SESSION['db_host'], true) . ';
$db      = ' . var_export($_SESSION['db_name'], true) . ';
$user    = ' . var_export($_SESSION['db_user'], true) . ';
$pass    = ' . var_export($_SESSION['db_pass'], true) . ';
$charset = \'utf8mb4\';

// --- CONNECTION SETUP ---
// Configure PDO to throw exceptions on errors, use associative arrays,
// and use prepared statements for security
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// --- ESTABLISH CONNECTION ---
// Fatal error handling: if the database is unreachable, display a safe
// error message without exposing credentials to the user
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("<div style=\'background:#200;color:#f99;padding:20px;border:1px solid red;font-family:monospace;\'><h3>DATABASE_LINK_FAILURE</h3>The connection to the data vault was interrupted.</div>");
}
';

    $wrote_db = @file_put_contents(__DIR__ . '/core/db.php', $db_php);
    if ($wrote_db === false) {
        $errors[] = 'Could not write core/db.php — check that the core/ directory is writable.';
    } else {
        @chmod(__DIR__ . '/core/db.php', 0640);
    }

    // --- GENERATE core/constants.php ---
    if (empty($errors)) {
        $constants_php = '<?php
/**
 * SNAPSMACK - System Constants
 * ' . $installer_version_label . '
 *
 * Defines version strings and system-wide constants. Include this early in
 * the bootstrap chain (e.g., from db.php) to ensure availability throughout
 * the application.
 */

define(\'SNAPSMACK_VERSION\', \'' . $installer_version_label . '\');
define(\'SNAPSMACK_VERSION_SHORT\', \'' . $installer_version . '\');
define(\'SNAPSMACK_TABLE_PREFIX\', \'' . $prefix . '\');

// --- MOBILE SKIN OVERRIDE ---
// The slug of the skin forced onto mobile devices. This skin is not selectable
// in the admin skin picker — it is served automatically when a phone is detected.
define(\'SNAPSMACK_MOBILE_SKIN\', \'photogram\');

/**
 * Detect mobile devices via User-Agent string.
 * Returns true for phones; tablets are treated as desktop.
 */
function snapsmack_is_mobile(): bool {
    $ua = $_SERVER[\'HTTP_USER_AGENT\'] ?? \'\';
    if (empty($ua)) return false;

    // Match common phone tokens. The \'Mobile\' token catches most modern phones
    // (iOS Safari, Chrome Mobile, Samsung, etc.). Additional patterns cover
    // older or niche handsets. Tablets (iPad, Android without \'Mobile\') are
    // intentionally excluded so they receive the normal desktop skin.
    return (bool) preg_match(\'/Mobile|iPhone|iPod|Android.*Mobile|webOS|BlackBerry|Opera Mini|IEMobile|Windows Phone/i\', $ua);
}
';

        $wrote_const = @file_put_contents(__DIR__ . '/core/constants.php', $constants_php);
        if ($wrote_const === false) {
            $errors[] = 'Could not write core/constants.php.';
        }
    }

    // --- SEED DEFAULT SETTINGS ---
    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$_SESSION['db_host']};dbname={$_SESSION['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            $defaults = [
                'site_name'                 => $_SESSION['site_name'] ?? 'My SnapSmack Site',
                'site_tagline'              => $_SESSION['site_tagline'] ?? '',
                'site_url'                  => $_SESSION['site_url'] ?? '',
                'site_email'                => $_SESSION['admin_email'] ?? '',
                'active_skin'               => 'new-horizon-dark',
                'active_skin_variant'       => 'dark',
                'active_theme'              => 'midnight-lime',
                'timezone'                  => 'UTC',
                'date_format'               => 'F j, Y',
                'header_type'               => 'text',
                'header_logo_url'           => '',
                'favicon_url'               => '',
                'global_comments_enabled'   => '1',
                'global_downloads_enabled'  => '0',
                'exif_display_enabled'      => '1',
                'blogroll_enabled'          => '1',
                'jpeg_quality'              => '85',
                'max_width_landscape'       => '2500',
                'max_height_portrait'       => '1850',
                'footer_slot_copyright'         => 'on',
                'footer_slot_copyright_custom'  => '',
                'footer_slot_email'             => 'on',
                'footer_slot_email_custom'      => '',
                'footer_slot_theme'             => 'off',
                'footer_slot_theme_custom'      => '',
                'footer_slot_powered'           => 'on',
                'footer_slot_powered_custom'    => '',
                'nav_slot_1'                => '0',
                'nav_slot_2'                => '0',
                'nav_slot_3'                => '0',
                'nav_slot_4'                => '0',
                'custom_css_public'         => '',
                'custom_css_admin'          => '',
                'footer_injection_scripts'  => '',
                'skin_registry_url'         => 'https://snapsmack.ca/skins/registry.json',
                'installed_version'         => $installer_version,
                'install_timestamp'         => date('Y-m-d H:i:s'),
            ];

            $stmt = $pdo->prepare("INSERT INTO `{$prefix}settings` (setting_key, setting_val) VALUES (?, ?)");
            foreach ($defaults as $key => $val) {
                $stmt->execute([$key, $val]);
            }
        } catch (PDOException $e) {
            $errors[] = 'Failed to seed settings: ' . htmlspecialchars($e->getMessage());
        }
    }

    // --- CREATE DIRECTORIES ---
    if (empty($errors)) {
        $dirs = [
            __DIR__ . '/img_uploads',
            __DIR__ . '/img_uploads/thumbs',
            __DIR__ . '/assets/img',
            __DIR__ . '/media_assets',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    $errors[] = "Could not create directory: " . basename($dir);
                }
            }
        }

        // Block PHP execution inside the uploads directory
        $htaccess_content = "<FilesMatch \"\\.php$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n";
        @file_put_contents(__DIR__ . '/img_uploads/.htaccess', $htaccess_content);

        // Generate or append SnapSmack rules to root .htaccess
        // Uses a marker comment so we can detect if our rules are already present
        $htaccess_marker = '# SNAPSMACK-HTACCESS-RULES';
        $htaccess_path = __DIR__ . '/.htaccess';
        $existing = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';

        if (strpos($existing, $htaccess_marker) === false) {
            $snapsmack_rules = <<<'HTACCESS'

# ─────────────────────────────────────────────────────────────
# SNAPSMACK-HTACCESS-RULES
# Do not remove the marker above — the installer uses it to
# detect whether these rules have already been added.
# ─────────────────────────────────────────────────────────────

# ─── FORCE HTTPS ─────────────────────────────────────────────
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

# ─── PHP LIMITS ──────────────────────────────────────────────
php_value upload_max_filesize 64M
php_value post_max_size 64M
php_value memory_limit 128M
php_value max_execution_time 120

# ─── CLEAN URL ROUTER ────────────────────────────────────────
RewriteBase /

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

RewriteRule ^archive$ archive.php [L,QSA]
RewriteRule ^rss$ rss.php [L,QSA]
RewriteRule ^feed$ rss.php [L,QSA]

RewriteRule ^([a-zA-Z0-9_-]+)$ index.php?name=$1 [L,QSA]

# ─── SECURITY HEADERS ────────────────────────────────────────
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# ─── BLOCK SENSITIVE FILES ───────────────────────────────────
<FilesMatch "(^\.ht|\.sql$|\.log$|\.bak$|\.inc$|\.sh$|\.env$)">
    Order Allow,Deny
    Deny from all
</FilesMatch>

<FilesMatch "^(db|auth|constants|release-pubkey|updater|skin-registry|manifest-inventory)\.php$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# ─── NO DIRECTORY LISTINGS ───────────────────────────────────
Options -Indexes

# ─── STATIC ASSET CACHING ───────────────────────────────────
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpeg "access plus 30 days"
    ExpiresByType image/png "access plus 30 days"
    ExpiresByType image/gif "access plus 30 days"
    ExpiresByType image/webp "access plus 30 days"
    ExpiresByType text/css "access plus 7 days"
    ExpiresByType application/javascript "access plus 7 days"
    ExpiresByType font/ttf "access plus 30 days"
    ExpiresByType font/woff2 "access plus 30 days"
</IfModule>

# ─── GZIP COMPRESSION ───────────────────────────────────────
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
</IfModule>
HTACCESS;
            // Append to existing or create new
            @file_put_contents($htaccess_path, $existing . $snapsmack_rules, LOCK_EX);
        }
    }

    // --- VERIFY SHIPPED SKINS ---
    // The installer ships with two skins: New Horizon (desktop) and Pocket Rocket (mobile).
    // New Horizon is a hard requirement — the public site will 404 without it.
    // Pocket Rocket is a soft warning — mobile users just get the desktop skin instead.
    $skin_warning = '';
    if (!is_dir(__DIR__ . '/skins/new-horizon-dark')) {
        $errors[] = 'Default skin "New Horizon" not found in skins/. The public site cannot load without it. Make sure the full SnapSmack codebase (including the skins/ directory) is uploaded before running the installer.';
        $skin_warning = $errors[count($errors) - 1];
    }
    if (!is_dir(__DIR__ . '/skins/photogram')) {
        $skin_warning .= ($skin_warning ? ' ' : '') . 'Mobile skin "Photogram" not found in skins/. Mobile visitors will see the desktop skin until Photogram is installed from the gallery.';
    }

    // --- SELF-DELETE ---
    $self_deleted = false;
    if (empty($errors)) {
        $self_deleted = @unlink(__FILE__);
    }

    // If we got here with no errors, we're done
    if (empty($errors)) {
        $step = 'complete';
    }
}


// =====================================================================
// RECOVERY MODE STEP HANDLERS
// =====================================================================

// Recovery Step R2: SQL Dump Import
if ($recovery_mode && $step === 'r2' && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {

    if ($has_existing_db) {
        // Existing DB — skip import, go straight to file restoration
        $_SESSION['recovery_db_ready'] = true;
        $step = 'r3';
    } else {
        // Need DB credentials first
        $db_host   = trim($_POST['db_host'] ?? 'localhost');
        $db_name   = trim($_POST['db_name'] ?? '');
        $db_user   = trim($_POST['db_user'] ?? '');
        $db_pass   = $_POST['db_pass'] ?? '';

        if (empty($db_name) || empty($db_user)) {
            $errors[] = 'Database name and username are required.';
            $step = 'r2';
        } else {
            try {
                $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
                $pdo = new PDO($dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                $_SESSION['db_host']   = $db_host;
                $_SESSION['db_name']   = $db_name;
                $_SESSION['db_user']   = $db_user;
                $_SESSION['db_pass']   = $db_pass;

                // Import the SQL dump
                if (isset($_FILES['sql_dump']) && $_FILES['sql_dump']['error'] === UPLOAD_ERR_OK) {
                    require_once __DIR__ . '/core/recovery-engine.php';
                    $engine = new SnapSmackRecovery($pdo, __DIR__);
                    $result = $engine->importSqlDump($_FILES['sql_dump']['tmp_name']);

                    if (!empty($result['errors'])) {
                        $errors = array_merge($errors, array_slice($result['errors'], 0, 10));
                        $step = 'r2';
                    } else {
                        $_SESSION['recovery_db_ready'] = true;
                        $_SESSION['recovery_imported'] = $result['imported'];

                        // Generate db.php if it doesn't exist
                        if (!file_exists(__DIR__ . '/core/db.php')) {
                            $db_php = '<?php
$host    = ' . var_export($db_host, true) . ';
$db      = ' . var_export($db_name, true) . ';
$user    = ' . var_export($db_user, true) . ';
$pass    = ' . var_export($db_pass, true) . ';
$charset = \'utf8mb4\';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try { $pdo = new PDO($dsn, $user, $pass, $options); } catch (\PDOException $e) { die("DATABASE_LINK_FAILURE"); }
';
                            @file_put_contents(__DIR__ . '/core/db.php', $db_php);
                            @chmod(__DIR__ . '/core/db.php', 0640);
                        }

                        $step = 'r3';
                    }
                } else {
                    $errors[] = 'Please upload a SQL dump file.';
                    $step = 'r2';
                }
            } catch (PDOException $e) {
                $errors[] = 'Database connection failed: ' . htmlspecialchars($e->getMessage());
                $step = 'r2';
            }
        }
    }
}

// Recovery Step R3 → R4: Execute file restoration
if ($recovery_mode && $step === 'r4' && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $image_mode = $_POST['image_mode'] ?? 'in_place';
    if (!in_array($image_mode, ['in_place', 'flat'], true)) {
        $image_mode = 'in_place';
    }
    $flat_path = trim($_POST['flat_path'] ?? '');

    // Sanitize flat path — no directory traversal
    $flat_path = str_replace(['..', "\0"], '', $flat_path);

    $_SESSION['recovery_image_mode'] = $image_mode;
    $_SESSION['recovery_flat_path']  = $flat_path;
    $step = 'r4_exec';
}

// =====================================================================
// HTML OUTPUT
// =====================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SnapSmack Installer</title>
    <style>
        /* --- INSTALLER THEME --- */
        /* Self-contained dark theme inspired by the SnapSmack admin panel. */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #0e0e0e;
            color: #d0d0d0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 40px 20px;
        }
        .installer {
            width: 100%;
            max-width: 640px;
        }
        h1 {
            font-size: 1.6rem;
            color: #a0ff90;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        h1 span { color: #555; font-weight: 300; }
        .subtitle {
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 40px;
            letter-spacing: 1px;
        }
        h2 {
            font-size: 1.1rem;
            color: #eee;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #2a2a2a;
        }
        .step-indicator {
            display: flex;
            gap: 8px;
            margin-bottom: 30px;
        }
        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2a2a2a;
        }
        .step-dot.active { background: #a0ff90; }
        .step-dot.done { background: #4a7a40; }

        /* --- FORMS --- */
        label {
            display: block;
            color: #999;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
            margin-top: 16px;
        }
        label:first-of-type { margin-top: 0; }
        input[type="text"],
        input[type="password"],
        input[type="email"] {
            width: 100%;
            padding: 10px 12px;
            background: #1a1a1a;
            border: 1px solid #333;
            color: #eee;
            font-size: 0.95rem;
            font-family: monospace;
            border-radius: 3px;
        }
        input:focus {
            outline: none;
            border-color: #a0ff90;
        }
        .hint {
            color: #555;
            font-size: 0.78rem;
            margin-top: 3px;
        }

        /* --- BUTTONS --- */
        button, .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #a0ff90;
            color: #0e0e0e;
            border: none;
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            border-radius: 3px;
            margin-top: 28px;
        }
        button:hover, .btn:hover { background: #c0ffb0; }
        button:disabled {
            background: #333;
            color: #666;
            cursor: not-allowed;
        }

        /* --- CHECK LIST --- */
        .check-list { list-style: none; margin: 20px 0; }
        .check-list li {
            padding: 8px 0;
            border-bottom: 1px solid #1a1a1a;
            display: flex;
            justify-content: space-between;
        }
        .pass { color: #a0ff90; }
        .fail { color: #ff6b6b; }
        .warn { color: #ffd866; }

        /* --- MESSAGES --- */
        .error-box {
            background: #2a0a0a;
            border: 1px solid #ff6b6b;
            color: #ff9999;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        .success-box {
            background: #0a2a0a;
            border: 1px solid #a0ff90;
            color: #c0ffb0;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 0.9rem;
        }
        .warn-box {
            background: #2a2200;
            border: 1px solid #ffd866;
            color: #ffe088;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 3px;
            font-size: 0.9rem;
        }

        /* --- TABLE LIST --- */
        .table-list {
            list-style: none;
            margin: 10px 0 20px 0;
            columns: 2;
        }
        .table-list li {
            padding: 3px 0;
            font-family: monospace;
            font-size: 0.85rem;
            color: #a0ff90;
        }
        .table-list li::before {
            content: '+ ';
            color: #4a7a40;
        }

        /* --- COMPLETION --- */
        .complete-box {
            text-align: center;
            padding: 40px 20px;
        }
        .complete-box h2 {
            color: #a0ff90;
            border: none;
            font-size: 1.4rem;
        }
        .complete-box p { margin: 10px 0; }
        .complete-box a {
            display: inline-block;
            margin-top: 24px;
            padding: 14px 40px;
            background: #a0ff90;
            color: #0e0e0e;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 3px;
        }
        .complete-box a:hover { background: #c0ffb0; }

        /* --- INLINE STYLES MOVED TO CLASSES --- */
        .install-desc-note {
            color: #888;
            margin-bottom: 20px;
        }
        .install-divider-label {
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid #2a2a2a;
        }
        .install-file-input {
            color: #ccc;
        }
        .install-recovery-note {
            color: #888;
            margin-bottom: 20px;
        }
        .install-image-count-label {
            color: #888;
            margin-bottom: 20px;
        }
        .install-count-highlight {
            color: #a0ff90;
        }
        .install-radio-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 20px;
            cursor: pointer;
        }
        .install-radio-input {
            margin-top: 4px;
        }
        .install-radio-text {
            color: #eee;
        }
        .install-flat-path-label {
            margin-top: 16px;
        }
    </style>
</head>
<body>
<div class="installer">

    <h1>SNAPSMACK <span>INSTALLER</span></h1>
    <p class="subtitle"><?php echo $installer_version_label; ?></p>

    <?php
    // --- STEP DOTS ---
    // Map internal step numbers to visual step numbers (1–4).
    // Internal step 3 (schema creation) is processing-only and immediately
    // advances to step 4, so the user never sees it as a distinct page.
    // Visual mapping: internal 1→1, 2→2, 3→3, 4→3, 5→4, complete→done
    $total_steps = 4;
    if ($step === 'complete') {
        $current_num = $total_steps + 1; // all dots "done"
    } elseif ($step >= 4) {
        $current_num = $step - 1; // shift 4→3, 5→4
    } else {
        $current_num = $step;
    }
    echo '<div class="step-indicator">';
    for ($i = 1; $i <= $total_steps; $i++) {
        if ($i < $current_num) echo '<div class="step-dot done"></div>';
        elseif ($i == $current_num) echo '<div class="step-dot active"></div>';
        else echo '<div class="step-dot"></div>';
    }
    echo '</div>';
    ?>

    <?php if (!empty($errors)): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?>
                <div><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // STEP 1: Environment Check ?>
    <?php // ============================================================= ?>
    <?php if ($step === 1): ?>
        <h2>Step 1 — Environment Check</h2>

        <?php
        $checks = [];
        $all_pass = true;

        // PHP Version
        $php_ok = version_compare(PHP_VERSION, '8.0.0', '>=');
        $checks[] = ['PHP Version (' . PHP_VERSION . ')', $php_ok, 'Requires 8.0+'];
        if (!$php_ok) $all_pass = false;

        // PDO MySQL
        $pdo_ok = extension_loaded('pdo_mysql');
        $checks[] = ['PDO MySQL Extension', $pdo_ok, 'Required for database access'];
        if (!$pdo_ok) $all_pass = false;

        // GD Library
        $gd_ok = extension_loaded('gd');
        $checks[] = ['GD Library', $gd_ok, 'Required for image processing'];
        if (!$gd_ok) $all_pass = false;

        // EXIF
        $exif_ok = function_exists('exif_read_data');
        $checks[] = ['EXIF Support', $exif_ok, 'Required for reading photo metadata'];
        if (!$exif_ok) $all_pass = false;

        // Libsodium
        $sodium_ok = function_exists('sodium_crypto_sign_verify_detached');
        $checks[] = ['Libsodium', $sodium_ok, 'Required for verifying signed updates'];
        if (!$sodium_ok) $all_pass = false;

        // Write permissions
        $dirs_to_check = [
            '.' => 'Application root',
            'core' => 'core/',
        ];
        foreach ($dirs_to_check as $dir => $label) {
            $writable = is_writable(__DIR__ . '/' . $dir);
            $checks[] = ["Write: {$label}", $writable, ''];
            if (!$writable) $all_pass = false;
        }
        ?>

        <ul class="check-list">
        <?php foreach ($checks as $c): ?>
            <li>
                <span><?php echo $c[0]; ?></span>
                <span class="<?php echo $c[1] ? 'pass' : 'fail'; ?>">
                    <?php echo $c[1] ? 'PASS' : 'FAIL'; ?>
                </span>
            </li>
        <?php endforeach; ?>
        </ul>

        <?php if ($all_pass): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="step" value="2">
                <button type="submit">Continue</button>
            </form>
        <?php else: ?>
            <div class="error-box">One or more requirements are not met. Fix the issues above and reload this page.</div>
        <?php endif; ?>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // STEP 2: Database Configuration ?>
    <?php // ============================================================= ?>
    <?php if ($step === 2): ?>
        <h2>Step 2 — Database Configuration</h2>
        <p class="install-desc-note">Enter the database credentials from your hosting control panel. The database must already exist.</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="step" value="2">

            <label for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
            <div class="hint">Usually "localhost" on shared hosting</div>

            <label for="db_name">Database Name</label>
            <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" autofocus>

            <label for="db_user">Database Username</label>
            <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">

            <label for="db_pass">Database Password</label>
            <input type="password" id="db_pass" name="db_pass">

            <label for="db_prefix">Table Prefix</label>
            <input type="text" id="db_prefix" name="db_prefix" value="<?php echo htmlspecialchars($_POST['db_prefix'] ?? 'snap_'); ?>">
            <div class="hint">Letters, numbers, underscores only. Default: snap_</div>

            <button type="submit">Test Connection &amp; Continue</button>
        </form>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // STEP 3: Schema Created — Show Results + Admin User Form ?>
    <?php // ============================================================= ?>
    <?php if ($step === 4 && !isset($_POST['admin_user'])): ?>
        <h2>Step 3 — Database Created</h2>

        <div class="success-box">All tables created successfully.</div>

        <ul class="table-list">
        <?php foreach ($_SESSION['tables_created'] ?? [] as $t): ?>
            <li><?php echo htmlspecialchars($t); ?></li>
        <?php endforeach; ?>
        </ul>

        <h2>Step 4 — Your Site &amp; Admin Account</h2>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="step" value="4">

            <label for="site_name">Site Name</label>
            <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($_POST['site_name'] ?? ''); ?>" autofocus>
            <div class="hint">The name of your photo blog. Shown in the header and browser tab.</div>

            <label for="site_tagline">Tagline</label>
            <input type="text" id="site_tagline" name="site_tagline" value="<?php echo htmlspecialchars($_POST['site_tagline'] ?? ''); ?>">
            <div class="hint">Optional. A short description or subtitle.</div>

            <label for="site_url">Site URL</label>
            <input type="text" id="site_url" name="site_url" value="<?php echo htmlspecialchars($_POST['site_url'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'))); ?>" placeholder="https://example.com">
            <div class="hint">The full URL to your SnapSmack site. No trailing slash.</div>

            <label for="admin_user" class="install-divider-label">Admin Username</label>
            <input type="text" id="admin_user" name="admin_user" value="<?php echo htmlspecialchars($_POST['admin_user'] ?? ''); ?>">

            <label for="admin_email">Admin Email</label>
            <input type="email" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>">

            <label for="admin_pass">Password</label>
            <input type="password" id="admin_pass" name="admin_pass">
            <div class="hint">Minimum 12 characters. Length is security.</div>

            <label for="admin_pass2">Confirm Password</label>
            <input type="password" id="admin_pass2" name="admin_pass2">

            <button type="submit">Create Site &amp; Finish</button>
        </form>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // STEP 4: Admin form re-display on validation error ?>
    <?php // ============================================================= ?>
    <?php if ($step === 4 && isset($_POST['admin_user'])): ?>
        <h2>Step 4 — Your Site &amp; Admin Account</h2>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="step" value="4">

            <label for="site_name">Site Name</label>
            <input type="text" id="site_name" name="site_name" value="<?php echo htmlspecialchars($_POST['site_name'] ?? ''); ?>" autofocus>
            <div class="hint">The name of your photo blog. Shown in the header and browser tab.</div>

            <label for="site_tagline">Tagline</label>
            <input type="text" id="site_tagline" name="site_tagline" value="<?php echo htmlspecialchars($_POST['site_tagline'] ?? ''); ?>">
            <div class="hint">Optional. A short description or subtitle.</div>

            <label for="site_url">Site URL</label>
            <input type="text" id="site_url" name="site_url" value="<?php echo htmlspecialchars($_POST['site_url'] ?? ''); ?>" placeholder="https://example.com">
            <div class="hint">The full URL to your SnapSmack site. No trailing slash.</div>

            <label for="admin_user" class="install-divider-label">Admin Username</label>
            <input type="text" id="admin_user" name="admin_user" value="<?php echo htmlspecialchars($_POST['admin_user'] ?? ''); ?>">

            <label for="admin_email">Admin Email</label>
            <input type="email" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>">

            <label for="admin_pass">Password</label>
            <input type="password" id="admin_pass" name="admin_pass">
            <div class="hint">Minimum 12 characters. Length is security.</div>

            <label for="admin_pass2">Confirm Password</label>
            <input type="password" id="admin_pass2" name="admin_pass2">

            <button type="submit">Create Site &amp; Finish</button>
        </form>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // COMPLETE ?>
    <?php // ============================================================= ?>
    <?php if ($step === 'complete'): ?>
        <div class="complete-box">
            <h2>INSTALLATION COMPLETE</h2>
            <p>SnapSmack <?php echo $installer_version_label; ?> is ready.</p>

            <?php if (!empty($skin_warning)): ?>
                <div class="warn-box"><?php echo $skin_warning; ?></div>
            <?php endif; ?>

            <?php if (!$self_deleted): ?>
                <div class="warn-box">
                    <strong>Security Warning:</strong> Could not delete install.php automatically.
                    Delete this file from your server manually before doing anything else.
                </div>
            <?php else: ?>
                <div class="success-box">install.php has been deleted automatically.</div>
            <?php endif; ?>

            <a href="login.php">Log In</a>
        </div>
    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // RECOVERY: Step R1 — Mode Selection ?>
    <?php // ============================================================= ?>
    <?php if ($recovery_mode && $step === 1): ?>
        <h2>Recovery Mode</h2>
        <div class="warn-box">
            You are restoring an existing SnapSmack installation. This process will use your
            database records to verify and relocate image files, regenerate missing thumbnails,
            and compute integrity checksums.
        </div>

        <?php if ($has_existing_db): ?>
            <div class="success-box">
                Existing database connection found. Your data is intact — proceeding to file restoration.
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="step" value="r3">
                <input type="hidden" name="mode" value="recovery">
                <button type="submit">Continue to File Restoration</button>
            </form>
        <?php else: ?>
            <p class="install-recovery-note">No existing database found. You'll need to provide credentials and a SQL dump file.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="step" value="r2">
                <input type="hidden" name="mode" value="recovery">
                <button type="submit">Begin Recovery</button>
            </form>
        <?php endif; ?>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // RECOVERY: Step R2 — Database Credentials + SQL Import ?>
    <?php // ============================================================= ?>
    <?php if ($recovery_mode && $step === 'r2'): ?>
        <h2>Recovery — Database &amp; SQL Import</h2>
        <p class="install-recovery-note">Enter your database credentials and upload the SQL dump from your backup.</p>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="step" value="r2">
            <input type="hidden" name="mode" value="recovery">

            <label for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">

            <label for="db_name">Database Name</label>
            <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" autofocus>

            <label for="db_user">Database Username</label>
            <input type="text" id="db_user" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">

            <label for="db_pass">Database Password</label>
            <input type="password" id="db_pass" name="db_pass">

            <label for="sql_dump" class="install-divider-label">SQL Dump File</label>
            <input type="file" id="sql_dump" name="sql_dump" accept=".sql" class="install-file-input">
            <div class="hint">The .sql file from Backup &amp; Recovery → Full Database export.</div>

            <button type="submit">Import &amp; Continue</button>
        </form>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // RECOVERY: Step R3 — Image Source Selection ?>
    <?php // ============================================================= ?>
    <?php if ($recovery_mode && $step === 'r3'): ?>
        <h2>Recovery — Locate Image Files</h2>

        <?php if (!empty($_SESSION['recovery_imported'])): ?>
            <div class="success-box">SQL dump imported successfully (<?php echo $_SESSION['recovery_imported']; ?> statements executed).</div>
        <?php endif; ?>

        <?php
        // Show a quick count of what's in the database
        try {
            $img_count = $pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn();
            $asset_count = 0;
            try { $asset_count = $pdo->query("SELECT COUNT(*) FROM snap_assets")->fetchColumn(); } catch (Exception $e) {}
        } catch (Exception $e) { $img_count = '?'; }
        ?>
        <p class="install-image-count-label">
            Found <strong class="install-count-highlight"><?php echo $img_count; ?></strong> image records
            <?php if ($asset_count > 0): ?> and <strong class="install-count-highlight"><?php echo $asset_count; ?></strong> media assets<?php endif; ?>
            in the database.
        </p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="step" value="r4">
            <input type="hidden" name="mode" value="recovery">

            <label class="install-radio-label">
                <input type="radio" name="image_mode" value="in_place" checked class="install-radio-input">
                <div>
                    <strong class="install-radio-text">Images are already in the correct directory structure</strong>
                    <div class="hint">Files are in <code>img_uploads/YYYY/MM/</code> with thumbs in subdirectories. Just verify and regenerate missing thumbs.</div>
                </div>
            </label>

            <label class="install-radio-label">
                <input type="radio" name="image_mode" value="flat" class="install-radio-input">
                <div>
                    <strong class="install-radio-text">All images are in one flat folder</strong>
                    <div class="hint">All image files were dumped into a single directory. The recovery engine will use the database paths to sort them into the correct <code>img_uploads/YYYY/MM/</code> structure.</div>
                </div>
            </label>

            <label for="flat_path" class="install-flat-path-label">Flat folder path (relative to site root)</label>
            <input type="text" id="flat_path" name="flat_path" placeholder="e.g. recovery/ or img_dumps/" value="">
            <div class="hint">Only needed if you selected "flat folder" above.</div>

            <button type="submit">Begin Restoration</button>
        </form>

    <?php endif; ?>


    <?php // ============================================================= ?>
    <?php // RECOVERY: Step R4 — Execute Restoration (streaming) ?>
    <?php // ============================================================= ?>
    <?php if ($recovery_mode && $step === 'r4_exec'): ?>
        <?php
        // Close the installer HTML wrapper and stream recovery progress directly
        echo '</div></body></html>';

        // Begin streaming output
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>SnapSmack Recovery</title>";
        echo "<style>body{background:#0e0e0e;color:#ccc;font-family:monospace;padding:30px;font-size:13px;line-height:1.7;}";
        echo ".success{color:#39FF14;} .info{color:#00bfff;} .warn{color:#ffaa00;} .error{color:#ff6b6b;}";
        echo "h2{color:#a0ff90;letter-spacing:2px;} h3{color:#eee;margin-top:24px;} hr{border-color:#333;margin:16px 0;}";
        echo ".summary{background:#1a1a1a;border:1px solid #333;padding:14px;margin:10px 0;border-radius:4px;}";
        echo "a{color:#a0ff90;}</style></head><body>";
        echo "<h2>SNAPSMACK RECOVERY ENGINE</h2><hr>";
        flush();

        // Ensure we have a PDO connection
        if (!isset($pdo)) {
            if (file_exists(__DIR__ . '/core/db.php')) {
                require_once __DIR__ . '/core/db.php';
            } else {
                echo "<span class='error'>ERROR:</span> No database connection available.<br>";
                echo "</body></html>";
                exit;
            }
        }

        require_once __DIR__ . '/core/recovery-engine.php';
        $engine = new SnapSmackRecovery($pdo, __DIR__);

        $image_mode = $_SESSION['recovery_image_mode'] ?? 'in_place';
        $flat_path  = $_SESSION['recovery_flat_path'] ?? '';
        $flat_dir   = ($image_mode === 'flat' && !empty($flat_path)) ? __DIR__ . '/' . ltrim($flat_path, '/') : null;

        // 1. Ensure directory structure
        echo "<h3>PHASE 1: DIRECTORY STRUCTURE</h3>";
        $engine->ensureDirectories();
        echo "<span class='success'>OK:</span> Upload directories verified.<br>";
        flush();

        // 2. Restore image files
        echo "<h3>PHASE 2: IMAGE FILES</h3>";
        $img_result = $engine->restoreImages($flat_dir);
        echo "<div class='summary'>";
        echo "Restored: {$img_result['restored']} | Already in place: {$img_result['in_place']} | Missing: {$img_result['missing']}";
        echo "</div>";
        flush();

        // 3. Regenerate thumbnails and compute checksums
        echo "<h3>PHASE 3: THUMBNAILS &amp; CHECKSUMS</h3>";
        $thumb_result = $engine->regenerateAndChecksum();
        echo "<div class='summary'>";
        echo "Regenerated: {$thumb_result['generated']} | Skipped: {$thumb_result['skipped']}";
        if (!empty($thumb_result['errors'])) {
            echo "<br>Errors: " . count($thumb_result['errors']);
        }
        echo "</div>";
        flush();

        // 4. Restore media assets
        echo "<h3>PHASE 4: MEDIA ASSETS</h3>";
        $asset_result = $engine->restoreMediaAssets($flat_dir);
        echo "<div class='summary'>";
        echo "Restored: {$asset_result['restored']} | In place: {$asset_result['in_place']} | Missing: {$asset_result['missing']}";
        echo "</div>";
        flush();

        // 5. Restore branding
        echo "<h3>PHASE 5: BRANDING</h3>";
        $brand_result = $engine->restoreBranding($flat_dir);
        echo "<div class='summary'>";
        echo "Restored: {$brand_result['restored']} | In place: {$brand_result['in_place']} | Missing: {$brand_result['missing']}";
        echo "</div>";
        flush();

        // Final summary
        $total_restored = $img_result['restored'] + $asset_result['restored'] + $brand_result['restored'];
        $total_missing  = $img_result['missing'] + $asset_result['missing'] + $brand_result['missing'];

        echo "<hr><h2 style='margin-top:20px;'>RECOVERY COMPLETE</h2>";
        echo "<div class='summary'>";
        echo "<span class='success'>Files restored: {$total_restored}</span><br>";
        echo "<span class='success'>Thumbnails regenerated: {$thumb_result['generated']}</span><br>";
        if ($total_missing > 0) {
            echo "<span class='warn'>Files still missing: {$total_missing}</span><br>";
        }
        echo "</div>";

        echo "<p style='margin-top:20px;'><a href='login.php' style='display:inline-block;padding:12px 30px;background:#a0ff90;color:#0e0e0e;text-decoration:none;font-weight:700;text-transform:uppercase;letter-spacing:1px;border-radius:3px;'>Log In</a></p>";
        echo "<p class='warn' style='margin-top:16px;'>Delete <code>install.php</code> from your server when you're done.</p>";
        echo "</body></html>";
        exit;
        ?>
    <?php endif; ?>

</div>
</body>
</html>
