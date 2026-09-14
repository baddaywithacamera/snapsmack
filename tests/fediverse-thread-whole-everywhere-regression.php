<?php
/**
 * OPAUDIT 015 — one photograph, three places, three different comment counts.
 *
 *   allinthewrist (source)   4 comments
 *   pixelfed.social          2 (missing a pre-708D local comment + a reply
 *                               from another SnapSmack site)
 *   unzucked FEDIVERSE reader 0 (the reader only knew the Mastodon API)
 *
 * Static + pure-function contract for the three fixes in 0.7.712D:
 *   1. every content Note advertises `replies`, and /ap/note/{i|p|l}/N/replies
 *      serves the thread (remote ids + our local Notes);
 *   2. the reader falls back to the AP `replies` collection when the origin has
 *      no Mastodon API;
 *   3. pre-708D blog comments are backfilled once per version, and inbound
 *      replies to our posts are forwarded to our followers (§7.1.2), with
 *      forwarded activities accepted only when verified from their origin.
 */
$root = dirname(__DIR__);
$core = file_get_contents($root . '/core/fediverse.php');
$rtr  = file_get_contents($root . '/fediverse.php');
$cron = file_get_contents($root . '/cron-fediverse.php');
$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $fail++;
};

// 1. replies collection
$check('image Note advertises replies',    substr_count($core, "'replies'      => preg_replace('/~\\d+\$/', '', \$note_id) . '/replies'") === 3);
$check('router accepts /ap/note/x/N/replies', str_contains($rtr, "if ((\$seg[3] ?? '') === 'replies') \$_GET['replies'] = '1';"));
$check('router serves the collection',     str_contains($rtr, 'sv_replies_collection($pdo, (string)($note[\'id\'] ?? \'\'), $settings)'));
$check('collection lists remote ids, not our words for them', str_contains($core, "if ((\$c['ap_source'] ?? 'local') === 'fediverse') {\n            \$rid = trim((string)(\$c['ap_object_id'] ?? ''));"));
$check('collection embeds our local comment Notes', str_contains($core, "\$note = sv_note_for_comment(\$pdo, \$c, \$settings);\n        if (\$note !== null) { unset(\$note['@context']); \$items[] = \$note; }"));
$check('collection is approved-only',      str_contains($core, "WHERE is_approved = 1 AND is_spam = 0 AND ("));

// 2. reader fallback
$check('reader falls back to AP replies',  str_contains($core, "if (!is_array(\$ctx) || empty(\$ctx['descendants'])) return sv_ap_replies_items(\$object_url);"));
$check('AP reader follows first page',     str_contains($core, "if (\$items === null && !empty(\$coll['first']))"));
$check('AP reader dereferences bare ids',  str_contains($core, "\$obj = is_array(\$it) ? \$it : (is_string(\$it) ? sv_fetch_ap(\$it) : null);"));

// 3. backfill + forwarding
$check('backfill exists and keeps original dates', str_contains($core, 'function sv_backfill_community_comments(') && str_contains($core, "(string)(\$r['created_at'] ?: date('Y-m-d H:i:s'))"));
$check('backfill skips already-mirrored', str_contains($core, "if (\$exists->fetchColumn()) { \$skipped++; continue; }"));
$check('backfill runs once per version from cron', str_contains($cron, 'sv_backfill_community_comments_once($pdo, $settings);'));
$check('inbound reply is forwarded to followers', str_contains($core, 'sv_forward_to_followers($pdo, $activity, $actor_id);'));
$check('forwarding skips the replier\'s own host', str_contains($core, "if (\$h === '' || \$h === \$skip_host) continue;"));
$check('forwarded Create verified from origin, else 401', str_contains($core, 'sv_verify_forwarded_create($activity, (string)$act_actor, $signed_by)')
    && str_contains($core, "if (\$attr !== \$act_actor) return null;"));
$check('signer marker never leaves the handler', str_contains($core, "unset(\$activity['_signed_by']);"));
$check('button on Push & Tools', str_contains(file_get_contents($root . '/smack-sv-tools.php'), 'value="backfill_blog_comments"'));

// pure: forwarded-create verifier refuses cross-host claims without a fetch
require_once $root . '/core/fediverse.php';
$check('object on a different host than the actor is refused',
    sv_verify_forwarded_create(['type' => 'Create', 'actor' => 'https://a.example/ap/actor', 'object' => ['id' => 'https://b.example/note/1']], 'https://a.example/ap/actor') === null);
$check('non-Create is refused',
    sv_verify_forwarded_create(['type' => 'Like', 'actor' => 'https://a.example/ap/actor', 'object' => 'https://a.example/note/1'], 'https://a.example/ap/actor') === null);

if ($fail) { fwrite(STDERR, "{$fail} check(s) failed.\n"); exit(1); }
echo "ALL PASS — the thread under a photograph is served, read, backfilled and forwarded.\n";
// ===== SNAPSMACK EOF =====
