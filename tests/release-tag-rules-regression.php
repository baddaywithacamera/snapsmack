<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A published tag must never move, and "I could not check" must never pass.
 *
 * WHAT HAPPENED. On 29 October the release gate said, in these words: "If it
 * is not deployed, move the tag deliberately and re-run." An agent followed
 * it and re-pointed v0.7.970D twice -- a tag that was already on origin. The
 * gate was not lying: it asks the fleet what it is SERVING, and 970D was not
 * served. It simply had no idea whether the tag had ever left the machine.
 *
 * THE DISTINCTION IT WAS MISSING IS PUBLISHED, NOT DEPLOYED. Sean's fold rule
 * -- one open build number until it is deployed -- is about build NUMBERS. It
 * was never licence to rewrite a ref other people may have fetched.
 *
 * This is a MUTATION test. Every branch is proved to fire by being driven
 * into it, including the one that matters most: origin unreachable must
 * REFUSE, not shrug. The repo has made the opposite mistake before -- an
 * earlier live-site check SKIPPED when its request failed, which is how
 * v0.7.892D was force-moved while twelve sites were already serving it.
 *
 * The rules are pure functions in tools/_build/release-tag-rules.php so they
 * can be driven directly. Including the gate itself runs it, which is why the
 * reasoning does not live there.
 */

$root = dirname(__DIR__);
require_once $root . '/tools/_build/release-tag-rules.php';

$pass = 0;
$fail = 0;

function rt_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

const RT_TAG  = 'v0.7.971D';
const RT_VER  = '0.7.971D';
const RT_OLD  = '2fbcec63b1aa0000000000000000000000000000';
const RT_HEAD = 'a9ed97e0c2bb0000000000000000000000000000';

echo "A tag that exists on origin must never move\n";
$v = snap_gate_tag_move_verdict(RT_VER, RT_OLD, RT_HEAD, 'present');
rt_test($v['ok'] === false, 'the move is REFUSED when origin has the tag');
rt_test(stripos($v['message'], 'published, not just deployed') !== false,
    'and the message says PUBLISHED, not just deployed, in those words');
rt_test(stripos($v['message'], 'open the next number') !== false,
    'and it says what to do instead, so the next agent is not left guessing');
rt_test(strpos($v['message'], '2fbcec63') !== false && strpos($v['message'], 'a9ed97e0') !== false,
    'and it names both commits, so the refusal can be checked rather than believed');

echo "\nOrigin unreachable must REFUSE, not assume the tag is local\n";
// The important one. A gate that passes when it cannot tell gives false
// comfort, and this repo has already paid for that once.
$v = snap_gate_tag_move_verdict(RT_VER, RT_OLD, RT_HEAD, 'unknown');
rt_test($v['ok'] === false, 'the move is REFUSED when origin could not be reached');
rt_test(stripos($v['message'], 'could not check origin') !== false,
    'and it says plainly that it could not check, rather than implying it did');
rt_test(stripos($v['message'], 'ls-remote') !== false,
    'and it gives the command to check by hand');
rt_test(stripos($v['message'], 'published') !== false,
    'and names what is unknown: whether the tag is published');

echo "\nA tag that has never left the machine may be re-pointed\n";
// Sean's fold rule. One open build number until it is deployed, and a local
// tag is part of that number, not a separate one.
$v = snap_gate_tag_move_verdict(RT_VER, RT_OLD, RT_HEAD, 'absent');
rt_test($v['ok'] === true, 'the move is ALLOWED when origin answered and does not have it');
rt_test($v['message'] === '', 'with no refusal message');
rt_test(stripos($v['note'], 'may be re-pointed') !== false,
    'and a note saying so, rather than silence');
rt_test(stripos($v['note'], 'record the move in the tag message') !== false,
    'and it asks for the move to be recorded, which is how 970D should have gone');

echo "\nWhen nothing is being moved there is no question to answer\n";
foreach (['present', 'absent', 'unknown'] as $state) {
    $v = snap_gate_tag_move_verdict(RT_VER, RT_HEAD, RT_HEAD, $state);
    rt_test($v['ok'] === true,
        "a tag already at HEAD passes with origin '{$state}': nothing is moving");
}
$v = snap_gate_tag_move_verdict(RT_VER, RT_HEAD, RT_HEAD, 'unknown');
rt_test(stripos($v['note'], 'nothing is being moved') !== false,
    'and it says why it passed, so an unreachable origin is not read as a skipped check');

echo "\nReading origin's answer: present, absent, and unreachable are three things\n";
$lines = [
    'a real hit' => [RT_OLD . "\trefs/tags/" . RT_TAG, 'present'],
    'a hit with its peel line' => [
        RT_OLD . "\trefs/tags/" . RT_TAG . "\n" . RT_HEAD . "\trefs/tags/" . RT_TAG . '^{}', 'present'],
    'an empty but successful listing' => ['', 'absent'],
    'whitespace only' => ["\n  \n", 'absent'],
    'origin answered with a DIFFERENT tag' => [RT_OLD . "\trefs/tags/v0.7.970D", 'absent'],
    'no remote configured' => ["fatal: 'origin' does not appear to be a git repository", 'unknown'],
    'no network' => ["fatal: Could not read from remote repository.", 'unknown'],
    'ssh refused' => ['Permission denied (publickey).', 'unknown'],
    'timeout' => ['ssh: connect to host github.com port 22: Connection timed out', 'unknown'],
    'host key' => ['Host key verification failed.', 'unknown'],
    'repo gone' => ['ERROR: Repository not found.', 'unknown'],
];
foreach ($lines as $label => [$out, $want]) {
    $got = snap_gate_parse_ls_remote($out, RT_TAG);
    rt_test($got === $want, "{$label} reads as '{$want}'" . ($got === $want ? '' : " (got '{$got}')"));
}

echo "\nA near-miss ref is not a hit\n";
// refs/tags/v0.7.971D-rc must not satisfy a check for v0.7.971D.
foreach (['refs/tags/' . RT_TAG . '-rc', 'refs/heads/' . RT_TAG, 'refs/tags/x' . RT_TAG] as $ref) {
    rt_test(snap_gate_parse_ls_remote(RT_OLD . "\t" . $ref, RT_TAG) === 'absent',
        "{$ref} does not count as the tag being on origin");
}

echo "\nThe gate is actually wired to these rules\n";
// The functions could be perfect and unused. This is the one source
// assertion here, and it is labelled as one.
$gate = (string) file_get_contents($root . '/tests/agreed-rules-gate.php');
rt_test(strpos($gate, "release-tag-rules.php") !== false,
    'tests/agreed-rules-gate.php includes the rules');
rt_test(strpos($gate, 'snap_gate_tag_move_verdict(') !== false,
    'and asks for the verdict rather than comparing commits itself');
rt_test(strpos($gate, 'snap_gate_origin_tag_state($root, $tagged)') !== false,
    'and passes it the real origin state');
// Comments are stripped first: the gate QUOTES the old advice in the comment
// explaining why it is gone, and an assertion that cannot tell a quotation
// from live code would fail on the explanation. Same token walk rule 6 uses.
$gateCode = '';
foreach (token_get_all($gate) as $tok) {
    if (is_array($tok)) {
        if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
        $gateCode .= $tok[1];
    } else {
        $gateCode .= $tok;
    }
}
rt_test(strpos($gateCode, 'If it is not deployed, move the tag deliberately') === false,
    'the old advice that caused this is gone from the code, not just commented out');
rt_test(strpos($gate, 'If it is not deployed, move the tag deliberately') !== false,
    'but it is still quoted in a comment, so the next reader learns why it changed');

echo "\nThe rule is written down where the rules that bite are written down\n";
$agents = (string) @file_get_contents($root . '/AGENTS.md');
rt_test($agents !== '' && stripos($agents, 'immutable once on origin') !== false,
    'AGENTS.md states that a tag is immutable once on origin');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Release tag rules: FAIL ({$fail}).\n";
    exit(1);
}
echo "Release tag rules: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
