<?php
/** Static regression guard for the authenticated migration boundary. */
$root = dirname(__DIR__);
$api = file_get_contents($root . '/core/smackpress-api.php');
if ($api === false) {
    fwrite(STDERR, "Could not read SmackPress API.\n");
    exit(1);
}
$needles = [
    'function smackpress_reject_migration_residue',
    'function smackpress_import_date',
    "setting_key='timezone'",
    'smackpress_reject_migration_residue((string)$raw_content)',
    'smackpress_reject_migration_residue((string)$raw_colophon)',
    'SELECT id,user_id FROM snap_ohsnap_keys',
    "This import key is not bound to a system user",
    'featured_image_id=?, user_id=?',
    'featured_image_id,user_id',
    "if (\$sub === 'pages' && \$method === 'GET')",
    "SELECT id,title,slug,is_active,created_at FROM snap_pages",
    '$stmt = $pdo->prepare(',
];
foreach ($needles as $needle) {
    if (strpos($api, $needle) === false) {
        fwrite(STDERR, "Missing migration-boundary control: {$needle}\n");
        exit(1);
    }
}
echo "SmackPress import boundary regression: PASS\n";
