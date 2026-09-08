# Claude Code Handoff — Fleet Follow Mesh Stuck at 596/600

Date: 2026-09-08  
Priority: Release blocker  
Related spec: `SPEC-fleet-follow-mesh-completion-v0_1.md`

## TL;DR

The production fleet follow mesh is **not complete**. A fresh authenticated,
actor-URL audit currently proves **596 of 600** required internal directed
relationships.

Four exact relationships remain absent at the receiving site:

1. `https://pixhellated.ca/ap/actor` → `https://curbappeal.photoblogs.fyi/ap/actor`
2. `https://theschoolofhardnocks.ca/ap/actor` → `https://curbappeal.photoblogs.fyi/ap/actor`
3. `https://usedcarparts.photoblogs.fyi/ap/actor` → `https://curbappeal.photoblogs.fyi/ap/actor`
4. `https://wateronthebrain.ca/ap/actor` → `https://curbappeal.photoblogs.fyi/ap/actor`

Each source accepted a manual follow submission and displayed “Follow sent,”
but curb appeal still records only 20 of its 24 production peers. Source-side
delivery evidence shows the Follow to curb appeal receiving **HTTP 429**.

Do not report this repaired until a new exact audit proves **600/600 accepted**,
with zero missing, pending, rejected, or unreachable edges.

## What was verified live

- Full fleet size: 25 active production sites.
- Required directed internal relationships: `25 × 24 = 600`.
- Accepted relationships proved by receiver-side actor URLs: 596.
- FoundTextures receiver set: verified 24/24.
- Craptasti receiver set: verified 24/24.
- Curb Appeal receiver set: verified 20/24.
- All other receiver sets: previously verified 24/24 in the same fresh audit.
- Curb Appeal Ban Manager: **0 active bans**.
- Curb Appeal was observed running SnapSmack **0.7.668D**.

The four targeted manual submissions were made again through each source’s
authenticated Fediverse form. All four forms returned the same success-looking
message:

> Follow sent to curbstomped@curbappeal.photoblogs.fyi — it shows as PENDING
> until their server accepts (usually seconds).

Receiver-side verification after those submissions still showed none of the
four actors. Therefore the form message proves only local queuing/attempt, not
remote acceptance.

On Pixhellated’s authenticated Delivery Log, the queued Follow to Curb Appeal
showed:

- Activity: Follow
- Status: waiting
- Attempts: 1
- Error: `HTTP 429`

The same destination also has numerous queued Create deliveries carrying the
same 429 response.

## Root cause

`core/fediverse.php::sv_inbox_rate_ok()` applies one `fediverse_inbox` bucket per
resolved client IP:

- soft cap: 60 requests per 10 minutes;
- ban threshold: 180 requests per 10 minutes;
- the check occurs before body parsing and signature verification.

The production sites share outbound infrastructure/addressing. Curb Appeal
therefore sees legitimate traffic from many fleet sites as one sender. Create
and backfill traffic consumes the same 60-request bucket needed by Follow and
Accept control handshakes. The receiver returns 429 before the Follow reaches
`sv_handle_inbox()`, so the follower row is never created and no Accept can be
returned.

The newer sender-side 429 handling is useful but insufficient. It correctly
backs off and preserves delivery jobs, yet it cannot complete the mesh while
control traffic competes with the catalogue backlog inside the receiver’s
single inbox bucket.

## Required repair

### 1. Prevent content traffic from starving control handshakes

Give signed ActivityPub control activities—at minimum `Follow`, `Accept`,
`Reject`, and `Undo`—a small, independently bounded receiver allowance separate
from Create/Announce content.

Security constraints:

- Do not provide an unlimited bypass.
- Do not trust an unverified actor identity.
- Preserve body-size, SSRF, replay, signature, and IP-ban protections.
- Avoid turning a forged `type: Follow` body into a free expensive-signature
  denial-of-service path.
- If the body must be parsed to classify traffic, retain an outer coarse IP
  ceiling before verification and apply a second type/actor-aware limit after
  verification.
- A verified active fleet actor may receive a conservative fleet-control
  allowance, but ordinary external actors must remain safely bounded.

Recommended shape:

1. Keep a generous coarse pre-verification per-IP ceiling that stops floods but
   does not collapse normal shared-host fleet traffic at 60 requests.
2. Parse and verify the request.
3. Apply separate post-verification buckets for content and control traffic.
4. Key verified fleet control traffic by canonical actor URL or actor host, not
   solely by the shared client IP.
5. Return `Retry-After` on 429 so sender scheduling has authoritative timing.

### 2. Make “Follow sent” truthful

`sv_follow_actor()` currently returns a success-looking message even when the
targeted immediate delivery fails with 429. Return structured delivery state:

- delivered/awaiting Accept;
- queued after 429, including next retry;
- failed, including the exact error;
- accepted, only after the matching Accept is recorded.

The UI must not imply the remote server received the Follow merely because the
local row was queued.

### 3. Preserve control priority end to end

The sender already prioritizes control activities in the queue. Confirm that:

- a targeted retry selects the intended Follow row;
- a pre-existing deduplicated/stale Follow job cannot cause the new follow ID
  to be skipped;
- host cooldown does not bury all control traffic behind hundreds of Creates;
- normal cron retries pending fleet follows after the receiver-provided delay.

### 4. Implement the authoritative mesh tooling in the related spec

Complete the read-only `mesh-status`, bounded `mesh-reconcile`, and hub matrix
driver described in `SPEC-fleet-follow-mesh-completion-v0_1.md`. Public follower
counts and successful form submissions are not acceptable proof.

## Safe production completion procedure

1. Add regression tests for shared-IP content saturation plus a valid Follow.
2. Prove malformed/unsigned control-shaped requests remain bounded and rejected.
3. Deploy to one test-lab receiver and reproduce the saturated-content case.
4. Verify a signed Follow reaches the handler, creates one active follower row,
   returns one Accept, and creates exactly one durable backfill job.
5. Deploy the receiver fix to Curb Appeal.
6. Re-send only the four listed Follow relationships; do not fan out another
   fleet-wide catalogue operation.
7. Read Curb Appeal’s exact follower actor URLs and verify 24/24 internal peers.
8. Re-run the complete 25-site matrix audit twice.
9. Declare completion only at 600/600 accepted and zero missing/pending/rejected/
   unreachable edges, with external follows unchanged.

## Regression tests required

- Sixty-plus valid Create deliveries sharing one client IP do not permanently
  starve a valid signed Follow from a distinct fleet actor.
- Control allowance remains finite and cannot be expanded merely by claiming an
  unverified ActivityStreams type.
- A receiver 429 leaves the Follow durable and retryable without incrementing it
  toward the failed/parked cliff.
- A retry uses the current follow ID and a matching Accept changes only that row
  to accepted.
- Duplicate Follow delivery does not create duplicate follower or backfill rows.
- External followers remain untouched by mesh reconciliation.
- Exact fleet audit reports 600/600 only when every canonical actor URL exists in
  the correct accepted state.

## Explicit non-claims

- The fleet is **not** fully cross-followed yet.
- “Follow sent” did **not** prove remote receipt.
- Sender-side 429 backoff alone did **not** repair the receiver-side starvation.
- No active IP ban was found on Curb Appeal.
- Likes remain a separate unresolved workstream.

<!-- ===== REPORT EOF ===== -->
