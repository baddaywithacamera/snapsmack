<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# LEWK AGAIN

**Status:** FIRST USEFUL VERSION IMPLEMENTED
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
- The first version is text-only. It never uploads photographs; providers receive only
  the photographer's written request and, during refinement, the validated recipe.

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

## Open decisions

- Local or cloud is user-selectable: Gemini, Kimi, DeepSeek, Claude, OpenAI, or a local
  OpenAI-compatible endpoint.
- Whether recipes support masks and subject-aware adjustments in the first release.
- Exact Lightroom-compatible preset version and metadata fields to target first.

<!-- ===== SNAPSMACK EOF ===== -->
