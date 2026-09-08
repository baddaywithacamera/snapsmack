# Fleet Follow Mesh Completion and Verifiable Repair — v0.1

Date: 2026-09-08  
Priority: Release blocker  
Scope: SnapSmack hub/spoke federation follow mesh

## Non-negotiable outcome

For a fleet of `N` active, federation-enabled SnapSmack sites, every site must
have an accepted follow relationship to every other site. Self-follows are
excluded. The expected directed-edge count is therefore:

`N × (N - 1)`

The current 25-site fleet requires exactly **600 accepted internal directed
relationships**. External follows are neither counted nor modified.

The repair is not complete because a command returned success. It is complete
only when an exact actor-URL comparison proves that all 600 internal edges are
accepted and zero are missing, pending, or rejected.

## Verified incident baseline

An authenticated, per-site audit on 2026-09-08 compared each site's accepted
incoming follower actor URLs with the canonical 25-site roster.

- Expected internal directed edges: 600
- Accepted internal directed edges at the audit: 557
- Missing internal directed edges: 43
- Sites with a complete 24-of-24 incoming peer set: 20
- Destinations with missing incoming peers: FoundTextures, Craptasti, Curb
  Appeal, Dithering in the Kitchen, and Squared Straight
- Source sites requiring one or more outgoing follow repairs: 16

This replaces the earlier unverified claim that all 600 relationships existed.
Public follower totals are insufficient evidence because they include external
followers; exact canonical actor URLs must be compared.

## The defect

### 1. Roster membership is not relationship reconciliation

Adding or synchronizing a site in `snap_multisite_nodes` makes it visible to
fleet tools but does not prove that every existing site follows it or that it
follows every existing peer.

### 2. Repair is coupled to an unrelated heavy queue drain

`sv_reconcile_mesh_follows()` correctly adds at most one missing peer, but its
normal interactive path is `sv_run_sweep()`. That sweep first calls
`sv_process_deliveries(..., 1000, ..., 240)`. A request intended to add one
missing follow can therefore spend up to four minutes draining unrelated
delivery and backfill traffic before reconciliation is reached.

Observed result: authenticated RUN FEDIVERSE JOBS NOW requests took minutes,
timed out at the browser boundary, or reported the worker already busy. The
operator cannot tell whether the follow step ran.

### 3. The hub job button does not perform the advertised repair

The hub's `multisite/jobs/run` endpoint currently refreshes a spoke's roster
only. It deliberately does not run `sv_run_sweep()` and therefore does not add
missing follow relationships. A fleet-wide job action can finish successfully
while the peer mesh remains incomplete.

### 4. There is no authoritative mesh report

The hub reports site health and public follower totals, but does not calculate
the exact directed relationship matrix. Totals cannot distinguish internal
peers from Mastodon, Pixelfed, PhotoFriday, or other external followers.

### 5. Pending or rejected rows can block healing

The existing reconciler builds its `existing` set from every
`snap_ap_following.actor_url`, regardless of state. A stale `pending` or
`rejected` internal peer is therefore treated as present and is never retried.

## Required repair

### A. Add an exact, read-only spoke mesh-status endpoint

Add authenticated endpoint:

`GET api.php?route=multisite/fediverse/mesh-status`

It returns only fleet-internal relationships by comparing canonical roster
actor URLs with `snap_ap_following`:

```json
{
  "ok": true,
  "self_actor": "https://example.ca/ap/actor",
  "expected": 24,
  "accepted": 20,
  "pending": 1,
  "rejected": 0,
  "missing": ["https://peer.example/ap/actor"],
  "states": {
    "https://peer.example/ap/actor": "accepted"
  }
}
```

Requirements:

- Canonicalize scheme, host, trailing slash, and `/ap/actor` consistently.
- Ignore self and external accounts.
- Report accepted, pending, rejected, and absent separately.
- Never create, update, or delete data.

### B. Add a dedicated bounded reconciliation endpoint

Add authenticated endpoint:

`POST api.php?route=multisite/fediverse/mesh-reconcile`

Body:

```json
{
  "limit": 1,
  "actors": ["https://peer.example/ap/actor"]
}
```

Requirements:

- Hub-authenticated only.
- Additive only: never remove external or internal follows.
- Skip self.
- Accept either a targeted actor list or the spoke's calculated missing list.
- Clamp each call to a small limit, initially 1–5.
- Perform actor validation and send only the selected Follow handshake(s).
- **Do not call `sv_run_sweep()` and do not drain the generic delivery queue.**
- Return per-actor results including queued/sent follow ID and resulting local
  state.
- Treat an accepted row as complete.
- Treat a recent pending row as pending, not complete.
- Permit an explicit retry of stale pending rows after a conservative age.
- Preserve rejected rows unless an explicit operator retry is requested.

### C. Add a hub-owned matrix auditor and repair driver

The Multisite Management hub must:

1. Fetch `mesh-status` from all active federation-enabled nodes.
2. Construct the full `N × (N - 1)` directed matrix.
3. Display exact totals: expected, accepted, pending, rejected, missing, and
   unreachable.
4. List every missing edge as `source → destination`.
5. Offer **REPAIR MISSING FOLLOWS** only after the read-only audit completes.
6. Call each affected source's dedicated reconcile endpoint in bounded rounds.
7. Limit concurrency and introduce jitter so no destination receives a burst.
8. Re-audit after every round and stop sending work to sources that are complete.
9. Resume safely after interruption; the operation must be idempotent.

Suggested initial safety limits:

- At most four source sites active concurrently.
- At most one new Follow per source per round.
- Five-to-ten-second jitter between rounds.
- Backfill remains on its separately throttled durable queue.

### D. Decouple follow acceptance from catalogue backfill

A Follow/Accept handshake must not synchronously deliver a catalogue. Once an
Accept is recorded:

- create exactly one idempotent backfill job for that follower;
- process it through the existing paced backfill worker;
- expose queued, running, completed, and failed backfill status separately;
- do not make mesh reconciliation wait for the backfill to drain.

### E. Make normal cron permanently self-healing

Cron may continue calling the bounded reconciler, but it must select only
missing or explicitly retryable fleet actors. Its log line must include the
source actor, destination actor, prior state, action, and result. A busy delivery
queue must not prevent the lightweight mesh reconciliation step from running;
either run reconciliation before delivery draining or give it its own bounded
worker/lock.

## Verification contract

The repair may be called complete only after all of the following pass:

1. Hub roster contains exactly the intended 25 active federation-enabled sites.
2. Every site's mesh-status endpoint answers successfully.
3. Each site reports 24 accepted internal peers.
4. Aggregated matrix reports exactly 600 accepted edges.
5. Aggregated pending, rejected, missing, and unreachable counts are all zero.
6. A second audit produces the same result without creating new work.
7. One controlled new-follow test proves Follow → Accept → one durable backfill
   job → paced delivery → visible remote posts end to end.
8. External follows remain unchanged.

Store the final verification artifact with timestamp, fleet roster, per-site
counts, missing-edge list (empty), and the exact evidence source. Do not infer
completion from public follower totals or successful HTTP responses.

## Immediate operational guidance for the current fleet

Until the dedicated endpoint exists:

- Do not use RUN JOBS — ALL SPOKES as proof of follow repair; it refreshes the
  roster only.
- Do not run all 25 full sweeps concurrently.
- Use the exact 43-edge baseline to target affected source sites only.
- After each bounded run, re-read accepted follower actor URLs at the
  destinations and remove verified edges from the retry set.
- Never publish “all 600 exist” until the final exact matrix audit passes.

## Separate unresolved issue: likes

The operator reports that federated likes behave inconsistently and the current
experience is unwanted. This is recorded as a separate unresolved workstream,
not folded into follow-mesh completion and not diagnosed by this spec. Preserve
like/inbox evidence during the mesh repair. Do not claim likes are working, and
do not expand or redesign them until their actual failure modes and the desired
keep/remove/replace behavior are specified.

<!-- ===== SNAPSMACK SPEC EOF ===== -->
