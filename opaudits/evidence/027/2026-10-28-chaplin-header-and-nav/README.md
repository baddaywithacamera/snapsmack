# CHAPLIN header and carousel navigation review

28 October 2026. Candidate CSS only; no production deployment.

CHAPLIN was rendered on actual acolourlesslife.ca About, blogroll and archive
markup with an isolated response override for the candidate skin stylesheet.
At 1920, 1440 and 1024 pixels, the blogroll heading matches the About heading's
left edge, width, font family, font size, letter spacing and underline width.
The header stays in document flow and the first archive image clears it.
Desktop About/blogroll and 1024 archive screenshots were opened and inspected.

For the enabled state, the existing shared sticky engine was supplied locally,
with opacity 0.3 and blur 8px; archive and About pin after scrolling and compute
those values at all three widths. This checks CSS compatibility, not whether
the strict CMS runtime emits the enabled engine/config. That integration was
sent to Claude's core lane and remains unverified here. No saved site setting
was changed. Header compensating margins/padding were removed with pinning.

SS-1009: JIVE TURKEY, HEURISTIC, INSTANT CAMERA and SLIDERS use the existing
20px flex gap before each pipe and now 20px margin after it. Forty-eight cases
exercise 2, 3, 4 and 5 items at 1920, 1440 and 1024 in touch-capable browser
contexts. Every measured side is 20px within subpixel rounding (tolerance 1px).
These are stylesheet fixtures, not certification of every control on a tablet.

Reproduction: tools/browser-tests/review-chaplin-nav.cjs. This intentionally
uses public live markup, so it is a review tool rather than an offline gate.
Measurements and screenshots are retained here. Skin versions/manifests and
release decisions belong to Claude; installed-site verification remains open.

<!-- ===== SNAPSMACK EOF ===== -->
