<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# LEWK AGAIN

**Status:** TO DO / concept  
**Former working name:** ACTION LAB  
**Type:** SnapSmack-exclusive AI tool  
**Core promise:** Describe the photographic look or correction you want. LEWK AGAIN
builds an editable LEWK and places it in SnapSmack's local LEWKS folder.

## The pitch

Stop buying expensive action and preset packs just to get one useful treatment. Tell
LEWK AGAIN what you want in ordinary language—such as “muted winter documentary colour,
protect skin tones, lift deep shadows, add restrained grain”—and it creates a reusable,
inspectable LEWK you own.

LEWK AGAIN belongs exclusively to SnapSmack. It is not a general-purpose standalone
generator. A completed LEWK may be emitted in a Lightroom-compatible preset format;
compatibility belongs to the output, not to the application.

## Shared authentication contract

LEWK AGAIN never asks for, stores, validates, or renews a second entitlement key. On
startup it reads SNAP SLAPPER's locally verified entitlement result.

- Valid SNAP SLAPPER entitlement unlocks LEWK AGAIN.
- SNAP SLAPPER grace state unlocks LEWK AGAIN with the identical warning, expiry, and
  grace boundary.
- Missing, invalid, or expired-beyond-grace SNAP SLAPPER authentication blocks protected
  LEWK AGAIN features and directs the user to authenticate in SNAP SLAPPER.
- Renewal in SNAP SLAPPER becomes available to LEWK AGAIN without re-entering a key.
- LEWK AGAIN does not contact the CMS independently merely to duplicate validation.
- LEWK AGAIN receives only the minimum verified entitlement state. CMS credentials,
  signing secrets, and the raw key are not copied into LEWK AGAIN configuration.
- The shared state must be authenticated and tamper-evident; a writable boolean in a
  local JSON file is not an entitlement check.

The 90-day term, 14-day non-bonus grace period, anchored renewal arithmetic, expiry
behavior, fork-renaming requirement, and support boundary are defined in
`desktop-tool-entitlements.md`.

## First useful version

- Accept a plain-language description of the desired result.
- Build within the SNAP SLAPPER adjustment, filter, mask, and layer model.
- Convert the request into a constrained, inspectable recipe.
- Preview the recipe against one or more user-selected photographs.
- Let the user refine the result conversationally.
- Show every adjustment before installation.
- Save the finished LEWK into SnapSmack's configured local LEWKS folder.
- Preserve the generated recipe as an editable LEWK AGAIN project.
- Never upload photographs unless the user explicitly chooses a cloud model and approves
  the upload.

## Import an existing Lightroom preset or Photoshop action

LEWK AGAIN must let a photographer bring in a Lightroom preset or Photoshop action as
source material for a new native LEWK. The purpose is translation, not emulation: LEWK
AGAIN inspects what the external preset or action is trying to accomplish, compares its
operations with the adjustments, Filters, masks, layers, and blend modes actually
available in SNAP SLAPPER, and proposes the closest safe editable equivalent.

The workflow is:

1. Choose `IMPORT PRESET OR ACTION` and select a supported Lightroom preset or Photoshop
   action file.
2. Parse the file as inert data. Never launch Lightroom or Photoshop, execute an action,
   run embedded scripts, load plugins, or follow network references.
3. Show the detected steps in plain language, preserving their original order when the
   format exposes it.
4. Classify every detected step as `MATCHED`, `REPLACED`, `OMITTED`, or `NEEDS REVIEW`.
5. Use AI assistance to interpret recognizable intent and recommend only operations from
   SNAP SLAPPER's versioned allowlist. AI does not create or execute arbitrary code.
6. Explain every substitution or omission. A replacement identifies both the external
   operation and the SNAP SLAPPER operation proposed in its place.
7. Preview the translated LEWK on user-selected photographs, with before/after comparison
   and access to `SHOW THE GUTS`.
8. Let the photographer edit, approve, or cancel the proposal before it is installed.
9. Save an approved result as a normal editable `.lewk` with provenance identifying the
   source format, source filename, conversion date, and conversion report. Do not embed
   the original proprietary file unless the photographer explicitly chooses to retain it.

Lightroom presets and Photoshop actions are separate import adapters. Lightroom preset
parameters may map directly when SNAP SLAPPER has an equivalent control. Photoshop
actions may contain application commands, plugins, selections, scripts, or recorded UI
steps that have no safe portable equivalent; those steps must remain visible in the
conversion report and may be replaced or omitted only with the photographer's approval.

If an input format cannot be parsed reliably, LEWK AGAIN must say so. It may offer a
guided reconstruction from a written description and reference before/after images, but
must not pretend it understood an opaque or unsupported action.

### Direct-open interception

People will try to open Lightroom and Photoshop files directly in SNAP SLAPPER. Known
external preset/action extensions must therefore be intercepted before the ordinary
project or LEWK loader attempts to parse them. Do not show a generic invalid-file error
and do not attempt a partial direct import.

The message is:

> This is a Lightroom preset or Photoshop action. SNAP SLAPPER cannot apply it directly.
> Open it in LEWK AGAIN to build a compatible, editable LEWK.

The dialog provides `OPEN IN LEWK AGAIN` as the primary action and `CANCEL` as the safe
secondary action. If LEWK AGAIN is unavailable, disabled, or not yet installed, replace
the primary action with a plain explanation of what is required. Never imply that every
external operation can be reproduced. After conversion, the report is the authoritative
record of what matched, changed, or could not be carried across.

## Output contract

- Every LEWK uses SnapSmack's native editable recipe internally.
- A completed LEWK may be emitted as a Lightroom-compatible preset.
- Generation, refinement, storage, and installation remain locked to SnapSmack.
- The compatible preset is portable output; LEWK AGAIN is not packaged or supported as
  a standalone Lightroom add-on.

## Guardrails

- Generated LEWKS are non-destructive by default.
- No arbitrary scripts or executable code are generated into the LEWKS folder.
- Originals are never overwritten during preview or export.
- Installation requires a visible summary and confirmation.
- Every installed LEWK can be removed or exported.
- AI suggestions are editable starting points, not magic or objective corrections.
- Imported presets and actions are data to inspect, never trusted programs to execute.
- A conversion cannot silently discard, approximate, reorder, or flatten an unsupported
  external step.
- Preview and installation use the same native SNAP SLAPPER renderer used after import.

## Import acceptance criteria

- A photographer can select a supported Lightroom preset or Photoshop action from LEWK
  AGAIN without opening either originating application.
- Opening a recognized external preset/action in SNAP SLAPPER produces the explanatory
  interception dialog and can hand the file to LEWK AGAIN without first reporting it as
  a malformed SNAP SLAPPER project.
- The conversion screen lists every recoverable source step and its translation status.
- Unsupported or unsafe steps are plainly identified and cannot execute.
- Each replacement explains what changed and remains editable before approval.
- Cancelling leaves the LEWKS folder and current SNAP SLAPPER project unchanged.
- An approved conversion installs a valid native `.lewk`, includes a readable conversion
  report, and can be inspected with `SHOW THE GUTS`.
- The same source file and supported-operation versions produce the same proposed native
  operation graph before optional AI refinements.

## Open decisions

- Local model, cloud model, or user-selectable hybrid.
- Whether recipes support masks and subject-aware adjustments in the first release.
- Exact Lightroom-compatible preset version and metadata fields to target first.
- Lightroom import formats and versions to support first (`.xmp`, legacy `.lrtemplate`,
  or both).
- Which Photoshop `.atn` records can be decoded reliably enough for the first importer,
  and whether before/after reference images are required for opaque steps.

<!-- ===== SNAPSMACK EOF ===== -->
