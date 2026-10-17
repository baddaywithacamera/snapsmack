# RULES — read this first, every session

Sean's rules, written down because agents kept breaking them from memory.
Several of these were agreed, broken, re-agreed, and broken again. Where a
machine can check a rule, the check is named. Where it cannot, that is said.

Last updated: 2026-10-16

---

## 1. Skins are self-contained presentation packs

**Each skin defines its own CSS.** One change must not break ten skins. A few
lines duplicated across skins is cheaper than a shared rule that breaks the
fleet — Sean has said this explicitly: *"a few lines of text isn't too much
to add."*

Do not build a new shared mechanism to solve a per-skin problem. Do not
extend a shared stylesheet to fix one skin.

## 1b. A skin may not declare an engine it does not feed

A skin that lists a JavaScript engine in `require_scripts` **must emit the
markup that engine looks for.** Every shared engine gives up quietly when the
markup is missing: no console error, no failed test, no visible clue. The
stylesheet still looks right, Skin Admin still shows the controls, and the
feature is simply not there.

This is not hypothetical. PARADE declared the border-wave engine, shipped a
full BORDER WAVE control section, advertised *"animated flag-coloured tile
borders"* in its own description, and emitted no ring element and no tile
position for **eighteen days**. The engine loaded. Every setting Sean had
chosen was published correctly. The stylesheet was byte-identical to the
repository. Sean found it himself, on his own site. OPAUDIT 023.

Each engine declares what it needs once, in `core/manifest-inventory.php`
under `requires_markup`, next to where the engine is already described. A new
engine adds its own contract there; nothing has to be remembered elsewhere.

The check is `tests/skin-engine-markup-contract.php`. It runs against
**rendered output**, not template source — a grep would pass on an element
sitting inside a branch that never executes. It carries 7 mutation tests plus
a control, because a check nobody has watched fail is not a check: it was run
against PARADE's pre-fix layout and names all three missing pieces.

What it does **not** cover, said plainly: it proves the markup is present, not
that the animation is right. PARADE's second fault — a lightness floor fed
from the wrong control, which painted every ring pure white — is invisible to
it. Markup presence is necessary, not sufficient; visual correctness is
measured in a browser harness.

## 2. No CSS in PHP. No inline styles. No JS in PHP.

**Exactly two bootstrap exceptions:** `fedup.php` and `setup.php`. Each is a
one-file installer intended to be uploaded by itself into an empty directory,
so each may carry the CSS required to render its own installation screen.
This exception does not extend to `install.php`, ordinary CMS PHP, project
sites, skins, or any other file.

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

The check is `tests/gramofsmack-family-identity.php`.

**Re-measured 16 Oct: they ARE identical, and the 15 Oct reading was wrong.**
That reading counted 3 masthead and 7 navbar variants because it compared the
raw text, class prefixes included — and each skin owns its class prefix, so
`au-profile` versus `tg-profile` was being scored as a divergence. With the
prefix normalised away, all nine match.

What is really there, and it reverses the obvious conclusion:

- Before the conversion all nine had a layout.php that was a shell; the whole
  body came from one shared core component. The masthead was identical because
  it was one piece of code.
- `6cab3a9c` "Move grid family skeletons into skin layouts" did not relocate
  that markup, it **re-typed** it for six skins and changed it on the way:
  `<header>`→`<section>`, `<span class="stat"><strong class="stat-num">`→
  `<div class="stat"><span class="stat-num">`, `aria-label="Primary"`→
  `"Site navigation"`, and the avatar gained a `--zoom` modifier the original
  never had. That is rule 5 broken in the same commit that claimed to be a
  relocation.
- `edf8670c` (PARADE) and `4043b2a6` (INSTANT CAMERA) were done separately and
  correctly. **Those two are the faithful ones; the seven are the drift.**
  A majority vote would have "fixed" the two correct skins.

Five of those differences were measured to render identically and the check
re-verifies the reason for each, rather than trusting it: every skin sets
`display` on the stat box and `font-weight` on the number, no skin selects the
masthead by element name or the initials as a descendant, and the boxes either
side of the whitespace that differs are all flex or block-level. If any of
those stops holding, the check fails instead of keeping its allowance.

Two real residuals, both Sean's call, neither changed:

- PARADE and INSTANT CAMERA's avatar lacks `--zoom`, so no `cursor: zoom-in`.
  Nothing in the repository implements avatar zoom, so on the other seven that
  cursor is a **false affordance** — it says click-to-zoom and nothing happens.
  Restoring the original means removing it from seven skins: seven layout
  edits, seven manifest bumps, seven skin installs, for a mouse cursor.
- INSTANT CAMERA shows an avatar inside its sticky nav. The other eight do not.
  Looks deliberate, never confirmed.

One uniform regression against the pre-conversion original, affecting all nine
equally: the no-avatar fallback used to show a single uppercase initial
(`strtoupper(substr($siteName, 0, 1))`); every skin now puts the **whole site
name** inside the avatar circle. Only visible on a site with no avatar set.

Proven by mutation: nine deliberate breakages, all nine caught.

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
- The GRAMOFSMACK avatar `--zoom` false affordance on seven skins, and
  INSTANT CAMERA's sticky-nav avatar. Both recorded in the identity check as
  allowances; see rule 7. Neither is a defect that shows on a photograph.
- All nine GRAMOFSMACK skins print the whole site name in the no-avatar
  circle where the original printed one uppercase letter.
- Interaction is now covered: `skin-render-compare.py controls <url>` and
  `capture --interact` discover the controls on a page, click each one from a
  fresh load, and report any that change nothing. 12/12 mutation tests,
  including one fixture written with exactly one live and one dead button,
  and one that randomises per load -- a dead control must still read as dead.
  What is still uncovered: anything needing two clicks in sequence, typing, or
  a logged-in session.
- `v0.7.892D` points ahead of what twelve sites are running, because the tag
  was moved after deployment. The built commit is not known on this side.

<!-- ===== SNAPSMACK EOF ===== -->
