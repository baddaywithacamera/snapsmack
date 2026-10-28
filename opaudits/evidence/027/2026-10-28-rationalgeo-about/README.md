# RATIONAL GEO About alignment

Actual wateronthebrain.ca/about markup and loaded styles replayed in isolated Edge. Before and after screenshots inspected at1920 and390. Only the two changed static-page selectors are added for the after case. Scripts omitted, so the decorative background engine is absent in this comparison; no claim of full-page engine parity.

Owner reference: projects/snapsmack-ca/img/rationalgeo-page.png. Its photo and reading text share a left edge. At1920 the live before image is960px wide while text is772px wide; after both960px and same x480 (isolated browser has no15px native scrollbar). At390 after both310px and x40. No horizontal overflow.

Root cause: shared page-static.css loads after the skin and overrides its low-specificity content/title defaults. Preserve skin width, padding and title by scoping existing declarations to body.static-transmission. No shared stylesheet edits.

<!-- ===== SNAPSMACK EOF ===== -->
