<?php
/**
 * Static regression guard for the GRAMOFSMACK-only Pixelix adapter.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
$root = dirname(__DIR__);
$api = file_get_contents($root . '/pixelfed-api.php');
$ht  = file_get_contents($root . '/core/htaccess-template');
$ui  = file_get_contents($root . '/smack-post-gram.php');
$js  = file_get_contents($root . '/assets/js/ss-engine-gram-post.js');
$auth = file_get_contents($root . '/core/auth-smack.php');
$authoring = file_get_contents($root . '/core/gram-client-authoring.php');
$installer = file_get_contents($root . '/install.php');
$release = file_get_contents($root . '/smack-central/sc-release.php');

$checks = [
    'carousel mode gate'       => "px_setting(\$pdo,'site_mode','photoblog') !== 'carousel'",
    'owner offline gate'       => "px_setting(\$pdo,'gram_authoring_enabled','0') !== '1'",
    'OAuth client registration'=> "route==='api/v1/apps'",
    'media upload'             => "route==='api/v1/media'",
    'status creation'          => "route==='api/v1/statuses'",
    'ten-image ceiling'        => "count(\$ids)>10",
    'registration rate limit'  => "Too many OAuth client registrations",
    'query-string registration'=> "\$_REQUEST['client_name']",
    'JSON status payload'      => "application/json",
    'refresh-token grant'      => "grant_type']??'')==='refresh_token'",
    'refresh-token rotation'   => "refresh_token_hash",
    'absolute refresh expiry'  => "refresh_expires_at>NOW()",
    'media ownership'          => "JOIN snap_oauth_media",
    'scope enforcement'        => "px_require_scope",
    'Pixelix optimized media'  => "'optimized_url'",
    'Pixelix media licence'    => "'license'=>null",
    'Pixelix instance stats'   => "'stats'=>",
    'Pixelix video limit'      => "'video_size_limit'=>0",
    'separate ALT update'      => "method==='PUT'",
];
foreach ($checks as $name => $needle) {
    if (strpos($api, $needle) === false) { fwrite(STDERR, "Missing: {$name}\n"); exit(1); }
}
if (strpos($api, 'function px_schema') !== false || strpos($api, 'CREATE TABLE') !== false || strpos($api, 'ALTER TABLE') !== false) { fwrite(STDERR, "Runtime schema mutation returned\n"); exit(1); }
if (strpos($authoring, 'FOR UPDATE') === false || strpos($authoring, '300 images/hour') === false) { fwrite(STDERR, "Missing atomic authoring budget\n"); exit(1); }
if (strpos($ht, 'pixelfed-api.php?route=api/v$1/$2') === false) { fwrite(STDERR, "Missing client API rewrite\n"); exit(1); }
if (strpos($installer, 'pixelfed-api.php?route=api/v$1/$2') === false || strpos($installer, 'pixelfed-api.php?route=oauth/$1') === false) { fwrite(STDERR, "Installer-generated .htaccess is missing client routes\n"); exit(1); }
if (strpos($release, 'Tagged source is missing required Pixelix runtime file') === false || strpos($release, 'Tagged htaccess template is missing required Pixelix API/OAuth routes') === false) { fwrite(STDERR, "Release builder does not reject incomplete Pixelix tags\n"); exit(1); }
if (strpos($ui, 'ss-gram-pwa-composer.css') === false) { fwrite(STDERR, "Missing GRAM composer stylesheet\n"); exit(1); }
if (strpos($ui, 'gram-composer-form') === false || strpos($ui, 'gram-advanced') === false) { fwrite(STDERR, "Missing structured PWA composer\n"); exit(1); }
if (strpos($js, 'cp-order-btn') === false) { fwrite(STDERR, "Missing touch/keyboard reorder controls\n"); exit(1); }
if (strpos($auth, 'snapsmack_oauth_return') === false) { fwrite(STDERR, "OAuth consent cannot survive login\n"); exit(1); }
echo "Pixelix compatibility regression checks passed.\n";
// ===== SNAPSMACK EOF =====
