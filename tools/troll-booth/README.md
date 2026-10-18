<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# TROLL BOOTH

**Nobody crosses without papers.**

TROLL BOOTH is the local capability broker for SnapSmack desktop applications.
It discovers an application's live `agent --json` sheet, intersects that sheet
with a typed local policy, and invokes only the resulting read-only capability.
It never accepts a shell command.

This first implementation slice provides the broker core and command-line
feasibility harness. The native `booth.py` window now owns first-run password
and TOTP setup, starts a dynamically assigned loopback-only service, issues
ten-minute actor sessions, and provides an emergency lock. An unlocked owner
can create a two-minute, actor-scoped, single-use pairing code. The browser
connector that submits that code is not yet complete.

Paired actors share a persistent collaboration room. Every message carries its
actor, intended recipient, kind, and timestamp. Sean can read and post in the
room from the native TROLL BOOTH window.

```powershell
python tb.py apps --json
python tb.py capabilities log-flogger --json
python tb.py invoke log-flogger analyze --args '{"quiet_days":14}' --actor codex --json
python booth.py
```

The initial policy exposes:

- LOG FLOGGER: `gather`, `analyze`
- TICKET WICKET: `queue`, public `show`, `stale`

The policy contains argument types and bounds. Discovery proves the installed
application still declares the command; the policy prevents a newly added app
command from becoming remotely callable by accident.

Every invocation is appended to a local JSON-lines audit log. Arguments are
recorded only after validation and secret-bearing argument names are rejected.

## Desktop app contracts still needed

TROLL BOOTH can use an app only after that app exposes a typed `agent --json`
contract. The next useful contracts are:

- SMACK UP YOUR BACKUP: list sites; show the latest successfully verified
  backup for each site; distinguish a failed latest attempt from the last good
  copy; list recovery points. “Never backed up” must be an explicit state.
- CRONOMETER: list jobs, current health, and recent runs.
- SYBU/GYSS: inspect an image job and submit an explicitly approved enrichment
  job under the owner's existing account plan.

The SUYB desktop list should show the last verified backup beside every blog.
It must not label a failed or merely started attempt as a backup.

<!-- SNAPSMACK_EOF -->
