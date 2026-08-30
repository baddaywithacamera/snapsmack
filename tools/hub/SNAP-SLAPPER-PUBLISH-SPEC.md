<!--
SNAPSMACK_EOF_HEADER
Last non-empty line must be the canonical HTML-comment EOF marker.
-->

# SNAP SLAPPER — Picasa-Style Web Publishing and Simple Site Setup

Status: deferred product specification; not approved for current release  
Owner: SNAP SLAPPER + THE HUB  
Target: post-closed-beta development  
Date: 2026-08-29

## 1. Decision

SNAP SLAPPER should provide a Picasa-style **Publish to SnapSmack** experience.
The photographer selects photographs, chooses a destination, and publishes without
working through a conventional web-admin interface.

For photographers who do not yet have a site, publishing may offer **Create a simple
SnapSmack site**. This is a guided deployment profile, not a second SnapSmack edition
and not a server-administration console inside SNAP SLAPPER.

No part of this feature is to be implemented for the current release. Current work may
preserve extension points, but must not add unfinished buttons, dormant setup screens,
or partial server changes.

## 2. Product promise

The ordinary repeat workflow is:

1. Select one or more photographs.
2. Press **Publish**.
3. Choose a remembered site and album/blog destination.
4. Confirm title, caption, visibility, and image size.
5. Publish in the background.
6. Receive a clear success result with **View post** and **Copy link**.

After first-time setup, a normal publish should require no hosting vocabulary and no
more than one confirmation screen.

## 3. First-use choices

The first Publish action offers exactly two primary choices:

### Connect to my SnapSmack

For an existing site. The photographer supplies the site address and authorizes SNAP
SLAPPER. Preferred authorization is a short-lived browser pairing flow that returns a
revocable application token. A manually created application password may be offered as
a fallback.

SNAP SLAPPER verifies the endpoint, account identity, permissions, upload limits,
available destinations, and supported publishing features before saving the profile.

### Create a simple SnapSmack site

For supported hosting. The photographer supplies only:

- site/domain address;
- site title;
- administrator name and email;
- hosting connection, chosen from a supported provider or SFTP;
- database details only when the host cannot provision them automatically.

An **Advanced setup** disclosure may expose paths, ports, database prefixes, cron,
mail, proxy, and federation settings. These fields must never appear in the ordinary
path unless required by the selected host.

## 4. Ownership boundaries

THE HUB owns site installation, credentials, connection profiles, upgrades, health
checks, and recovery. SNAP SLAPPER owns photograph selection, preparation, metadata,
upload queue, and publishing results.

SNAP SLAPPER invokes a versioned HUB service or shared library. It must not contain its
own SFTP client, database installer, cron editor, or duplicate credential store.

The deployed result is a normal SnapSmack installation using the same code, database
schema, updater, federation behavior, and themes as a manually installed site. “Simple”
describes the setup experience, not a reduced or incompatible product fork.

## 5. Simple installation transaction

THE HUB performs setup as an explicit, resumable transaction:

1. Preflight DNS, TLS, hosting access, PHP/runtime requirements, database access,
   writable paths, available disk space, outbound networking, and existing files.
2. Show the exact destination and planned changes.
3. Back up or refuse to touch any existing deployment; never overwrite silently.
4. Upload a signed, checksummed release package.
5. Create configuration and secrets outside the public web root where supported.
6. Initialize the database with an idempotent migration.
7. Create the administrator and a scoped desktop publishing token.
8. Install the supported scheduler mechanism and verify that it actually runs.
9. Run public URL, API, media upload, federation, relay, and background-job checks.
10. Commit the connection profile only after all required checks pass.

If any step fails, the wizard reports the failing step in plain language, preserves a
diagnostic log, and offers Retry or Roll Back. A failed install must not be presented as
a usable publishing destination.

## 6. Publishing model

The publishing endpoint accepts a versioned manifest followed by resumable media
uploads. The manifest contains:

- source asset identity and local checksum;
- title, caption, alt text, tags, and capture date;
- blog/album destination;
- draft, scheduled, unlisted, followers-only, or public visibility where supported;
- image rendition policy and optional original-file retention;
- duplicate handling policy;
- stable client operation ID for safe retry.

Default image preparation is non-destructive. SNAP SLAPPER generates an upload copy,
preserves orientation and color profile, removes GPS according to the photographer's
privacy preference, and never alters the source photograph.

Uploads run in the background with per-item progress, Pause, Resume, Cancel, and Retry.
Closing SNAP SLAPPER must not corrupt the queue. Retrying the same operation must not
create duplicate posts or duplicate media.

## 7. Destination and post controls

The confirmation screen provides:

- remembered site and destination;
- new post or add to existing draft/album;
- post title and optional shared caption;
- per-photo caption and alt-text review;
- visibility and publish-now/schedule choice;
- image size: Web optimized, Large, Original, or a saved custom profile;
- GPS/privacy status;
- duplicate warning and resolution.

Advanced controls remain collapsed. Defaults are remembered per destination, with an
obvious **Restore defaults** command.

## 8. Security requirements

- Credentials live in the existing encrypted SnapSmack credential vault owned by THE
  HUB; they are never stored in project files, logs, command lines, or image metadata.
- Publishing tokens are scoped to the minimum required operations and can be revoked
  from both THE HUB and the site.
- Setup packages and updates require signature and checksum verification.
- TLS certificate errors are blocking errors; there is no casual “ignore” button.
- Logs redact tokens, passwords, private keys, database secrets, and personal paths.
- Destructive repair, overwrite, database replacement, and rollback choices require an
  explicit summary and confirmation.

## 9. Health and repair

Every saved site profile exposes a plain-language status:

- Connected;
- Authentication required;
- Site update required;
- Background jobs not running;
- Media storage unavailable;
- Federation/relay degraded;
- Offline or unreachable.

**Test connection** checks the actual publishing path, not merely the home page. **Repair
site** belongs to THE HUB and may fix permissions, reinstall the scheduler, apply safe
migrations, or restore a known-good application package without touching photographs or
posts.

A broken federation relay must not block local publishing. The result reports “Published;
federation delivery delayed” and queues delivery for retry.

## 10. Explicit non-goals

- No general-purpose hosting control panel.
- No bundled public web server or database inside SNAP SLAPPER.
- No separate “SnapSmack Lite” codebase.
- No silent domain purchasing, DNS replacement, destructive overwrite, or host migration.
- No requirement that photographers understand cron, PHP, databases, ActivityPub, or
  reverse proxies for the supported simple path.
- No current-release implementation or UI placeholder.

## 11. Delivery phases

### Phase A — connect and publish

Existing-site authorization, destination discovery, background/resumable uploads,
metadata/privacy controls, drafts, progress, retry, and view/copy-link results.

### Phase B — simple site setup

THE HUB installer transaction for one tightly supported hosting profile, complete
preflight, rollback, scheduler verification, health checks, and automatic connection to
SNAP SLAPPER.

### Phase C — broaden safely

Additional hosting adapters, scheduled posting, album synchronization, publish presets,
site repair, and migration tooling. A new provider is supported only when its install,
upgrade, scheduler, rollback, and health-check paths are automated and tested.

## 12. Acceptance criteria

The feature is ready for beta only when all of the following are true:

- A new photographer can publish to an existing SnapSmack without documentation.
- A repeat publish takes no more than selection, Publish, and confirmation.
- A supported clean host can be installed without exposing advanced fields.
- Interrupted uploads and interrupted installation resume or roll back safely.
- Repeated publish requests are idempotent and do not duplicate content.
- Existing remote files, databases, posts, and media are never overwritten without an
  explicit, specific confirmation.
- Scheduler, publishing API, media delivery, federation, and relay checks produce
  separate, accurate results.
- Losing federation or relay connectivity does not lose a successfully published post.
- Credentials do not appear in logs, configuration exports, process arguments, or crash
  reports.
- Unsupported hosting fails during preflight with a useful explanation and a manual
  install path; the wizard never guesses its way through production changes.

<!-- ===== SNAPSMACK EOF ===== -->
