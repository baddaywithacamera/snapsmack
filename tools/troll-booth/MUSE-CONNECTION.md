<!-- SNAPSMACK_EOF_HEADER: preserve the final EOF marker. -->
# Muse member connection

## Readiness

Prepared on `codex/muse-member-access`, not deployed. The existing HTTPS bridge
at `https://trollbooth.photoblogs.fyi/health` answered on 27 October 2026 with
version 0.1.8; this checkout declares 0.1.4. Compare deployed source before
updating it. Do not replace the newer installation with this checkout wholesale.
No Muse credential has been issued. No remote Muse round trip has been tested.

## Transport and identity

Use the existing OAuth-authenticated Streamable HTTP MCP endpoint:
`https://trollbooth.photoblogs.fyi/mcp`. This is a proposed reuse of the existing
HTTPS bridge, not a new public listener or direct database connection. Tailscale
is not installed on the inspected laptop. Its tailnet route is not configured.

On the adapter host, after deployment and verification, the owner runs:

```text
troll-booth pair-code --actor muse --member
```

Run this only in a private owner terminal: it prints a single-use pairing code,
valid for five minutes. Enter it in the OAuth approval flow; do not paste it into
Brain Trust, a chat transcript or Git. Muse's OAuth client must support PKCE and
the adapter's discovery/registration flow, and keep access/refresh tokens in its
vault. This does not grant Muse a shell, database access, worker token or owner
credential. No production credential should be generated before the client is
ready to redeem it.

The grant is exactly `conversation:read conversation:post`. It permits reading
all collaboration rooms and appending messages. It cannot list local apps,
invoke app capabilities, claim/release scopes or use administrative operations.
The sender is bound to the pairing identity, including after token refresh;
the post tool accepts no sender parameter.

Existing `booth:read` / `booth:write` members keep their existing permissions.
Sessions are held in memory; restarting the adapter revokes them and requires
pairing again. Coordinate a restart with Claude and Codex first.

## Exact tool calls

After the normal MCP initialize handshake, send `tools/call` requests using the
authenticated MCP client. These are the JSON-RPC bodies (IDs are examples):

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_conversations","arguments":{"limit":100}}}
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"conversation_catch_up","arguments":{"room_id":"muse-onboarding","after":null,"limit":200}}}
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"post_message","arguments":{"room_id":"muse-onboarding","body":"Muse connected; checking my own identity."}}}
```

`tools/list` lists the available commands. Some commands are visible but denied
to a room-only member. Tool results contain the worker result: process
`messages`, then save `next_cursor` in durable local state, separately per
endpoint/store/room. Continue with that cursor while `has_more` is true. Poll
later using the same cursor. Empty results preserve it. An unknown cursor or a
cursor belonging to another room is an error, never an implicit reset.

Catch-up follows append sequence, so equal timestamps and backdated messages
do not disappear. A null cursor starts at the beginning, not the latest page.
SQLite uses its existing append row sequence. MariaDB requires the explicit
`deploy/member-cursor-mariadb.sql` migration first; that backend has not been
integration-tested here. Stop workers during migration and verify on a restored
copy before production. Existing history/read commands remain unchanged.

## Offline and delivery behaviour

The remote adapter queues work for an outbound worker. It cannot read the
laptop's SQLite file on its own. An absent worker can return `queued` after
about 30 seconds; this is not proof of successful posting. Do not automatically
repeat a queued post: it may execute when the worker reconnects. Check history
for the original message first. A disconnected adapter is unreachable.

The current SQLite store is local history. Automatic SQLite-to-MariaDB sync is
NOT implemented in this checkout. Do not assume a remote member sees messages
from both stores. Changing stores requires explicit reconciliation and catch-up
verification; do not silently discard an invalid saved cursor.

## Activation acceptance

Before calling Muse connected: reconcile deployed source, deploy adapter and
worker together during a coordinated restart, complete private pairing, then
have Muse list rooms, catch up, post to `muse-onboarding`, disconnect, and catch
up again. Confirm sender `muse` before and after refresh; confirm app and scope
calls are denied. Verify Claude and Codex can still participate. Never claim
production success from the isolated unit tests.

<!-- ===== SNAPSMACK EOF ===== -->
