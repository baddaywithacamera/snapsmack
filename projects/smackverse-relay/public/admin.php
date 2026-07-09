<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * SMACKVERSE Relay — operator console. Token-gated (config admin_token). Lists
 * subscribers, approves pending, blocks/unblocks domains, manages the allowlist,
 * and flips open mode. No public UI, no user accounts.
 */

require_once __DIR__ . '/../lib/relay.php';

$cfg    = relay_config();
$secret = (string)($cfg['admin_token'] ?? '');
$token  = (string)($_REQUEST['token'] ?? '');
$authed = $secret !== '' && $secret !== 'CHANGE_ME_TO_A_LONG_RANDOM_STRING'
          && hash_equals($secret, $token);
if (!$authed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Append ?token=YOUR_ADMIN_TOKEN (set admin_token in config.php first).\n";
    exit;
}
relay_ensure_schema();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $r  = relay_db()->prepare("SELECT * FROM relay_subscribers WHERE id = ? LIMIT 1");
        $r->execute([$id]);
        $row = $r->fetch();
        if ($row && $row['state'] === 'pending') {
            relay_db()->prepare("UPDATE relay_subscribers SET state='active' WHERE id=?")->execute([$id]);
            // Tell their server we accepted the relay follow.
            $accept = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id'       => relay_actor_url() . '#accept-' . bin2hex(random_bytes(8)),
                'type'     => 'Accept',
                'actor'    => relay_actor_url(),
                'object'   => [
                    'id'     => $row['follow_id'] ?: ($row['actor_url'] . '#follow'),
                    'type'   => 'Follow',
                    'actor'  => $row['actor_url'],
                    'object' => RELAY_PUBLIC,
                ],
            ];
            relay_queue($row['inbox_url'], json_encode($accept, JSON_UNESCAPED_SLASHES));
            relay_drain(10);
            $msg = 'Approved ' . htmlspecialchars($row['domain']) . '.';
        }
    } elseif ($do === 'block') {
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        if ($domain !== '') {
            relay_db()->prepare("INSERT INTO relay_blocklist (domain, reason) VALUES (?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)")
                ->execute([$domain, substr((string)($_POST['reason'] ?? ''), 0, 255)]);
            relay_db()->prepare("UPDATE relay_subscribers SET state='blocked' WHERE domain = ?")->execute([$domain]);
            $msg = 'Blocked ' . htmlspecialchars($domain) . '.';
        }
    } elseif ($do === 'unblock') {
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        relay_db()->prepare("DELETE FROM relay_blocklist WHERE domain = ?")->execute([$domain]);
        $msg = 'Unblocked ' . htmlspecialchars($domain) . '. (Re-approve the subscriber to re-activate.)';
    } elseif ($do === 'allow_add') {
        $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
        if ($domain !== '') {
            relay_db()->prepare("INSERT INTO relay_allowlist (domain, note) VALUES (?, ?) ON DUPLICATE KEY UPDATE note=VALUES(note)")
                ->execute([$domain, substr((string)($_POST['note'] ?? ''), 0, 255)]);
            // Auto-activate any pending subscriber now covered by the allowlist.
            relay_db()->prepare("UPDATE relay_subscribers SET state='active' WHERE domain=? AND state='pending'")->execute([$domain]);
            $msg = 'Allowlisted ' . htmlspecialchars($domain) . '.';
        }
    } elseif ($do === 'allow_remove') {
        relay_db()->prepare("DELETE FROM relay_allowlist WHERE domain = ?")->execute([strtolower(trim((string)($_POST['domain'] ?? '')))]);
        $msg = 'Removed from allowlist.';
    } elseif ($do === 'setmode') {
        $mode = ($_POST['mode'] ?? 'allowlist') === 'open' ? 'open' : 'allowlist';
        relay_set('open_mode', $mode);
        $msg = 'Mode set to ' . $mode . '.';
    }
}

$mode      = relay_setting('open_mode', 'allowlist');
$pending   = relay_db()->query("SELECT * FROM relay_subscribers WHERE state='pending' ORDER BY subscribed_at DESC")->fetchAll();
$active    = relay_db()->query("SELECT * FROM relay_subscribers WHERE state='active'  ORDER BY subscribed_at DESC")->fetchAll();
$blocked   = relay_db()->query("SELECT domain, reason FROM relay_blocklist ORDER BY blocked_at DESC")->fetchAll();
$allow     = relay_db()->query("SELECT domain, note FROM relay_allowlist ORDER BY domain ASC")->fetchAll();
$log       = relay_db()->query("SELECT * FROM relay_inbox_log ORDER BY id DESC LIMIT 40")->fetchAll();
$q_queued  = (int)relay_db()->query("SELECT COUNT(*) FROM relay_deliveries WHERE status='queued'")->fetchColumn();
$q_failed  = (int)relay_db()->query("SELECT COUNT(*) FROM relay_deliveries WHERE status='failed'")->fetchColumn();
$t = htmlspecialchars($token);
?><!doctype html>
<html><head><meta charset="utf-8"><title>SMACKVERSE Relay — operator</title>
<style>
body{font-family:system-ui,sans-serif;background:#0b1015;color:#dde;margin:0;padding:24px;}
h1{color:#4fd;font-size:1.4rem} h2{color:#4fd;font-size:1rem;margin-top:28px;border-bottom:1px solid #234;padding-bottom:4px}
table{border-collapse:collapse;width:100%;margin-top:8px} td,th{padding:6px 10px;border-bottom:1px solid #223;text-align:left;font-size:.85rem}
input,button{font:inherit;padding:5px 8px;background:#131c24;color:#dde;border:1px solid #345;border-radius:4px}
button{cursor:pointer} .msg{background:#123;padding:10px;border-radius:6px;margin:12px 0}
.pill{padding:2px 8px;border-radius:10px;font-size:.72rem} .on{background:#052;color:#5f9} .off{background:#411;color:#f88}
form.inline{display:inline}
</style></head><body>
<h1>SMACKVERSE Relay — operator console</h1>
<p>Mode: <span class="pill <?php echo $mode==='open'?'off':'on'; ?>"><?php echo strtoupper($mode); ?></span>
   &nbsp; Queue: <?php echo $q_queued; ?> queued, <?php echo $q_failed; ?> failed.</p>
<?php if ($msg): ?><div class="msg"><?php echo $msg; ?></div><?php endif; ?>

<h2>Mode</h2>
<form method="post">
  <input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="setmode">
  <button name="mode" value="allowlist"<?php echo $mode==='allowlist'?' disabled':''; ?>>ALLOWLIST (approve each site)</button>
  <button name="mode" value="open"<?php echo $mode==='open'?' disabled':''; ?>>OPEN (anyone joins, block after)</button>
</form>

<h2>Pending approval (<?php echo count($pending); ?>)</h2>
<?php if (!$pending): ?><p>None.</p><?php else: ?>
<table><tr><th>Domain</th><th>Actor</th><th>When</th><th></th></tr>
<?php foreach ($pending as $r): ?>
<tr><td><?php echo htmlspecialchars($r['domain']); ?></td>
    <td><?php echo htmlspecialchars($r['actor_url']); ?></td>
    <td><?php echo htmlspecialchars($r['subscribed_at']); ?></td>
    <td><form class="inline" method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button>APPROVE</button></form>
        <form class="inline" method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="block"><input type="hidden" name="domain" value="<?php echo htmlspecialchars($r['domain']); ?>"><button>BLOCK</button></form></td></tr>
<?php endforeach; ?></table>
<?php endif; ?>

<h2>Active subscribers (<?php echo count($active); ?>)</h2>
<?php if (!$active): ?><p>None yet.</p><?php else: ?>
<table><tr><th>Domain</th><th>Actor</th><th></th></tr>
<?php foreach ($active as $r): ?>
<tr><td><?php echo htmlspecialchars($r['domain']); ?></td>
    <td><?php echo htmlspecialchars($r['actor_url']); ?></td>
    <td><form class="inline" method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="block"><input type="hidden" name="domain" value="<?php echo htmlspecialchars($r['domain']); ?>"><button>BLOCK</button></form></td></tr>
<?php endforeach; ?></table>
<?php endif; ?>

<h2>Allowlist (auto-admit)</h2>
<form method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="allow_add">
  <input name="domain" placeholder="blog.example.com"> <input name="note" placeholder="note (optional)"> <button>ADD</button></form>
<?php if ($allow): ?><table><tr><th>Domain</th><th>Note</th><th></th></tr>
<?php foreach ($allow as $r): ?>
<tr><td><?php echo htmlspecialchars($r['domain']); ?></td><td><?php echo htmlspecialchars((string)$r['note']); ?></td>
    <td><form class="inline" method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="allow_remove"><input type="hidden" name="domain" value="<?php echo htmlspecialchars($r['domain']); ?>"><button>REMOVE</button></form></td></tr>
<?php endforeach; ?></table><?php endif; ?>

<h2>Blocklist</h2>
<form method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="block">
  <input name="domain" placeholder="spam.example"> <input name="reason" placeholder="reason"> <button>BLOCK</button></form>
<?php if ($blocked): ?><table><tr><th>Domain</th><th>Reason</th><th></th></tr>
<?php foreach ($blocked as $r): ?>
<tr><td><?php echo htmlspecialchars($r['domain']); ?></td><td><?php echo htmlspecialchars((string)$r['reason']); ?></td>
    <td><form class="inline" method="post"><input type="hidden" name="token" value="<?php echo $t; ?>"><input type="hidden" name="do" value="unblock"><input type="hidden" name="domain" value="<?php echo htmlspecialchars($r['domain']); ?>"><button>UNBLOCK</button></form></td></tr>
<?php endforeach; ?></table><?php endif; ?>

<h2>Recent inbound (last 40)</h2>
<table><tr><th>When</th><th>Verb</th><th>Actor</th><th>Outcome</th></tr>
<?php foreach ($log as $r): ?>
<tr><td><?php echo htmlspecialchars((string)$r['received_at']); ?></td><td><?php echo htmlspecialchars((string)$r['verb']); ?></td>
    <td><?php echo htmlspecialchars((string)$r['actor_url']); ?></td><td><?php echo htmlspecialchars((string)$r['outcome']); ?></td></tr>
<?php endforeach; ?></table>
</body></html>
<?php // ===== SNAPSMACK EOF =====
