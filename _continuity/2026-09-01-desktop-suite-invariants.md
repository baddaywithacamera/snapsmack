# DESKTOP SUITE CONTINUITY — icons, instructions, and build preservation

Recorded 2026-09-01 at Sean's direction. This is a standing rule for every
SnapSmack desktop companion on every supported desktop platform.

## Non-negotiable preservation rule

Any change to a desktop-suite application must preserve:

1. **Its application identity and icons.** Preserve the source icon asset, the
   executable/bundle icon, the window icon, the taskbar/dock icon, shortcuts, and
   launcher/SNAP HQ presentation. A rebuilt application showing a generic
   Python, Tk, Qt, browser, terminal, or blank icon is a regression.
2. **Its operating and build instructions.** Preserve and update the tool's
   README/spec, dependencies, build command, output location, install location,
   launcher contract, and any platform-specific icon wiring. Instructions must
   describe the actual current build and must not be discarded during a port,
   refactor, packaging change, or framework replacement.
3. **Continuity.** Before handoff, the worker must record material desktop changes,
   unresolved items, exact staged/installed state, verification performed, and
   the relevant commits in `_continuity/`. A chat statement or private to-do is
   not sufficient continuity.

## Required verification before calling a desktop build complete

- Inspect the running window and OS taskbar/dock; do not infer icon success from
  the presence of an asset file.
- Inspect the built executable/bundle and installed launcher/shortcut.
- Confirm SNAP HQ still displays and launches the tool with the intended icon.
- Confirm build and install instructions point to real paths and reproduce the
  artifact.
- Record any failure or deferred work here before leaving the task.

## Current known exception — CRONOMETER

CRONOMETER's missing application icon is **not fixed** as of this entry. Its
Windows PyInstaller recipe has no icon configured and `tools/cronometer/` has no
icon asset directory. The earlier item was only a to-do while another worker was
changing the application. Do not report it fixed until the executable, window,
taskbar, SNAP HQ launcher, and installed build have been visually verified.

## Image-sizing decision status

The 4K orientation rule remains open. Sean clarified that the original
`3840×2160` proposal was intentionally landscape-display-first, in the tradition
of desktop software designed around landscape monitors rather than smartphones.
He also identified equal long-edge pixel retention as legitimate future-proofing.
Do not present either policy as decided until Sean chooses between:

- landscape display envelope: landscape width ≤3840 and portrait height ≤2160;
- equal long edge: either orientation's long edge ≤3840.

Present both with concrete landscape and portrait output dimensions. Keep display
layout policy separate from retained-file resolution policy; a landscape-oriented
interface does not by itself require permanently reducing portrait source pixels.

