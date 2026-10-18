<?php
// SNAPSMACK_EOF_HEADER
declare(strict_types=1);
$online = (int)tb_db()->query("SELECT COUNT(*) FROM troll_devices WHERE enabled=1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)")->fetchColumn();
$queued = (int)tb_db()->query("SELECT COUNT(*) FROM troll_jobs WHERE status IN ('queued','claimed') AND expires_at >= NOW()")->fetchColumn();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TROLL BOOTH</title><link rel="stylesheet" href="/style.css"></head>
<body><main><h1>TROLL BOOTH</h1><p>Nobody crosses without papers.</p>
<section class="panel"><h2>Relay status</h2>
<p class="status"><?= $online > 0 ? 'LOCAL BOOTH CONNECTED' : 'LOCAL BOOTH OFFLINE OR LOCKED' ?></p>
<p>Connected local devices: <?= $online ?></p><p>Active jobs: <?= $queued ?></p></section>
<section class="panel"><h2>Boundaries</h2><p>This relay accepts MCP requests, but performs no local operation itself.</p>
<p>The workstation connects outward, validates every capability locally, and stops when Emergency Lock is used.</p></section>
</main></body></html>
<?php // ===== SNAPSMACK EOF =====
