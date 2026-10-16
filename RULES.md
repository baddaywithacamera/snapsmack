# RULES — read this first, every session

Sean's rules, written down because agents kept breaking them from memory.
Several of these were agreed, broken, re-agreed, and broken again. Where a
machine can check a rule, the check is named. Where it cannot, that is said.

Last updated: 2026-10-15

---

## 1. Skins are self-contained presentation packs

**Each skin defines its own CSS.** One change must not break ten skins. A few
lines duplicated across skins is cheaper than a shared rule that breaks the
fleet — Sean has said this explicitly: *"a few lines of text isn't too much
to add."*

Do not build a new shared mechanism to solve a per-skin problem. Do not
extend a shared stylesheet to fix one skin.

## 2. No CSS in PHP. No inline styles. No JS in PHP.

- No `style="..."` in any template, static or generated.
- No CSS declarations assembled in PHP strings.
- Colour and geometry belong in the skin's stylesheet.
- The CMS may pass **bounded values** (a word, a token, a number). It may not
  pass CSS.

Checked by `tests/agreed-rules-gate.php` (rules 1 and 2), as a ratchet:
the counts may fall, never rise. Budgets are the measured truth, not targets.

### Per-element values
Where a value genuinely varies per element (per-image tile frames), do **not**
emit CSS from PHP. Quantise to a bounded token set — `data-frame="1".."5"` or
named presets — and let each skin's stylesheet map the token to CSS. Core
passes the token only. If a value is truly free-form, **stop and ask Sean
before building anything.**

## 3. Tags are immutable once built

A tag that has been packaged or deployed **anywhere on the fleet** must never
move. An undeployed number may absorb fixes — that is the fold rule — but
"undeployed" means undeployed *everywhere*.

Check with `bash tools/_build/deployed-version.sh`, which polls twelve live
sites and returns the highest version in service. **A single-site check is not
enough**: on 15 Oct a guard sampled one site on 891D and force-moved v0.7.892D
while twelve sites were already serving it.

This has been broken at least five times. The only real control is
server-side tag protection, which does not exist yet.

## 4. Agents do not deploy

Claude tags. Sean builds in SMACK CENTRAL and uploads. An agent may prepare a
change; it may not ship one.

## 5. A relocation emits byte-identical output

Moving code changes **where it lives**, nothing else. Renaming a class,
dropping an empty paragraph, moving a class off `<body>`, stripping a
modifier — each of those broke a stylesheet nobody had opened and cost Sean a
fortnight. The skins' CSS files **are** the specification for that markup.

Prove it with `tools/_build/skin-render-compare.py`, which compares the
rendered result rather than the markup — deleting an inline style *is* an HTML
change, so an empty-diff gate is useless here.

## 6. Reference version, per skin

The reference is **the commit before that skin's JS was removed** — not a
fixed tag. It differs per skin. The reference is a measuring stick: it is
never restored from and never ships. Old versions predate security fixes.

- 0.7.772D is **not** a clean baseline: 19 of 30 skins already had inline
  styles there. Only GLIDE gained any from the conversion.
- PHOTOGRAM's reference is `510b001b` (before "Confine PHOTOGRAM and ONYX to
  strict runtime"). It cannot be restored — 258 blocking findings against the
  current security gate — so it is a rebuild against it.

## 7. GRAMOFSMACK: identical by verification, not by sharing

The GRAMOFSMACK family is the **`carousel`** mode — 9 skins: aurora, game-on,
heuristic, instant-camera, jive-turkey, parade, sliders, sudden-impact,
the-grid. They are meant to look like classic Twitter, so their masthead, bio
and navbar must be **identical across the family**.

Identical is achieved by **duplicating the code in each skin and checking it
matches** — never by moving it into core or a shared include. Sean:
*"The GRAMOFSMACK masthead, bio and navbar stay per-skin and are not
standardized into core... No shared mechanism."*

Each skin keeps the functionality it already had in Skin Admin; only the
masthead and nav **code** is individual to each skin.

The comparator must hold those three regions strict for this family.

**Measured 15 Oct: they are NOT identical today** — the masthead, bio and
navbar markup differ across the nine. That check, once written, fails on
arrival. Treat the divergence as work, not as a reason to weaken the rule.

Every other skin owns its own masthead and nav outright.

## 8. Never say "fixed" without evidence

Evidence means the live page measured, or a comparator report attached. A
passing test suite is not evidence — tests written by the agent whose work
they check went green all week while certifying drift.

Report CLOSED / CONTAINED (with the named residual) / DROPPED. Never
"mostly fixed".

## 9. When a skin declares something correctly and the CMS cannot honour it,
the CMS is wrong

Changing the skin to suit CMS code pushes the defect outward into thirty
packages. Twice an invented asset handle got skins blocked by the packager —
once ten of them. Short handles come from each installation's own
`core/manifest-inventory.php`, and SMACK CENTRAL runs an older copy;
`asset:public:<filename>` comes from `assets/ASSET-INVENTORY.json`, which it
has. Prefer the declared form.

## 10. Specs in `_spec/`, never `docs/`

`_spec/*.md` are private notes and are never `git add`ed. PyInstaller `.spec`
build recipes are code and are tracked. `docs/` is not used — note that it is
currently tracked in git and holds 10+ spec files, which is a standing
violation nobody has cleared.

## 11. Plain speech

Sean is autistic and works from explicit rules. Lead with the answer. No
metaphors. Say what a change does for the SITE. Status words are literal:
"fixed" means fixed. Never police his hours or his health.

---

## Current ownership

| area | owner |
|---|---|
| RATIONAL GEO | **Codex — off limits** |
| OPAUDIT 022 | **Codex** |
| everything else below | Claude Code |

## Beta skins (deadline 4 November)

PHOTOGRAM, TILEZ, STANLEY, and every skin live on the SKINS page:
50-shades-of-noah-grey, aurora, chaplin, galleria, game-on, impact-printer,
instant-camera, jive-turkey, parade, scroll, slickr, the-grid, true-grit.
Everything else is frozen.

Inline styles remaining in beta skins (21 across 7):
slickr 6 · aurora 3 · game-on 3 · parade 3 · instant-camera 2 ·
jive-turkey 2 · the-grid 2. TILEZ and STANLEY are at 0.

## Named residuals

- 55 core/skin couplings: 28 skins named inside 16 core files. Ratcheted.
- 21 CSS-in-PHP sites in `core/`. Ratcheted.
- PHOTOGRAM's bottom tab bar: present in Sean's reference screenshots,
  found in no version of the skin. Unexplained.
- The comparator covers rendering, not interaction. Menu, next/prev and
  lightbox clicks are uncovered.
- `v0.7.892D` points ahead of what twelve sites are running, because the tag
  was moved after deployment. The built commit is not known on this side.

<!-- ===== SNAPSMACK EOF ===== -->
