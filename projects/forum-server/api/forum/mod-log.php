<?php
/**
 * SNAPSMACK FORUM — moderation log (SS-1510)
 *
 * Every act of moderator power gets a row: who did it, what they did, to
 * which thing, and when.
 *
 * WHY THIS DID NOT EXIST AND WHY IT HAD TO. Before this, no moderation
 * action was recorded anywhere — no table in any schema, no logging call in
 * the API. Deletions, locks, board changes and role promotions all happened
 * and left nothing behind. That is bad on its own, and it is worse in
 * combination with how the God key works: require_auth() REFUSES
 * FORUM_MOD_KEY for posting, so an action taken with that key carries no
 * install identity at all. Afterwards there was no way to tell the owner's
 * moderation from anyone else's who had the string.
 *
 * Making an owner's blog the moderator (SS-1510) is only meaningful if the
 * acts are attributable. This file is that half.
 *
 * IT LIVES IN ITS OWN FILE SO IT CAN BE TESTED. index.php is a router that
 * dispatches on include, so nothing in it can be executed by a test — the
 * existing forum regressions all read its source instead. Extracted here,
 * the behaviour runs for real against a database in
 * tests/forum-moderation-is-logged-regression.php, and only the call sites
 * are checked by reading.
 *
 * IT NEVER THROWS. A forum whose moderator cannot delete a spam post because
 * the log table is missing is worse than one with a gap in its log. A
 * failure goes to error_log so a missing migration is visible to whoever
 * runs the server, rather than silently producing an empty audit trail.
 */

if (!function_exists('forum_log_mod_action')) {

/**
 * Record one act of moderator power.
 *
 * $actor is the install row of the caller, or NULL when the God key acted —
 * which is itself the thing most worth recording, so NULL is a value here,
 * not a missing argument.
 *
 * Returns true when a row was written. Callers ignore it; the tests do not.
 */
function forum_log_mod_action(
    PDO $pdo,
    ?array $actor,
    string $action,
    string $target_type = '',
    ?int $target_id = null,
    string $detail = ''
): bool {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO ss_forum_mod_log
                (actor_install_id, actor_domain, actor_is_god, action, target_type, target_id, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        return $stmt->execute([
            $actor === null ? null : (int)($actor['id'] ?? 0),
            // The God key has no domain. Recording the string "god" rather
            // than an empty cell means a reader never has to guess whether
            // the blank is "nobody" or "we did not capture it".
            $actor === null ? 'god' : (string)($actor['domain'] ?? ''),
            $actor === null ? 1 : 0,
            substr($action, 0, 40),
            substr($target_type, 0, 20),
            $target_id,
            substr($detail, 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('[forum mod-log] could not record ' . $action . ': ' . $e->getMessage());
        return false;
    }
}

}

// ===== SNAPSMACK EOF =====
