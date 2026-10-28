# Visible frame edge correction

2.1.37 acceptance aligned text to the img border box and missed the25px yellow outline beyond it. Owner screenshot caught this.2.1.38 reserves the configured outline thickness inside the static hero main-photo box. Actual live markup/CSS replay with fully decoded photograph, screenshots opened and inspected at1920/390. Border widths0/10/25/50 keep both visible outline edges aligned with text; no horizontal overflow. Decorative background engine omitted from isolated replay.

Permanent check tools/browser-tests/rationalgeo-static-frame.cjs loads real styles in live order and rejects the old padding:0 mutation. Included in required browser runner. Scoped to static pages, archive/solo selectors unchanged.

<!-- ===== SNAPSMACK EOF ===== -->
