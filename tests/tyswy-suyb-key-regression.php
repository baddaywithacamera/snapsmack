<?php
/**
 * Regression guard (0.7.711D): the read-only export API accepts a 'suyb' key
 * as well as 'tyswy', so SMACK UP YOUR BACKUP can write an exit package with
 * the key it already holds. Nothing wider: no other key type, and no writes
 * to any content table (bookkeeping rows — site uuid, rate limits — are fine).
 */
$root = dirname(__DIR__);
$src  = file_get_contents($root . '/core/tyswy-api.php');

$n = preg_match_all("/key_type IN \\('tyswy', 'suyb'\\)/", $src);
if ($n < 2) { fwrite(STDERR, "tyswy_auth must accept key_type IN ('tyswy','suyb') on both query paths (found {$n})\n"); exit(1); }
if (preg_match("/key_type\\s*=\\s*'tyswy'/", $src)) { fwrite(STDERR, "a tyswy-only key check remains\n"); exit(1); }
if (preg_match("/key_type IN \\('tyswy', 'suyb', /", $src)) { fwrite(STDERR, "the export API must not accept a third key type\n"); exit(1); }

$content = ['snap_images', 'snap_posts', 'snap_pages', 'snap_comments', 'snap_albums',
            'snap_collections', 'snap_users', 'snap_community_comments'];
foreach ($content as $table) {
    if (preg_match('/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?' . $table . '\b/i', $src)) {
        fwrite(STDERR, "export API must not write {$table}\n"); exit(1);
    }
}
echo "TYSWY export API accepts suyb keys, stays read-only for content — regression checks passed.\n";
// ===== SNAPSMACK EOF =====
