<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the canonical HTML comment. -->

# SnapSmack Photo Editor — build map

This directory is the handoff surface for the standalone SnapSmack photo editor.
The editor is a differentiating desktop darkroom for rendered photographs, not a
RAW developer or a Photoshop clone. It launches alone, from THE HUB, or against a
specific photograph from COLD SNAP or GYSS.

Read `PHOTO-EDITOR-SPEC.md` first. Build packets are intentionally independent,
ordered, and acceptance-gated so Codex or Claude can resume without reconstructing
prior decisions:

1. `work-packets/01-foundation-and-license.md`
2. `work-packets/02-processing-core.md`
3. `work-packets/03-darkroom-controls.md`
4. `work-packets/04-layers-and-projects.md`
5. `work-packets/05-layer-styles-and-masks.md`
6. `work-packets/06-suite-integrations.md`
7. `work-packets/07-linux-blink.md`
8. `work-packets/08-release-qa.md`

Each packet must be committed separately. A packet is not complete merely because
the UI exists: its acceptance tests and handoff notes must pass. Never start a later
packet by silently changing a frozen contract from an earlier one.

<!-- ===== SNAPSMACK EOF ===== -->
