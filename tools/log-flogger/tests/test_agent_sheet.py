"""The capability sheet must describe the tool that actually exists.

Sean asked for a help system Claude and Codex can use to find out what is
available. A sheet that drifts from the real commands is worse than none: an
agent would trust it, call something that is not there, and waste the turn it
was written to save.

So this does not check the sheet reads nicely. It checks it matches
build_parser(), both ways.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

import lf as cli
from logflogger import agent


def _real_commands():
    """Every subcommand argparse actually registers."""
    for action in cli.build_parser()._subparsers._group_actions:
        return set(action.choices)
    return set()


def test_every_documented_command_exists():
    documented = {c["name"] for c in agent.COMMANDS}
    missing = documented - _real_commands()
    assert not missing, (
        "The sheet promises commands that do not exist: " + ", ".join(sorted(missing))
    )


def test_every_real_command_is_documented():
    # 'agent' documents itself by being the sheet; everything else must appear.
    undocumented = _real_commands() - {c["name"] for c in agent.COMMANDS} - {"agent"}
    assert not undocumented, (
        "These commands exist but the sheet never mentions them, so an agent "
        "would never find them: " + ", ".join(sorted(undocumented))
    )


def test_the_sheet_leads_with_what_it_refuses_to_do():
    # The refusals save more agent time than the commands do.
    assert agent.REFUSALS
    assert all(isinstance(r, str) and r.strip() for r in agent.REFUSALS)


def test_both_forms_render():
    body = agent.text()
    assert "LOG FLOGGER" in body
    sheet = agent.sheet()
    assert sheet["commands"] and sheet["refusals"]
    assert sheet["entry"].endswith(".py")


def test_the_agent_command_runs_and_exits_clean():
    assert cli.main(["agent"]) == 0
    assert cli.main(["agent", "--json"]) == 0


def test_json_works_in_the_position_an_agent_will_actually_type_it():
    """The sheet documents `gather --json`, i.e. AFTER the subcommand.

    argparse only accepts a top-level flag BEFORE the subcommand, so the first
    version of this tool rejected the exact form its own help told agents to
    use. Both orders must work."""
    assert cli.main(["gather", "--json"]) == 0      # as documented
    assert cli.main(["--json", "gather"]) == 0      # as argparse prefers

# ===== SNAPSMACK EOF =====
