<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# TROLL BOOTH

**Nobody crosses without papers.**

TROLL BOOTH is the local capability broker for SnapSmack desktop applications.
It discovers an application's live `agent --json` sheet, intersects that sheet
with a typed local policy, and invokes only the resulting read-only capability.
It never accepts a shell command.

This first implementation slice provides the broker core and command-line
feasibility harness. It deliberately does not yet open a network listener or
claim browser pairing is complete.

```powershell
python tb.py apps --json
python tb.py capabilities log-flogger --json
python tb.py invoke log-flogger analyze --args '{"quiet_days":14}' --actor codex --json
```

The initial policy exposes:

- LOG FLOGGER: `gather`, `analyze`
- TICKET WICKET: `queue`, public `show`, `stale`

The policy contains argument types and bounds. Discovery proves the installed
application still declares the command; the policy prevents a newly added app
command from becoming remotely callable by accident.

Every invocation is appended to a local JSON-lines audit log. Arguments are
recorded only after validation and secret-bearing argument names are rejected.

<!-- SNAPSMACK_EOF -->
