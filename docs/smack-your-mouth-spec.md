<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->
# SMACK YOUR MOUTH

## Tool specification (draft)

Status: planned desktop tool
Type: standalone offline desktop app (Python, same family as COLD SNAP)
Launched by: THE HUB (or run standalone)
Last updated: 2026-08-13

## 1. What it is

**SMACK YOUR MOUTH** is offline, batched comment control for the whole fleet: pull every
blog's pending comments down in one shot, **moderate and reply while disconnected**, then
sync the decisions back up on the next connection. It is the inbound twin of COLD SNAP —
COLD SNAP pushes posts out offline; SMACK YOUR MOUTH brings the conversation in.

## 2. Why it exists (the real reason)

Connectivity comes in **short windows** — ten minutes at a coffee shop, then offline again.
The web moderation queue (`smack-multisite-comments.php`) makes you do the work *while
connected*: you race the clock and the wifi to approve and type replies. That is the wrong
shape for intermittent connectivity.

SMACK YOUR MOUTH decouples the work from the window:

- **Pull** the queue in seconds while connected.
- **Work** it offline, at leisure, no clock.
- **Sync** the decisions back in one burst next window.

It also adds the thing the web queue does not have: **replies**. You can moderate the fleet
from one place today, but you cannot *engage* it from one place — this closes that.

## 3. Flow

1. **Connect + pull.** Using the fleet already discovered by THE HUB (shared profiles +
   keys), pull each spoke's pending/recent comments — comment text, the post it is on, and
   enough thread context to reply — into a local, resumable session.
2. **Work offline.** For each comment: **approve / delete / mark spam**, and optionally
   **write a reply**. All local, no network.
3. **Sync back.** On the next connection, push the decisions and replies to each originating
   spoke, with **positive verification** (confirm the action landed before marking it done —
   the SYBU/COLD SNAP lesson: never infer success from no-error).

## 4. What it reuses (pencil, not a rebuild)

- **COLD SNAP's store-and-forward SyncEngine** (`sumna_offline.py`) — the local session
  model, export/import, and positive-verification sync. Shared as a library; SMACK YOUR
  MOUTH is its **own** exe, not a tab inside COLD SNAP (COLD SNAP stays single-purpose).
- **`smack-multisite-comments.php`** already **pulls pending comments from all spokes and
  proxies approve/delete back** — the transport exists. The net-new server work is a
  **reply** endpoint (post a reply comment on a spoke) if one is not already exposed.
- **THE HUB discovery** for the fleet list + per-spoke keys (arm's-length, read/moderate
  scope only — no broad CMS credentials).

## 5. Out of scope (v1)

- Composing new posts (that is COLD SNAP).
- Community/forum threads beyond blog-post comments (later, if wanted).
- Real-time / always-connected moderation (the web queue already covers that).

## 6. Open questions

- Does a per-spoke **reply** API already exist, or is that the one server-side addition?
- How much thread context to pull for a useful offline reply (parent comment only, or the
  whole thread on that post)?
- Reply identity: replies post as the site owner/admin actor — confirm the actor + how it
  federates.
- Name: **SMACK YOUR MOUTH** (working); alt considered: THE LAST WORD.

<!-- ===== SNAPSMACK EOF ===== -->
