<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# PIXEL TICKLER — Codex continuity handoff

**Branch:** `codex/desktop-photo-editor`  
**Worktree:** `C:\dev\snapsmack\.codex-worktrees\desktop-photo-editor`  
**Read first:** `PHOTO-EDITOR-SPEC.md`, `CATALOG-PROJECT-BACKUP-SPEC.md`, then packet handoffs.

## Governing decisions

- Original images are immutable. Remove every replace-original workflow from contracts,
  hosts, integrations and old specification text.
- Basic mode is a friendly photo organizer/editor; Advanced exposes the same project's
  layers. Adjustment layers, nested layer folders and common blend modes are required.
- Right adjustment rail is Camera Raw–influenced but original; essential left dock and
  bottom filmstrip/lighttable can each hide. No traditional top File/Edit menu.
- `.spt` is an open layered master; PSD is optional interchange; TIFF/PNG/JPEG/WebP are
  rendered copies. PSD/export work requires a fresh license/specification review.
- Local catalog backup must integrate explicitly with SMACK UP YOUR BACKUP. Catalog backup
  and original-photo backup are separate promises.
- HEIC/HEIF and RAW development remain excluded. Recommend RawTherapee → 16-bit TIFF.
- No Companion Pass, download gate, API-key expiry or lockout was built. Do not build it
  until the user starts that separate phase.

## Implemented and tested

The native Rust engine handles approved decode/export, geometry, darkroom controls,
vignette, deterministic film grain, detail controls, basic optics, adjustment/text/image
layers, nested groups, five blend modes, opacity/fill and initial layer styles. Projects,
Windows Tauri host, Linux/Blink host, THE HUB registration and a portable lighttable
catalog foundation exist. Last green run had 28 native tests plus JS syntax checks.

Recent commits:

- `440ea33a` deterministic film grain
- `6ea2c44d` inner shadow/glow and style-stack copy/paste
- `c0d75423` portable lighttable catalog core
- `859d6d84` lighttable/layer UX specification

## Work present at handoff

The web UI has an uncommitted/next-commit reorganization: presets moved out of Geometry,
compact context bar, essential left dock, hideable remembered right inspector and `Tab`
panel hiding. `tools/_shared/snap_blink.py` also has a Windows native-dialog owner fix so
Open Image cannot hide behind the Blink window and picker errors are no longer swallowed.

Before extending anything:

1. Inspect `git status` and preserve these changes.
2. Verify Open Image, Open Project, Save Project, Save Copy and overlay selection in the
   freshly launched Python/Blink build on Windows. The user reported Open Image appeared
   dead before the owner-window fix; visual verification is still required.
3. Audit every visible control against a registered JS handler and host method. Static ID
   audit found no unhandled button after the reorganization, but exercise them manually.
4. Commit the picker fix and workspace reorganization separately after verification.
5. Update the old master spec/contracts to remove replace-original language.
6. Review `CATALOG-PROJECT-BACKUP-SPEC.md` with the user before catalog implementation.

## Next implementation order after approval

1. Freeze catalog/project-v2 schemas and backup snapshot contract.
2. Complete visible filmstrip/lighttable over the existing Rust catalog core.
3. Add SQLite rebuildable cross-folder index and durable thumbnail cache.
4. Redesign adjustment-layer targeting; replace “turn last edit into layer.”
5. Add nested layer folders in engine, project schema and UI.
6. Add `.spt` v2 package/migration/validator.
7. Add SMACK UP YOUR BACKUP local snapshot/restore integration.
8. Treat PSD interchange and external-editor round trip as separate reviewed packets.

Do not claim release quality. Perspective correction, full masks/styles, Lightroom XMP,
suite round trips, hardware QA and release packaging remain incomplete.

<!-- ===== SNAPSMACK EOF ===== -->
