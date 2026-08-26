<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# SnapSmack Desktop Companion Architecture

**Status:** Governing architecture

**Applies to:** THE HUB, SNAP SLAPPER, LEWK AGAIN, and every SnapSmack desktop companion

**Principle:** The CMS coordinates. Desktop companions do the heavy lifting.

## 1. Purpose

SnapSmack must remain practical on inexpensive shared hosting with constrained CPU, memory, execution time, storage I/O, process control, and background-job support. A feature is not successful if it works only after turning the CMS into a workstation or requiring premium hosting.

The desktop companion suite exists to move expensive, long-running, dependency-heavy, interruptible, and highly interactive work onto the user's own computer. The server receives results that are already prepared for storage, publication, and delivery.

This is not merely a performance optimization. It is a product boundary and a hosting-accessibility commitment.

## 2. Governing rule

When deciding where new work belongs, default to:

- **CMS:** authority, coordination, capabilities, metadata, publication state, lightweight validation, and delivery.
- **Desktop:** creation, transformation, analysis, batching, preview, staging, recovery, and expensive computation.

Any proposal that puts substantial media processing, AI inference, archive construction, recursive scanning, or long-running batch work on the CMS must explain why the desktop cannot do it. Convenience for the implementer is not sufficient justification.

## 3. Work that belongs on the desktop

Desktop companions should perform the following whenever technically possible:

- High-resolution non-RAW image decoding.
- Colour and tonal adjustments.
- Resizing, cropping, sharpening, and format conversion.
- Thumbnail and derivative generation.
- Batch file operations.
- Duplicate and quality analysis.
- AI-assisted metadata, sorting, and adjustment generation.
- LEWK AGAIN recipe generation and preset rendering.
- Large archive and backup construction.
- Compression, checksumming, and integrity verification of large local payloads.
- Local folder scanning and change detection.
- Offline drafting, queuing, and staging.
- Slideshow, preview, contact-sheet, and library rendering.
- Expensive EXIF/media inspection.
- Retryable preparation work that may take minutes or hours.

The desktop may use the user's CPU, GPU, memory, storage, and optional local AI services. It may also use explicitly configured cloud services when the user knowingly chooses them, but cloud use must not become a hidden CMS burden.

## 4. Work that belongs on the CMS

The CMS should remain responsible for:

- Authenticating users and desktop clients.
- Issuing and validating desktop-tool entitlements.
- Reporting the blog's identity, version, capabilities, and constraints.
- Storing canonical post, image, taxonomy, profile, and publication metadata.
- Accepting prepared files through small authenticated API operations.
- Applying lightweight validation to received files and metadata.
- Publishing, scheduling, and changing post state.
- Serving already prepared images and derivatives.
- Federating published content.
- Returning clear, bounded API responses.
- Recording audit-relevant actions and failures.

The CMS may verify dimensions, MIME type, size, checksum, or metadata supplied by a desktop client. Verification must not quietly become a second full image-processing pipeline.

## 5. Work the CMS should not be asked to do

Avoid or reject designs that require budget hosting to:

- Decode or repeatedly transform full-resolution source images.
- Generate large batches of derivatives during a web request.
- Run AI models or install AI runtimes.
- Hold open long-running HTTP requests while work completes.
- Perform recursive remote-library scans on ordinary page loads.
- Build large backup archives in one request.
- Maintain always-running workers, daemons, or process supervisors.
- Depend on shell access, GPU access, uncommon binaries, or generous PHP limits.
- Recreate work the desktop already completed and verified.
- Use the public web process as a job queue.

Where the CMS must support a fallback transformation for compatibility, it should be bounded, defensive, and clearly secondary to the desktop-prepared path.

## 6. Capability exchange

Desktop tools must not hard-code one universal blog configuration. The CMS should expose a small versioned capability description, which THE HUB discovers and stores in shared profiles for the companion suite.

Useful capability fields include:

- Stable installation/profile identifier.
- Human-readable blog name and canonical URL.
- CMS version and API contract version.
- Enabled posting modes.
- Maximum accepted image width and height.
- Maximum upload byte size.
- Accepted MIME types and file extensions.
- Preferred JPEG/WebP quality where relevant.
- Whether a local export requests the single permitted metadata exception: GPS removal
  from the newly created derivative. Other EXIF and embedded metadata are preserved.
- Whether the blog expects a particular colour space.
- Local desktop uploads/staging directory configured for that blog.
- Available authenticated routes and feature flags.
- Entitlement issuance/validation capability.

The CMS reports server truth. Local-only values such as a Windows staging path belong in THE HUB's shared local profile layer, associated with the CMS installation identifier; they must not be written into the public site's configuration merely for desktop convenience.

## 7. Shared-profile rule

THE HUB is the discovery and configuration front door for desktop companions. It should collect each blog's CMS-reported capabilities, combine them with local workstation settings, and expose one shared profile contract.

Companion applications should consume shared profiles rather than maintaining competing copies of:

- Blog lists.
- URLs and installation identifiers.
- Authentication material.
- Maximum image dimensions.
- Supported formats.
- Local upload/staging locations.
- Per-blog export policy.

When a tool needs a field that the shared profile does not yet contain, extend the shared contract deliberately. Do not create an application-specific shadow profile unless the data is genuinely private to that application.

## 8. Local blog-ready save copies

SNAP SLAPPER should eventually support a local-only convenience workflow for offline posting:

1. The photographer saves the finished master normally as TIFF, PSD, or another chosen format.
2. The save/export interface offers `Also save blog upload copy`.
3. The photographer selects a blog profile.
4. SNAP SLAPPER reads that profile's local uploads directory, maximum resolution, accepted format, quality, colour-space, and metadata policy.
5. SNAP SLAPPER creates the web-ready derivative locally.
6. The derivative is placed in the selected blog's local uploads/staging directory.
7. The master remains in its normal location and is never replaced by the derivative.
8. Later, the appropriate offline posting companion finds the prepared image waiting in that directory.

This flow performs no network upload, does not write to the server's filesystem, does not create a post, and does not enqueue publication.

### Required behavior

- Display the selected blog and full local destination before saving.
- Display resulting dimensions, format, quality, colour space, and metadata policy.
- Use collision-safe filenames unless the user explicitly approves replacement.
- Never block or roll back the master save because the optional upload-copy export failed.
- Report a missing, read-only, or unavailable staging directory clearly.
- Preserve an auditable relationship between master and derivative where practical.
- Make the operation repeatable without silently accumulating ambiguous copies.

## 9. Local staging contract

A local uploads directory is a staging inbox, not a server mirror.

- It may live on an internal drive, removable drive, or user-controlled network share.
- Its path is workstation-specific and stored locally.
- A posting tool may consume, copy, or mark staged files according to its own explicit workflow.
- Staged files must not disappear merely because a post attempt fails.
- Posting tools must distinguish prepared, queued, uploaded, published, failed, and user-retained states.
- SNAP SLAPPER should not infer publication success by observing that a file vanished.

## SNAP SLAPPER brush compatibility

The editor will provide a visible brush palette shared by mask painting and retouch tools.
It must include useful built-in hard and soft brushes, readable tip previews, and controls
for size, hardness, opacity, flow, spacing, angle, and rotation.

The palette may import grayscale or alpha PNG brush tips and compatible sampled brush tips
from Photoshop `.abr` files. Imported Photoshop brushes are translated into SNAP SLAPPER's
own non-destructive brush model. Photoshop-only behavior such as mixer brushes, dual-brush
engines, proprietary textures, and application-specific pressure dynamics is not promised
to reproduce exactly; unsupported settings must be reported rather than silently misread.
Imported brushes remain local workstation data and are never uploaded to the CMS.

## 10. Failure and recovery semantics

Desktop-heavy architecture is valuable only if failures are recoverable.

- Preparation output should use temporary files plus atomic final placement where the filesystem permits.
- Long operations should expose progress and cancellation.
- Retrying must not duplicate posts or overwrite masters.
- Network failure occurs after local preparation, so prepared work remains available.
- CMS rejection should return a precise constraint mismatch that the desktop can correct locally.
- Partial upload cleanup must be explicit and safe.
- Operations should produce enough local state to resume without rescanning or regenerating everything.
- User files remain usable when entitlement, CMS, or network state changes.

## 11. Security boundary

- Keep CMS credentials in the existing shared credential system, not in staging filenames or image metadata.
- Treat shared profiles as sensitive local configuration when they contain authentication material.
- Validate every server response before using paths, dimensions, filenames, or policy values.
- A CMS capability response must never be allowed to select an arbitrary local output path. The local staging path is chosen and stored on the workstation.
- Do not execute generated scripts, actions, or downloaded binaries as part of media preparation.
- Use authenticated, narrowly scoped API operations for eventual upload/publish actions.
- Keep entitlement signing secrets on the CMS; desktop applications receive verification material or server validation only.
- Avoid exposing local absolute paths in remote logs or API payloads unless operationally necessary and explicitly designed.

## 12. Privacy boundary

- Local processing is the default.
- AI features must state whether inference is local or cloud-based.
- Photographs are never uploaded to an AI provider without explicit informed approval.
- Originals are immutable. New derivatives preserve all available EXIF and embedded
  metadata; GPS is the only removable field and only after an explicit local preference
  or export choice. GPS removal never changes the original.
- Local analysis indexes, thumbnails, and recipes remain local unless the user exports or synchronizes them deliberately.

## 13. Entitlements and product support

SNAP SLAPPER and LEWK AGAIN are included free for people running their own SnapSmack CMS installation. The CMS-issued entitlement limits the official support population to SnapSmack users; it is not a paid subscription.

- SNAP SLAPPER carries the desktop entitlement.
- LEWK AGAIN reads SNAP SLAPPER's locally verified unlock state and never requires key
  re-entry, a second key, or a duplicate entitlement clock.
- Entitlement expiry must not delete or encrypt local work or exported output.
- Decoupled forks may exist under the SPL but must use different tool names and own their support surface.

See `docs/desktop-tool-entitlements.md` for term, grace, renewal, fork, and support rules.

## 14. Hosting budget test

Every feature proposal affecting the CMS or a desktop companion should answer:

1. What CPU-heavy work occurs?
2. What peak memory is required?
3. How long can the operation run?
4. How much temporary storage is required?
5. Does it require uncommon server binaries or PHP extensions?
6. Can it survive ordinary shared-hosting execution limits?
7. Can the desktop prepare the result instead?
8. Can the CMS reduce its role to capability reporting, validation, storage, and state change?

If the answers imply that a low-cost shared host performs workstation-class work, redesign the feature.

## 15. API design guidance

Prefer small, idempotent operations:

- `GET` capabilities/profile facts.
- `POST` a prepared file with declared dimensions, MIME type, byte size, and checksum.
- `POST` or `PATCH` bounded metadata.
- `POST` a publish/schedule state transition after assets exist.
- `GET` status for a specific operation or asset.

Avoid giant “do everything” requests that upload originals, transform them, generate metadata, create a post, federate it, and wait for completion inside one web process.

Contract versions should advance explicitly. Desktop clients must produce a useful “CMS/tool version mismatch” message rather than falling through to generic upload failure.

## 16. Review checklist

Before merging a companion-app or CMS integration feature, confirm:

- [ ] Expensive work is local unless a documented exception exists.
- [ ] CMS work is short, bounded, and safe on budget shared hosting.
- [ ] Server capabilities are discovered rather than guessed.
- [ ] Workstation-specific paths remain local.
- [ ] Originals and masters cannot be overwritten accidentally.
- [ ] Local preparation survives network failure.
- [ ] Upload and publication are explicit separate actions where appropriate.
- [ ] Retries are idempotent or collision-safe.
- [ ] The feature does not broaden official support beyond SnapSmack users.
- [ ] Security and privacy implications are visible to the user.
- [ ] Failure messages identify the responsible layer: desktop, local filesystem, network, CMS validation, entitlement, or publication.

## 17. Decision summary

SnapSmack deliberately spends the user's local computing resources to protect the viability of their inexpensive hosting. Desktop companions are not decorative front ends to server jobs. They are the workhorses. The CMS remains the small, authoritative coordinator that stores and publishes already prepared work.

When in doubt: make it locally, verify it locally, stage it locally, then send the server only what it needs.

<!-- ===== SNAPSMACK EOF ===== -->
