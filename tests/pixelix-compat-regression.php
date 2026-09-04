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
$lifecycle = file_get_contents($root . '/core/pixelix-lifecycle.php');
$installer = file_get_contents($root . '/install.php');
$release = file_get_contents($root . '/smack-central/sc-release.php');

$checks = [
    'all-mode connection'      => "function px_mode(PDO \$pdo)",
    'GRAM write boundary'      => "function px_gram_authoring_gate(PDO \$pdo)",
    'SMACKONEOUT timeline'     => "SELECT id FROM snap_images WHERE img_status='published'",
    'SMACKTALK timeline'       => "post_type='longform'",
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
    'public cover permalink'   => "\$publicUrl",
    'exclusive max-id paging' => "if(\$maxId>0){\$sql.=' AND id<?'",
    'Pixelix media licence'    => "'license'=>null",
    'Pixelix instance stats'   => "'stats'=>",
    'Pixelix video limit'      => "'video_size_limit'=>0",
    'separate ALT update'      => "method==='PUT'",
    'Pixelix settings'         => "'hide_collections'=>true",
    'Pixelix account alias'    => "api/pixelfed/v1/accounts/1",
    'followers list endpoint'  => "accounts/1/(followers|following)",
    'real follower rows'       => "snap_ap_followers f",
    'real following rows'      => "snap_ap_following f",
    'accepted follows only'    => "f.state='accepted'",
    'location search fallback' => "api/v1.1/compose/search/location",
    'collections fallback'     => "api/v1\\.1/collections/accounts/1",
    'federated DM conversations'=> "route==='api/v1/conversations'",
    'conversation unread state'=> "direction='in' AND is_read=0",
    'conversation mark read'   => "conversations/([a-f0-9]{24})/read",
    'published-only status'    => "status='published'",
    'fail-closed token expiry' => "t.token_expires_at>NOW()",
    'locked code redemption'   => "LIMIT 1 FOR UPDATE",
    'conditional code redeem'  => "authorization_code_hash=? AND token_hash IS NULL",
    'conditional refresh'      => "refresh_token_hash=? AND revoked_at IS NULL",
    'single-winner exchange'   => "rowCount()!==1",
];
foreach ($checks as $name => $needle) {
    if (strpos($api, $needle) === false) { fwrite(STDERR, "Missing: {$name}\n"); exit(1); }
}
if (strpos($api, 'function px_schema') !== false || strpos($api, 'CREATE TABLE') !== false || strpos($api, 'ALTER TABLE') !== false) { fwrite(STDERR, "Runtime schema mutation returned\n"); exit(1); }
if (strpos($api, 'token_expires_at IS NULL') !== false) { fwrite(STDERR, "Null-expiry bearer still fails open\n"); exit(1); }
foreach (["SNAP_PIXELIX_DRAFT_RETENTION_DAYS = 7", "FOR UPDATE", "post_id IS NULL", "snap_api_safe_upload_path", "dry_run"] as $needle) {
    if (strpos($lifecycle, $needle) === false) { fwrite(STDERR, "Missing lifecycle control: {$needle}\n"); exit(1); }
}
if (strpos($authoring, 'FOR UPDATE') === false || strpos($authoring, '300 images/hour') === false) { fwrite(STDERR, "Missing atomic authoring budget\n"); exit(1); }
if (strpos($ht, 'pixelfed-api.php?route=api/v$1/$2') === false) { fwrite(STDERR, "Missing client API rewrite\n"); exit(1); }
if (strpos($ht, 'pixelfed-api.php?route=api/v1.1/$1') === false || strpos($ht, 'pixelfed-api.php?route=api/pixelfed/v1/$1') === false) { fwrite(STDERR, "Missing Pixelix extension rewrites\n"); exit(1); }
if (strpos($installer, 'pixelfed-api.php?route=api/v$1/$2') === false || strpos($installer, 'pixelfed-api.php?route=oauth/$1') === false) { fwrite(STDERR, "Installer-generated .htaccess is missing client routes\n"); exit(1); }
if (strpos($installer, 'pixelfed-api.php?route=api/v1.1/$1') === false || strpos($installer, 'pixelfed-api.php?route=api/pixelfed/v1/$1') === false) { fwrite(STDERR, "Installer-generated .htaccess is missing Pixelix extension routes\n"); exit(1); }
if (strpos($release, 'Tagged source is missing required Pixelix runtime file') === false || strpos($release, 'Tagged htaccess template is missing required Pixelix API/OAuth routes') === false) { fwrite(STDERR, "Release builder does not reject incomplete Pixelix tags\n"); exit(1); }
if (strpos($ui, 'ss-gram-pwa-composer.css') === false) { fwrite(STDERR, "Missing GRAM composer stylesheet\n"); exit(1); }
if (strpos($ui, 'gram-composer-form') === false || strpos($ui, 'gram-advanced') === false) { fwrite(STDERR, "Missing structured PWA composer\n"); exit(1); }
if (strpos($js, 'cp-order-btn') === false) { fwrite(STDERR, "Missing touch/keyboard reorder controls\n"); exit(1); }
if (strpos($auth, 'snapsmack_oauth_return') === false) { fwrite(STDERR, "OAuth consent cannot survive login\n"); exit(1); }
echo "Pixelix compatibility regression checks passed.\n";
// ===== SNAPSMACK EOF =====
