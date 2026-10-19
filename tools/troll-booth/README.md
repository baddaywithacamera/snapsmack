<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line of this file MUST be the SNAPSMACK EOF marker below. -->

# TROLL BOOTH

**Nobody crosses without papers.**

TROLL BOOTH is SnapSmack's local, loopback-only capability broker and agent
collaboration room. It lets an explicitly paired Claude Web or ChatGPT Web
session discover and use the supported CLI surfaces of installed SnapSmack
desktop applications without exposing a shell or a public network service.

Sean unlocks the native application with password plus TOTP. The native window
shows connected agents, available apps, active tasks, conversations, plans,
approvals, evidence, and audit receipts. It also provides per-agent pause,
session revocation, bridge lock, and emergency stop.

## First build boundary

The original v0.1 build was deliberately read-only:

1. Bind only to loopback.
2. Start locked.
3. Unlock in the native window through the existing SnapSmack step-up system.
4. Pair Claude Web and ChatGPT Web with separate short-lived sessions.
5. Discover installed apps through their `agent --json` or
   `capabilities --json` contract.
6. Invoke only declared read-only capabilities through structured arguments.
7. Record actor, reason, ticket, command, result, and receipt.
8. Provide the collaboration room and Sean's control window.

No shell, arbitrary executable, production write, reply delivery, moderation,
cleanup, restore, deletion, or deployment belongs in the first build.

## Implementation status

The experimental v0.1.3 collaboration slice is implemented in `troll_booth/`:

- OAuth-protected Streamable HTTP MCP endpoint;
- short-lived, single-use owner pairing codes;
- outbound-only local worker connection;
- SQLite job queue and audit ledger;
- append-only local conversation history with SQLite FTS5 search and per-room hash chaining;
- `booth_status`, `list_apps`, and `app_capabilities` read-only tools;
- `list_conversations`, `conversation_messages`, and `search_conversation` local-history tools;
- authenticated room posting with a separate `booth:write` pairing grant;
- expiring file and subsystem claims that reject overlapping agents;
- loopback-only owner console for rooms, search, posting, working agreement, and validated app configuration;
- read-only sessions remain unable to post or claim work;
- exact argv capability discovery with `shell=False`;
- systemd and Apache deployment templates.
- credential-free Windows owner-console installer and a recovery runbook.

OAuth sessions are intentionally revoked whenever the remote service restarts
in this first build. This is safer than persisting bearer and refresh tokens
before the owner-facing revocation UI exists.

The pairing code is the owner's explicit step-up decision. A normal code grants
read-only access. A code issued with `--write` adds collaboration write access
to that session even when a browser MCP client reuses a registration that asks
only for its original read scope.

The current vertical slice stores conversation bodies in the local worker's
`~/.troll-booth/history.sqlite3`. It is not stored in Cloudflare or in the
public MCP adapter. The public service queues bounded read requests; the
outbound worker performs the search locally and returns only the requested
results. A returned payload is single-consumption and is removed from the relay
database as soon as the requesting session receives it. Messages are
append-only and hash-chained per room. Attachments are references, not copied
blobs.

The accepted production design makes the existing central MariaDB service on
CT110 the authoritative, full-text-searchable conversation and audit store.
SQLite becomes an optional encrypted offline cache and spool that can be
rebuilt from MariaDB. The existing rclone backup path must transport a
transaction-consistent MariaDB dump or physical backup; copying live database
files is not considered a recoverable backup. This MariaDB migration is the
next storage milestone and is not claimed as part of the v0.1 read-only slice.

## Local development

```text
python -m venv .venv
.venv/Scripts/python -m pip install -e .
set TROLL_BOOTH_WORKER_TOKEN=replace-with-a-long-random-value
.venv/Scripts/troll-booth-server
.venv/Scripts/troll-booth-owner
```

The owner console binds only to `127.0.0.1:8766`, uses a random local token,
and never displays worker credentials. It keeps a previous copy when the
working agreement or app capability configuration is changed.

For a clean Windows installation or disaster recovery, run
`deploy/windows/install-owner-console.ps1`. See `RECOVERY.md` for the complete
source-versus-secret boundary and rebuild procedure.

The production endpoint is `/mcp`. `/health` discloses only service name,
version, and health. The worker endpoints require a separate random bearer
credential that is never granted to Claude.

Create a browser pairing code on the remote adapter host:

```text
troll-booth pair-code --actor claude
```

The code expires in five minutes and is invalid immediately after one use.

## Canonical specification

The complete authority, protocol, security, collaboration, and acceptance-test
contract is section 15 of:

`docs/support-operations-agent-cli-spec.md`

That document controls when this README and implementation disagree.

<!-- SNAPSMACK_EOF -->
