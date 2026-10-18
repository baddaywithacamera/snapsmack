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

The first build is deliberately read-only:

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

The v0.1 vertical slice is implemented in `troll_booth/`:

- OAuth-protected Streamable HTTP MCP endpoint;
- short-lived, single-use owner pairing codes;
- outbound-only local worker connection;
- SQLite job queue and audit ledger;
- append-only local conversation history with SQLite FTS5 search and per-room hash chaining;
- `booth_status`, `list_apps`, and `app_capabilities` read-only tools;
- `list_conversations`, `conversation_messages`, and `search_conversation` local-history tools;
- exact argv capability discovery with `shell=False`;
- systemd and Apache deployment templates.

OAuth sessions are intentionally revoked whenever the remote service restarts
in this first build. This is safer than persisting bearer and refresh tokens
before the owner-facing revocation UI exists.

Conversation bodies are stored in the local worker's
`~/.troll-booth/history.sqlite3`, not in Cloudflare and not in the public MCP
adapter. The public service queues bounded read requests; the outbound worker
performs the search locally and returns only the requested results. A returned
payload is single-consumption and is removed from the relay database as soon
as the requesting session receives it. Messages
are append-only and hash-chained per room. Attachments are references, not
copied blobs. A later encrypted relay will carry new messages between agents;
it is not claimed as part of this read-only build.

## Local development

```text
python -m venv .venv
.venv/Scripts/python -m pip install -e .
set TROLL_BOOTH_WORKER_TOKEN=a-long-random-value
.venv/Scripts/troll-booth-server
```

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
