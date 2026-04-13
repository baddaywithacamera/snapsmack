<?php
/**
 * SNAPSMACK - Migration Runner
 *
 * Standalone admin tool for applying schema sync and pending SQL migrations
 * without SSH or phpMyAdmin access. FTP this file to the site root, load it
 * in a browser while logged into the admin, then delete it when done.
 *
 * SECURITY: Requires an active admin session. Will not run unauthenticated.
 * DELETE THIS FILE after use — it should not live on the server permanently.
 */

require_once 'core/auth.php';        // Provides $pdo and session guard
require_once 'core/schema-sync.php'; // Provides snap_schema_sync()
require_once 'core/updater.php';     // Provides updater_find_migrations() / updater_run_migrations()

// Auth check — auth.php redirects to login if no session, but double-check.
if (empty($_SESSION['snap_user'])) {
    http_response_code(403);
    exit('Not authorised.');
}

$ran    = false;
$schema = [];
$migs   = ['success' => true, 'applied' => [], 'errors' => []];
$pending_files = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;

    // 1. Schema sync — creates missing tables, adds missing columns/indexes.
    $schema = snap_schema_sync($pdo);

    // 2. Pending data migrations.
    $pending_files = updater_find_migrations($pdo);
    if (!empty($pending_files)) {
        $migs = updater_run_migrations($pdo, $pending_files);
    }
}

// ─── HTML OUTPUT ─────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SnapSmack — Migration Runner</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body {
      margin: 0;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #0e0e0e;
      color: #ccc;
      padding: 40px 20px;
  }
  .wrap { max-width: 760px; margin: 0 auto; }
  h1 { font-size: 1.1rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #fff; margin: 0 0 6px; }
  .subtitle { font-size: 0.78rem; color: #666; margin: 0 0 32px; letter-spacing: 0.5px; }
  .warning {
      background: #2a1a00;
      border: 1px solid #ff8c00;
      border-radius: 6px;
      padding: 14px 18px;
      font-size: 0.82rem;
      color: #ffa940;
      margin-bottom: 28px;
      line-height: 1.6;
  }
  .warning strong { color: #ffb347; }
  .run-btn {
      display: inline-block;
      padding: 12px 28px;
      background: #4a9eff;
      color: #fff;
      border: none;
      border-radius: 5px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      text-transform: uppercase;
      cursor: pointer;
      margin-bottom: 36px;
  }
  .run-btn:hover { background: #3a8eef; }
  .section { margin-bottom: 32px; }
  .section-title {
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      color: #888;
      margin: 0 0 12px;
      padding-bottom: 6px;
      border-bottom: 1px solid #222;
  }
  .result-list { list-style: none; margin: 0; padding: 0; }
  .result-list li {
      padding: 7px 12px;
      font-size: 0.8rem;
      border-radius: 4px;
      margin-bottom: 4px;
      font-family: 'Menlo', 'Consolas', monospace;
  }
  .ok   { background: #0d2a0d; color: #5dba5d; }
  .skip { background: #1a1a1a; color: #555; }
  .err  { background: #2a0d0d; color: #d45; }
  .none { color: #444; font-size: 0.8rem; font-style: italic; }
  .summary {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 32px;
  }
  .pill {
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
  }
  .pill-green { background: #0d2a0d; color: #5dba5d; border: 1px solid #1e4a1e; }
  .pill-red   { background: #2a0d0d; color: #d45;    border: 1px solid #4a1e1e; }
  .pill-grey  { background: #1a1a1a; color: #555;    border: 1px solid #2a2a2a; }
  .delete-reminder {
      margin-top: 40px;
      padding: 14px 18px;
      background: #1a0d1a;
      border: 1px solid #6a3a6a;
      border-radius: 6px;
      font-size: 0.8rem;
      color: #c090c0;
  }
</style>
</head>
<body>
<div class="wrap">

  <h1>SnapSmack — Migration Runner</h1>
  <p class="subtitle">Schema sync + pending data migrations &mdash; safe to run multiple times</p>

  <div class="warning">
    <strong>Delete this file when you're done.</strong> It is protected by your admin session,
    but leaving deployment tools on a production server is bad practice.
  </div>

<?php if (!$ran): ?>

  <form method="post">
    <input type="hidden" name="action" value="run">
    <button type="submit" class="run-btn">Run Schema Sync &amp; Migrations</button>
  </form>

  <p style="font-size:0.8rem;color:#555;margin-top:-20px;">
    This will: (1) create any missing tables, (2) add any missing columns, (3) apply any pending migration files.
    Nothing will be dropped or overwritten.
  </p>

<?php else: ?>

  <?php
    $col_count  = count($schema['columns_added'] ?? []);
    $skip_count = count($schema['skipped'] ?? []);
    $err_count  = count($schema['errors'] ?? []) + count($migs['errors'] ?? []);
    $mig_count  = count($migs['applied'] ?? []);
  ?>

  <div class="summary">
    <span class="pill pill-green"><?= $col_count ?> column<?= $col_count !== 1 ? 's' : '' ?> added</span>
    <span class="pill pill-green"><?= $mig_count ?> migration<?= $mig_count !== 1 ? 's' : '' ?> applied</span>
    <span class="pill pill-grey"><?= $skip_count ?> already present</span>
    <?php if ($err_count > 0): ?>
    <span class="pill pill-red"><?= $err_count ?> error<?= $err_count !== 1 ? 's' : '' ?></span>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-title">Schema — Columns Added</div>
    <?php if (empty($schema['columns_added'])): ?>
      <p class="none">No columns needed adding.</p>
    <?php else: ?>
      <ul class="result-list">
        <?php foreach ($schema['columns_added'] as $item): ?>
          <li class="ok">&#10003; <?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-title">Schema — Already Present (skipped)</div>
    <?php if (empty($schema['skipped'])): ?>
      <p class="none">Nothing to skip.</p>
    <?php else: ?>
      <ul class="result-list">
        <?php foreach ($schema['skipped'] as $item): ?>
          <li class="skip">&#8212; <?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-title">Data Migrations Applied</div>
    <?php if (empty($migs['applied'])): ?>
      <p class="none">No pending migrations.</p>
    <?php else: ?>
      <ul class="result-list">
        <?php foreach ($migs['applied'] as $f): ?>
          <li class="ok">&#10003; <?= htmlspecialchars(basename($f)) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <?php if (!empty($schema['errors']) || !empty($migs['errors'])): ?>
  <div class="section">
    <div class="section-title">Errors</div>
    <ul class="result-list">
      <?php foreach (array_merge($schema['errors'] ?? [], $migs['errors'] ?? []) as $e): ?>
        <li class="err">&#10007; <?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="run">
    <button type="submit" class="run-btn" style="background:#555;">Run Again</button>
  </form>

<?php endif; ?>

  <div class="delete-reminder">
    <strong>Reminder:</strong> Delete <code>smack-migrate-runner.php</code> from your server when finished.
  </div>

</div>
</body>
</html>
