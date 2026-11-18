<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE HUB CAN PUSH THE PAGE CACHE, AND A PUSHED CHANGE DOES NOT SERVE STALE
 * PAGES.
 *
 * Sean, 2026-11-17: the page cache "should be" a setting the hub can push to
 * spokes. It existed (core/page-cache.php, cache_enabled default 0, cache_ttl
 * default 300) and was in no push group, so every site had to be visited one
 * at a time -- which on a 28-site fleet means it stayed off.
 *
 * THREE THINGS HAVE TO HOLD TOGETHER, and each has a way of failing quietly:
 *
 *  1. The group exists on the hub and the spoke ACCEPTS exactly its keys. An
 *     allowlist that misses one key drops it silently into 'skipped', and the
 *     hub reports a successful push of a setting that never arrived.
 *
 *  2. cache_dev_until is NOT pushable. Developer mode is a local, temporary
 *     bypass someone sets while working on one site. Pushing it would either
 *     strand a site in bypass or cancel a bypass in use, and neither is the
 *     hub's business.
 *
 *  3. A pushed change PURGES the spoke's stored pages. Turning caching off
 *     while the old files sit on disk means page-cache.php keeps serving them
 *     until each ages out on the OLD ttl -- so the owner turns it off from the
 *     hub, watches the fleet keep serving yesterday's pages, and concludes the
 *     switch does nothing. That is the failure this test exists for.
 *
 * SOURCE ASSERTIONS, and they say so: the push endpoint needs a hub, a node
 * row, an authenticated request and a database. What source can prove is the
 * shape -- which keys are offered, which are accepted, which are refused, and
 * that the purge is reachable from the branch that applies them.
 */

$root = dirname(__DIR__);
$failures = [];

$hub = (string)@file_get_contents($root . '/smack-push-it.php');
$api = (string)@file_get_contents($root . '/core/multisite-api.php');
if ($hub === '' || $api === '') {
    throw new RuntimeException('could not read the push page or the multisite API');
}

// ── 1. THE GROUP ON THE HUB ──────────────────────────────────────────────
if (!preg_match("/'cache'\s*=>\s*\[([^\]]*)\]/", $hub, $m)) {
    $failures[] = "smack-push-it.php has no 'cache' push group, so the page "
        . 'cache is back to being set one site at a time';
    $groupKeys = [];
} else {
    $groupKeys = array_values(array_filter(array_map(
        static fn($k) => trim($k, " \t\n'\""),
        explode(',', $m[1])
    )));
}
foreach (['cache_enabled', 'cache_ttl', 'hub_controls_cache'] as $need) {
    if (!in_array($need, $groupKeys, true)) {
        $failures[] = "the cache push group does not include {$need}";
    }
}
if (in_array('cache_dev_until', $groupKeys, true)) {
    $failures[] = 'the cache push group includes cache_dev_until. Developer '
        . 'mode is a local, temporary bypass; pushing it either strands a site '
        . 'in bypass or cancels one someone is using.';
}
// Nothing ELSE either: a group that grows by accident pushes settings nobody
// chose to share.
foreach ($groupKeys as $k) {
    if (!in_array($k, ['cache_enabled', 'cache_ttl', 'hub_controls_cache'], true)) {
        $failures[] = "the cache push group carries an unexpected key '{$k}'";
    }
}

// ── 2. THE SPOKE ACCEPTS EXACTLY THOSE KEYS ──────────────────────────────
// Read the allowlist itself, not the whole file: 'cache_enabled' appears in
// page-cache logic too, so a file-wide search would pass with the allowlist
// untouched.
$allowAt = strpos($api, '$allowed_keys = [');
$allowEnd = $allowAt === false ? false : strpos($api, '];', $allowAt);
if ($allowAt === false || $allowEnd === false) {
    $failures[] = 'the spoke-side allowlist could not be isolated';
    $allowList = '';
} else {
    $allowList = substr($api, $allowAt, $allowEnd - $allowAt);
}
foreach (['cache_enabled', 'cache_ttl', 'hub_controls_cache'] as $need) {
    if ($allowList !== '' && !str_contains($allowList, "'" . $need . "'")) {
        $failures[] = "the spoke does not accept {$need}, so the hub would "
            . 'report a successful push of a setting that silently landed in '
            . "'skipped'";
    }
}
if ($allowList !== '' && str_contains($allowList, "'cache_dev_until'")) {
    $failures[] = 'the spoke now accepts cache_dev_until from the hub';
}

// ── 3. THE PURGE IS REACHABLE AND TRIGGERED BY THE RIGHT KEYS ────────────
$pushAt = strpos($api, "\$resource === 'settings' && \$sub_action === 'push'");
$pushEnd = $pushAt === false ? false : strpos($api, "ENDPOINT: POST multisite/auth/sso-token", $pushAt);
$handler = ($pushAt !== false && $pushEnd !== false)
    ? substr($api, $pushAt, $pushEnd - $pushAt) : '';
if ($handler === '') {
    $failures[] = 'the settings/push handler could not be isolated; the purge '
        . 'assertions below would pass for the wrong reason';
} else {
    if (!str_contains($handler, 'page_cache_purge_all()')) {
        $failures[] = 'a pushed cache change does not purge the spoke\'s stored '
            . 'pages. Caching turned off from the hub would keep serving the '
            . 'old files until each aged out on the OLD ttl, and the switch '
            . 'would look broken.';
    }
    if (!str_contains($handler, 'page-cache.php')) {
        $failures[] = 'the purge is called without requiring core/page-cache.php, '
            . 'so it would be an undefined function on the spoke';
    }
    // It must key off what was APPLIED, not off what was merely sent: a key
    // the allowlist refused must not trigger a purge, and one held for
    // confirmation has not taken effect yet.
    if (!preg_match('/array_intersect\(\s*\[[^\]]*cache_enabled[^\]]*\]\s*,\s*\$applied\s*\)/', $handler)) {
        $failures[] = 'the purge is not keyed off the APPLIED list. Purging on '
            . 'what was sent would fire for keys the allowlist refused.';
    }
    foreach (['cache_enabled', 'cache_ttl'] as $trigger) {
        if (!preg_match('/array_intersect\(\s*\[[^\]]*' . $trigger . '/', $handler)) {
            $failures[] = "a pushed change to {$trigger} does not trigger the purge";
        }
    }
    // The purge must happen BEFORE the response, or the hub is told the push
    // succeeded while the stale pages are still there.
    $purgeAt = strpos($handler, 'page_cache_purge_all()');
    $okAt = strpos($handler, 'ms_ok(');
    if ($purgeAt !== false && $okAt !== false && $purgeAt > $okAt) {
        $failures[] = 'the purge runs after the response is sent';
    }
}

// ── 4. THE LOCK FLAG IS CLEARED WHEN THE SPOKE LEAVES ────────────────────
// A hub_controls_* flag that survives a disconnect locks an owner out of
// their own settings with no hub left to unlock them.
$discAt = strpos($api, 'Clear all hub_controls_* flags');
$discEnd = $discAt === false ? false : strpos($api, '] as $_ctrl_key', $discAt);
$clearList = ($discAt !== false && $discEnd !== false)
    ? substr($api, $discAt, $discEnd - $discAt) : '';
if ($clearList === '') {
    $failures[] = 'the disconnect flag-clearing block could not be isolated';
} else {
    foreach (['hub_controls_cache', 'hub_controls_socialdock'] as $flag) {
        if (!str_contains($clearList, "'" . $flag . "'")) {
            $failures[] = "{$flag} is not cleared when a spoke disconnects, so "
                . 'that site stays locked out of its own settings with no hub '
                . 'left to unlock it';
        }
    }
    // Every group with a lock flag must be in here. Derived from the hub's own
    // group table so a new group cannot be added without being cleared.
    if (preg_match_all("/'(hub_controls_[a-z]+)'/", $hub, $hm)) {
        foreach (array_unique($hm[1]) as $flag) {
            if (!str_contains($clearList, "'" . $flag . "'")) {
                $failures[] = "{$flag} is pushable from the hub but is not "
                    . 'cleared on disconnect';
            }
        }
    }
}

// ── 5. THE UI OFFERS IT ──────────────────────────────────────────────────
// A group with no row on the page cannot be pushed by anyone.
if (!str_contains($hub, 'hub_controls[cache]')) {
    $failures[] = 'the push page has no hub-controls toggle for the cache group';
}
if (!preg_match("/\\\$render_result\('cache'\)/", $hub)) {
    $failures[] = 'the push page never renders the cache group\'s result, so a '
        . 'failed push would be invisible';
}

// ── 6. AN UNCONFIGURED HUB DOES NOT PUSH A LOCK WITH NOTHING BEHIND IT ───
// Muse's nit, and a real one. The page read ($settings['cache_enabled'] ?? '0')
// and ($settings['cache_ttl'] ?? '300'), so a hub that had never opened its own
// cache settings displayed OFF and 300 as if they were decisions -- and PUSH IT
// ALL then sent the group with no value rows to send, pushing ONLY the lock.
// Every spoke locked out of its own cache settings on a default nobody chose.
//
// THE PREDICATE IS EXECUTED. It is a pure function for exactly that reason: the
// page around it needs a request, a hub and a database.

if (!function_exists('pushit_cache_is_configured')) {
    // Pull the function out of the page without running the page.
    $fnAt = strpos($hub, 'function pushit_cache_is_configured');
    if ($fnAt === false) {
        $failures[] = 'pushit_cache_is_configured() is gone, so nothing stops a '
            . 'hub with no saved cache settings from pushing a bare lock';
    } else {
        $fnEnd = strpos($hub, "
}", $fnAt);
        eval(substr($hub, $fnAt, $fnEnd - $fnAt + 2));
    }
}
if (function_exists('pushit_cache_is_configured')) {
    // A saved '0' IS a decision and must push. Only an absent row is "not set".
    $cases = [
        [['cache_enabled' => '0', 'cache_ttl' => '300'], true,
         "a deliberate OFF is a decision and must still push"],
        [['cache_enabled' => '1', 'cache_ttl' => '60'], true, 'a configured hub pushes'],
        [[], false, 'a hub with neither row saved must not push'],
        [['cache_enabled' => '1'], false, 'a hub missing cache_ttl must not push'],
        [['cache_ttl' => '300'], false, 'a hub missing cache_enabled must not push'],
    ];
    foreach ($cases as [$given, $want, $why]) {
        if (pushit_cache_is_configured($given) !== $want) {
            $failures[] = 'pushit_cache_is_configured() is wrong: ' . $why;
        }
    }
}
// And the push loop must consult it, before it pushes.
// Anchored on the comment, not on "isset($_POST['push_all'])": escaping a $
// inside a PHP string here put a literal backslash in the needle and the slice
// came back empty, which failed against correct code. Third time today.
$loopAt = strpos($hub, 'PUSH IT ALL — push every hub-controlled group');
$loop = $loopAt === false ? '' : substr($hub, $loopAt, 1400);
if ($loop === '') {
    $failures[] = 'the PUSH IT ALL loop could not be found';
} else {
    if (!str_contains($loop, 'pushit_cache_is_configured')) {
        $failures[] = 'PUSH IT ALL does not check whether the hub has cache '
            . 'settings saved, so it can still push a bare lock flag';
    }
    $guardAt = strpos($loop, 'pushit_cache_is_configured');
    $pushAt = strpos($loop, 'pushit_push_group(');
    if ($guardAt !== false && $pushAt !== false && $guardAt > $pushAt) {
        $failures[] = 'the check runs after the push, which checks nothing';
    }
    if (!str_contains($loop, "push_results['cache']")) {
        $failures[] = 'a refused cache push is silent. A group that quietly does '
            . 'nothing is how someone concludes the push is broken.';
    }
}
// The page must not present unsaved defaults as saved values.
if (preg_match("/OFF \(DEFAULT\)/", $hub)) {
    $failures[] = 'the push page still shows "OFF (DEFAULT)" for the cache, '
        . 'which reads as a saved decision when nothing has been chosen';
}
if (!str_contains($hub, 'NOT SET')) {
    $failures[] = 'the page does not distinguish an unsaved cache setting from a '
        . 'saved one';
}

if ($failures) {
    throw new RuntimeException("The cache push group is not safe:\n  - "
        . implode("\n  - ", $failures));
}

echo "Cache push group: hub offers it, spoke accepts exactly its keys, dev "
    . "mode refused, stored pages purged, lock cleared on disconnect: passed.\n";
// ===== SNAPSMACK EOF =====
