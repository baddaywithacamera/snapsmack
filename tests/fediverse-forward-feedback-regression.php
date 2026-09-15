<?php
/**
 * 718D "FEEDBACK": one comment must never become 277,000 deliveries.
 *
 * 2026-09-15. Comment #1 on usedcarparts.photoblogs.fyi (a reply to that
 * site's image 388) was received by every fleet site; each resolved
 * /ap/note/i/388 against ITS OWN images, filed the comment on the wrong
 * photograph, and forwarded it to all its followers as "a reply to our post"
 * (712D inbox forwarding). Every receiver did the same. Sean: "microphone
 * audio feedback, fediverse version." Three rules end it:
 *   1. a URL names one of OUR objects only when it is on OUR host;
 *   2. only the origin forwards — a copy that arrived as a forward never
 *      forwards again;
 *   3. one forward per object per inbox (dedupe key).
 */
$root = dirname(__DIR__);
$fedi = file_get_contents($root . '/core/fediverse.php');
$inbox = substr($fedi, strpos($fedi, 'function sv_handle_inbox'),
    strpos($fedi, 'function sv_bio_html') - strpos($fedi, 'function sv_handle_inbox'));
$forward = substr($fedi, strpos($fedi, 'function sv_forward_to_followers'),
    strpos($fedi, 'function sv_handle_inbox') - strpos($fedi, 'function sv_forward_to_followers'));

$checks = [
    'own-host helper exists' => str_contains($fedi, 'function sv_url_is_ours(string $url, array $settings): bool'),
    'helper compares against sv_domain' => str_contains($fedi, "return \$h !== '' && \$h === strtolower(sv_domain(\$settings));"),
    'reply target must be on our host' => str_contains($inbox, '$target = ($in_reply !== \'\' && sv_url_is_ours($in_reply, $settings))'),
    'like target must be on our host' => str_contains($inbox, 'sv_url_is_ours($liked, $settings)) ? sv_resolve_target($liked, $pdo)'),
    'boost target must be on our host' => str_contains($inbox, 'sv_url_is_ours($boosted, $settings) ? sv_resolve_target($boosted, $pdo)'),
    'undo target must be on our host' => str_contains($inbox, 'sv_url_is_ours($obj, $settings)) ? sv_resolve_target($obj, $pdo)'),
    'delete target must be on our host' => str_contains($inbox, 'sv_url_is_ours($obj_id, $settings) ? sv_resolve_target($obj_id, $pdo)'),
    'no unguarded resolve_target left in the inbox' => substr_count($inbox, 'sv_resolve_target($') === substr_count($inbox, 'sv_url_is_ours('),
    'a forwarded copy is never forwarded again' => str_contains($inbox, "&& empty(\$actor_doc['_forwarded_by'])) {\n                sv_forward_to_followers("),
    'forward dedupes per object per inbox' => str_contains($forward, "'fwd:' . substr(hash('sha256', \$obj_id . '|' . \$inbox), 0, 60)")
        && str_contains($forward, 'sv_queue_delivery($pdo, $inbox, $json, $dedupe);'),
    'forward still skips the replier host' => str_contains($forward, "if (\$h === '' || \$h === \$skip_host) continue;"),
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: a reply to someone else's post is not ours, and a forward is one hop.\n";
// ===== SNAPSMACK EOF =====
