<?php
// SNAPSMACK_EOF_HEADER — preserve the canonical EOF marker below.
if (PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Is an update holding the maintenance lock?
 *
 * One pure function, no side effects, no database, no globals — so it can be
 * exercised directly by a test with real files instead of being asserted at by
 * reading the source. It decides one thing and it decides it the safe way.
 *
 * FAIL CLOSED: the lock is HELD unless we can positively prove it is stale,
 * meaning a valid timestamp more than $ttl seconds old. Unreadable file, empty
 * file, truncated JSON, no 'since', a 'since' of zero, a negative or
 * future-dated 'since' — all of those count as HELD.
 *
 * The inversion is the whole point. updater_acquire_lock() creates this file
 * and then writes to it; for the instant in between, the file EXISTS and
 * decodes to nothing. A check that asks "is there a recent valid timestamp?"
 * reads that instant as UNLOCKED, at precisely the moment an update is starting
 * — the worst possible answer at the worst possible time. core/constants.php
 * already gets this right on the web side: it only REMOVES a lock when a valid
 * timestamp is older than 300 seconds, so a missing or invalid one keeps
 * blocking. This matches that behaviour for callers that are exempt from the
 * web-side gate.
 *
 * The TTL still applies to a provably stale lock, so a lock left behind by a
 * crashed update cannot block for ever.
 *
 * @param  string   $lock_path  Absolute path to data/maintenance.lock.
 * @param  int      $ttl        Seconds after which a VALID timestamp is stale.
 * @param  int|null $age        Out: the lock's age in seconds, or null when no
 *                              readable timestamp was found. For reporting
 *                              only — never for the decision.
 */
function snap_maintenance_lock_held(string $lock_path, int $ttl = 300, ?int &$age = null): bool
{
    $age = null;

    if (!file_exists($lock_path)) {
        return false;
    }

    $raw = @file_get_contents($lock_path);
    if ($raw === false) {
        // Exists but unreadable. We cannot prove it is stale, so it is held.
        return true;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['since'])) {
        return true;
    }

    $since = (int) $data['since'];
    if ($since <= 0) {
        return true;
    }

    $measured = time() - $since;
    if ($measured < 0) {
        // Future-dated: a clock skew or a corrupt value. Not provably stale.
        return true;
    }

    $age = $measured;

    return $measured <= $ttl;
}

// ===== SNAPSMACK EOF =====
