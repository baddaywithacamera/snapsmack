<?php
declare(strict_types=1);

/**
 * SNAPSMACK - when a version tag may move, and when it must not
 *
 * WHY THIS FILE EXISTS. On 29 October the release gate told an agent, in
 * these words: "If it is not deployed, move the tag deliberately and re-run."
 * The agent did, twice, on v0.7.970D -- a tag that was already on origin. The
 * gate knew whether a version was IN SERVICE, because it asks the fleet, but
 * it had no idea whether the tag had ever left the machine, so its advice was
 * sound for a local tag and actively wrong for a published one.
 *
 * THE DISTINCTION THE GATE WAS MISSING IS PUBLISHED, NOT DEPLOYED. Sean's
 * fold rule -- one open build number until it is deployed -- is about build
 * NUMBERS. It was never licence to rewrite a ref that other people may have
 * fetched. A tag is immutable the moment it exists on origin, built or not.
 *
 * The rules live here, apart from the gate, for one reason: the gate RUNS
 * when you include it, so a test cannot include it to check its reasoning.
 * Two of the three functions below are pure, and the mutation test drives
 * every combination through them directly.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Read `git ls-remote` output and say whether the tag is on origin.
 *
 * Pure, so the three answers can be proved rather than hoped for.
 *
 * 'present' the tag is on origin; it is immutable.
 * 'absent'  origin answered and does not have it; a move is legitimate.
 * 'unknown' origin could not be reached. NOT the same as absent, and the
 *           caller must refuse the move rather than assume it is local --
 *           a gate that passes when it cannot tell gives false comfort,
 *           which is the mistake the live-site check already made once.
 */
function snap_gate_parse_ls_remote(string $output, string $tag): string {
    $out = trim($output);

    // Failure to reach origin is checked FIRST. git prints its fatals on
    // stderr, which the caller folds into this string, and a listing that
    // failed can look empty if only the exit code said so.
    if (preg_match('/\b(fatal|error):|could not read from remote|permission denied|'
        . 'does not appear to be a git repository|connection (timed out|refused)|'
        . 'host key verification failed|repository not found|authentication failed/i', $out)) {
        return 'unknown';
    }
    if ($out === '') return 'absent';

    // A real hit is "<sha>\trefs/tags/<tag>" and may carry a "^{}" peel line.
    foreach (preg_split('/\R/', $out) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) continue;
        $ref = rtrim((string)$parts[1], '^{}');
        if ($ref === 'refs/tags/' . $tag) return 'present';
    }
    // Origin answered with something, but not this tag.
    return 'absent';
}

/**
 * Ask origin whether it has the tag.
 *
 * SNAPSMACK_GATE_ORIGIN_TAG overrides the answer. That seam exists so the
 * gate's own refusal can be mutation-tested without a network, and it is
 * named loudly rather than hidden: this is a developer-side reminder, not a
 * runtime security control, and the real protection is server-side
 * protection of the released tag namespace, which still does not exist.
 */
function snap_gate_origin_tag_state(string $root, string $tag): string {
    $override = getenv('SNAPSMACK_GATE_ORIGIN_TAG');
    if (is_string($override) && in_array($override, ['present', 'absent', 'unknown'], true)) {
        return $override;
    }
    $out = (string)@shell_exec('git -C ' . escapeshellarg($root)
        . ' ls-remote --tags origin ' . escapeshellarg('refs/tags/' . $tag) . ' 2>&1');
    return snap_gate_parse_ls_remote($out, $tag);
}

/**
 * May this tag be re-pointed at HEAD?
 *
 * Pure. Returns ['ok'=>bool, 'note'=>string, 'message'=>string]: 'ok' false
 * means refuse, and 'message' is what the gate prints. When nothing is being
 * moved -- the tag already equals HEAD -- there is no question to answer and
 * the origin state does not matter.
 */
function snap_gate_tag_move_verdict(string $version, string $tagCommit,
                                    string $headCommit, string $originState): array {
    $short = static function (string $sha): string {
        return $sha === '' ? '(unknown)' : substr($sha, 0, 8);
    };

    if ($tagCommit !== '' && $tagCommit === $headCommit) {
        return ['ok' => true, 'note' => 'v' . $version . ' already points at HEAD; nothing is being moved.',
                'message' => ''];
    }

    $where = 'v' . $version . ' is tagged at ' . $short($tagCommit)
           . ' while HEAD is ' . $short($headCommit) . '. ';

    if ($originState === 'present') {
        return ['ok' => false, 'note' => '', 'message' => $where
            . 'That tag is PUBLISHED, not just deployed: it exists on origin, so anyone '
            . 'may already have fetched it and it must never move. Open the next number '
            . 'and tag that instead.'];
    }
    if ($originState === 'unknown') {
        return ['ok' => false, 'note' => '', 'message' => $where
            . 'COULD NOT CHECK ORIGIN for v' . $version . ', so whether it is published is '
            . 'unknown. Refusing the move rather than assuming the tag is local: a gate '
            . 'that passes when it cannot tell is worse than no gate. Check by hand with '
            . 'git ls-remote --tags origin, then re-run.'];
    }
    // absent: origin answered and does not have it, so it has never left this
    // machine. Sean's fold rule applies and re-pointing is the correct act.
    return ['ok' => true, 'note' => $where
        . 'Origin does not have v' . $version . ', so it is local and may be re-pointed '
        . 'deliberately. Record the move in the tag message.', 'message' => ''];
}

// ===== SNAPSMACK EOF =====
