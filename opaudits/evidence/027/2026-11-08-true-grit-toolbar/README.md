# TRUE GRIT toolbar evidence — SS1256

Source branch: codex/true-grit-toolbar, implementation bf7e109a.

The before/after HTML files are generated local archive fixtures with search and T/M/C exposed. CSS is embedded. SVG placeholders stand in for photographs; these captures prove only toolbar placement, not full skin parity or image layout. External fonts/textures are not bundled. No installed-build or owner-acceptance claim is made.

The portable runner checks four relative clearances at 1920, 1440 and 1024 pixels: toolbar outside the centered inner area, clear of search, inside the viewport, and within the row. run.txt records the successful local execution against after.html. The four PNGs retain the original before/after captures at 1920 and 1024. Earlier absolute pixel ranges are supporting local observations, not additional acceptance requirements.

Run from the repository root:

    node tools/browser-tests/true-grit-toolbar.cjs opaudits/evidence/027/2026-11-08-true-grit-toolbar/after.html

The owner reference was inspected locally but is not bundled and is not offered as independently reproducible parity evidence here. Installed admin save/reload and owner acceptance: NOT CHECKED.

<!-- ===== SNAPSMACK EOF ===== -->
