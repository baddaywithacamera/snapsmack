<?php
declare(strict_types=1);

/**
 * SNAPSMACK FORUM — undoing a soft delete. (SS-1529)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS IS NOT INSIDE index.php. The router cannot be loaded by a test —
 * it dispatches on include — so anything living in it can only ever be READ by
 * a test, never run. The whole difficulty of restoring is COUNTER ARITHMETIC,
 * and arithmetic that is only ever read is arithmetic nobody has checked. So it
 * lives here, as two plain functions against a PDO handle, for the same reason
 * forum_log_mod_action() does.
 *
 * WHAT THE ARITHMETIC HAS TO GET RIGHT. Deleting a thread took its replies off
 * the board's reply_count as well as its thread_count. Deleting a reply touched
 * the board only when the parent thread was still live, because deleting a
 * thread had already removed all of its replies from that figure — counting
 * them again would make the board under-report. There is a comment on the
 * delete path recording that exact fault shipping once: Getting Started showed
 * "0 THREADS / 1 REPLY" on foundtextures.ca because delete did half the sum.
 * Restore is the same risk from the other direction, which is why the test for
 * this file asserts a ROUND TRIP — delete, restore, and the counters must be
 * exactly what they were before.
 *
 * These functions do NOT check permissions and do NOT log. The router does
 * both: permission depends on the caller's identity, which is the router's
 * business, and keeping it out of here is what lets a test drive the sums.
 */

/**
 * Bring back a soft-deleted thread.
 *
 * Returns ['status' => 'restored', 'reply_count' => int] on success, or
 * ['error' => 'THREAD_NOT_FOUND'] when there is no deleted thread with that id.
 */
function forum_restore_thread(PDO $pdo, int $thread_id): array
{
    // is_deleted = 1 in the lookup is a guard, not a detail. Restoring a thread
    // that is already live would add it to the board's counters a second time,
    // so it has to be refused rather than silently double-counted.
    $stmt = $pdo->prepare(
        "SELECT id, category_id FROM ss_forum_threads WHERE id = ? AND is_deleted = 1 LIMIT 1"
    );
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$thread) {
        return ['error' => 'THREAD_NOT_FOUND'];
    }

    // RECOUNTED, not read from thread.reply_count. The stored figure should
    // agree, but if it has drifted then trusting it writes that drift onto the
    // board's counter permanently. Counting the live rows is the only figure
    // that cannot be wrong, and it repairs the thread's own number on the way.
    $r = $pdo->prepare(
        "SELECT COUNT(*) FROM ss_forum_replies WHERE thread_id = ? AND is_deleted = 0"
    );
    $r->execute([$thread_id]);
    $live_replies = (int) $r->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE ss_forum_threads SET is_deleted = 0, reply_count = ? WHERE id = ?"
        )->execute([$live_replies, $thread_id]);
        $pdo->prepare(
            "UPDATE ss_forum_categories
                SET thread_count = thread_count + 1,
                    reply_count  = reply_count + ?
              WHERE id = ?"
        )->execute([$live_replies, (int) $thread['category_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['status' => 'restored', 'reply_count' => $live_replies];
}

/**
 * Bring back a soft-deleted reply.
 *
 * Returns ['status' => 'restored', 'thread_id' => int, 'counted_on_board' => bool]
 * or ['error' => 'REPLY_NOT_FOUND'].
 *
 * counted_on_board is returned because it is the one decision this function
 * makes that a caller or a test cannot otherwise see.
 */
function forum_restore_reply(PDO $pdo, int $reply_id): array
{
    $stmt = $pdo->prepare(
        "SELECT id, thread_id FROM ss_forum_replies WHERE id = ? AND is_deleted = 1 LIMIT 1"
    );
    $stmt->execute([$reply_id]);
    $reply = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reply) {
        return ['error' => 'REPLY_NOT_FOUND'];
    }

    $thread_id = (int) $reply['thread_id'];

    $t = $pdo->prepare("SELECT category_id, is_deleted FROM ss_forum_threads WHERE id = ? LIMIT 1");
    $t->execute([$thread_id]);
    $parent = $t->fetch(PDO::FETCH_ASSOC);

    // The board's figure moves ONLY when the parent thread is live. If the
    // thread is still deleted, the board is not counting any of its replies,
    // and adding this one would count a reply nobody can reach. The delete path
    // guards the identical case for the identical reason.
    $on_board = ($parent && !(int) ($parent['is_deleted'] ?? 0));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE ss_forum_replies SET is_deleted = 0 WHERE id = ?")
            ->execute([$reply_id]);
        $pdo->prepare("UPDATE ss_forum_threads SET reply_count = reply_count + 1 WHERE id = ?")
            ->execute([$thread_id]);
        if ($on_board) {
            $pdo->prepare(
                "UPDATE ss_forum_categories SET reply_count = reply_count + 1 WHERE id = ?"
            )->execute([(int) $parent['category_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Deliberately NOT re-solved. Deleting the accepted answer unsolves the
    // thread; whether this reply is still the right answer is a human
    // judgement, and reinstating it here would change a thread's answer with
    // nobody choosing to.
    return ['status' => 'restored', 'thread_id' => $thread_id, 'counted_on_board' => $on_board];
}

// ===== SNAPSMACK EOF =====
