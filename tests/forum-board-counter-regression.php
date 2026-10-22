<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * A board counted replies to a thread that had been deleted.
 *
 * foundtextures.ca showed "Getting Started — 0 THREADS / 1 REPLY". Sean spotted
 * it on the page. Deleting a thread decremented the board's thread_count and
 * left its reply_count alone, so the board kept counting replies nobody could
 * reach. The DELETE handler could not have fixed itself even in principle: its
 * SELECT never fetched reply_count. The move handler, twenty lines earlier,
 * had always adjusted both.
 *
 * Closing that opened a second hole: once a deleted thread's replies are off
 * the board's counter, deleting one of those replies individually would take
 * the same reply off a second time. The reply handler now only touches the
 * board when the parent thread is still live.
 *
 * Offline — it reads the server source, so it runs in the commit gate. The
 * numbers themselves are repaired by forum-recount-boards.sql.
 */

function forum_counter_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$api  = $root . '/projects/forum-server/api/forum/index.php';
forum_counter_check(is_file($api), 'The forum API source is missing.');
$src = (string)file_get_contents($api);

// 1. Deleting a thread must know how many replies it is taking with it.
forum_counter_check(
    (bool)preg_match('/SELECT id, install_id, category_id, reply_count FROM `?ss_forum_threads`?/', $src),
    'The thread-delete handler no longer selects reply_count, so it cannot take the thread\'s replies '
    . 'off the board and the board will count replies to a thread nobody can reach.'
);

// 2. ...and must actually subtract them, in the same statement as the thread.
forum_counter_check(
    (bool)preg_match(
        '/thread_count\s*=\s*GREATEST\(0,\s*thread_count\s*-\s*1\),\s*reply_count\s*=\s*GREATEST\(0,\s*reply_count\s*-\s*\?\)/s',
        $src
    ),
    'Deleting a thread no longer decrements the board\'s reply_count alongside its thread_count. '
    . 'That is exactly the drift that produced "0 THREADS / 1 REPLY".'
);

// 3. Deleting a reply must not subtract it again when its thread is gone.
forum_counter_check(
    (bool)preg_match('/SELECT category_id, is_deleted FROM `?ss_forum_threads`?/', $src),
    'The reply-delete handler no longer reads the parent thread\'s is_deleted flag, so it cannot tell '
    . 'whether the board has already lost this reply.'
);
forum_counter_check(
    (bool)preg_match('/if\s*\(\$parent\s*&&\s*!\(int\)\(\$parent\[\x27is_deleted\x27\]\s*\?\?\s*0\)\)/', $src),
    'Deleting a reply decrements the board unconditionally again. On a thread that was already deleted '
    . 'that subtracts the same reply twice and the board under-reports.'
);

// 4. The repair for counts that already drifted has to stay shipped.
$sql = $root . '/projects/forum-server/forum-recount-boards.sql';
forum_counter_check(
    is_file($sql),
    'forum-recount-boards.sql is gone. Fixing the code forward does not repair a number that has already drifted.'
);
$sqlSrc = (string)file_get_contents($sql);
foreach (['ss_forum_categories', 'ss_forum_threads', 'ss_forum_replies', 'is_deleted'] as $needle) {
    forum_counter_check(
        str_contains($sqlSrc, $needle),
        "The recount script no longer references $needle; it cannot derive the real counts."
    );
}
forum_counter_check(
    !preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM)\b/i', $sqlSrc),
    'The recount script contains a destructive statement. It may only write the two counters.'
);

fwrite(STDOUT, "Forum board-counter regression passed.\n");

// ===== SNAPSMACK EOF =====
