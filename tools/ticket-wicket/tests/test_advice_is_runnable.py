"""Every command this tool tells you to run must actually parse.

WHY THIS EXISTS. tw.py printed, as help, a command the tool could not run:

    tw.py --as <you> triage <id> --body-file draft.txt

`--body-file` was added on a branch that was never merged, while SS-1133 was
marked resolved. So for eighteen days the tool answered a stuck user by
advising a flag argparse would reject. Advice that does not run is worse than
no advice: it is read as authoritative, and the person following it loses the
turn they were already stuck in.

test_agent_sheet.py already pins the capability sheet against build_parser()
both ways. It checks COMMANDS. This checks the FLAGS inside the advice strings
the tool prints at the moment of failure, which is where the drift happened.

It is deliberately general. It finds every "tw.py ..." line in the source and
asks the real parser whether it would accept it, so the next stale instruction
fails here rather than in front of whoever is stuck.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import re
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

import tw as cli

SOURCE = open(os.path.join(BASE, 'tw.py'), encoding='utf-8').read()

#: a line of advice: "tw.py [--global ...] <command> ... --flag ..."
ADVICE = re.compile(r'tw\.py\s+(?P<rest>[^"\'\\\n]*)')


def _subparsers():
    for action in cli.build_parser()._subparsers._group_actions:
        return action.choices
    return {}


def _flags_of(parser):
    names = set()
    for action in parser._actions:
        names.update(action.option_strings)
    return names


def _advice_lines():
    """Every printed 'tw.py ...' instruction in the source."""
    found = []
    for match in ADVICE.finditer(SOURCE):
        rest = match.group('rest').strip()
        if not rest or rest.startswith('-h'):
            continue
        found.append(rest)
    return found


def test_there_is_advice_to_check():
    """If this fails the regex stopped matching and everything below is vacuous."""
    assert _advice_lines(), 'no tw.py advice strings found in the source'


def test_every_command_named_in_advice_exists():
    commands = _subparsers()
    for line in _advice_lines():
        words = [w for w in line.split() if not w.startswith('-')]
        # skip the value that follows --as, and placeholders
        named = [w for w in words
                 if w not in ('<you>', 'claude', 'chatgpt', 'sean', 'assistant')]
        if not named:
            continue
        command = named[0]
        if command.startswith('<') or command.startswith('{'):
            continue
        assert command in commands, (
            'tw.py advises "%s" but there is no such command' % command)


def test_every_flag_named_in_advice_is_real():
    """The one that would have caught --body-file."""
    commands = _subparsers()
    top = _flags_of(cli.build_parser())
    for line in _advice_lines():
        words = line.split()
        command = None
        for word in words:
            if not word.startswith('-') and word in commands:
                command = word
                break
        allowed = set(top)
        if command:
            allowed |= _flags_of(commands[command])
        for word in words:
            if not word.startswith('--'):
                continue
            flag = word.split('=')[0]
            assert flag in allowed, (
                'tw.py advises %s for command %r, but the parser does not '
                'accept it. Advice that cannot run is worse than none.'
                % (flag, command or '(top level)'))


def test_the_body_file_advice_in_particular_parses():
    """The exact line from SS-1133, end to end through the real parser.

    The general test above would catch a reintroduction, but this names the
    case so a future reader knows what went wrong rather than only that a
    rule exists.
    """
    assert '--body-file' in _flags_of(_subparsers()['triage']), (
        'triage must accept --body-file: tw.py prints that exact command as '
        'help when a ticket body is too large to pass on the command line')
    parsed = cli.build_parser().parse_args(
        ['--as', 'claude', 'triage', 'SS-1133', '--body-file', 'draft.txt'])
    assert getattr(parsed, 'body_file', None) == 'draft.txt'


if __name__ == '__main__':
    failures = 0
    for name, fn in sorted(globals().items()):
        if not name.startswith('test_') or not callable(fn):
            continue
        try:
            fn()
            print('PASS ' + name)
        except AssertionError as exc:
            failures += 1
            print('FAIL ' + name + ' — ' + str(exc))
    if failures:
        raise SystemExit('\n%d FAILURE(S)\n' % failures)
    print('advice is runnable: PASS')

# ===== SNAPSMACK EOF =====
