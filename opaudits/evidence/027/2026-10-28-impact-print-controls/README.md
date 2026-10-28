# SS-1032: IMPACT PRINTER archive dropdown and print controls

Candidate, not installed. Live pixhellated.ca inspected on 28 October 2026:
CMS 0.7.963D, skin 2.1.18. The filter markup exists but page-archive.css and
ss-engine-archive-filter.js are absent, exposing all filter options in the row.
The repository candidate manifest 2.1.19 already declares both assets.

Skin CSS now gives buttons, inputs, selects, placeholders, status text and code
the DotMatrix font, with dashed paper-style borders, square corners and a
printed X checkbox. Existing shared filter engine provides the dropdown;
no new executable skin code or custom JS was added.

Review tool: tools/browser-tests/review-impact-print-controls.cjs. It loads
actual public markup, intercepts candidate skin CSS and supplies the two
candidate-manifest assets locally. No server files or settings are changed.
At 1920, 1440 and 1024 it checks initial collapse, opening, Escape, keyboard
activation, panel bounds, actual loaded DotMatrix font and computed control
fonts. 1024 also exercises tap. First24 photo dimensions remain unchanged.
Real images are decoded and fade allowed to finish before captures.
Final1920 closed and1440 open screenshots were opened and visually inspected.
Existing full-render filter/search query-state regression also passes.

This is targeted controls acceptance, not a whole-skin tablet audit. The
1024 capture exposes existing oversized archive columns/header width; those
remain outside this control patch. Installed-package verification is pending;
ticket stays open and Claude owns manifest versioning/baseline integration.

<!-- ===== SNAPSMACK EOF ===== -->
