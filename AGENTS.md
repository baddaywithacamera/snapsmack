# Working here — for any agent arriving at this repo

<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
-->

Read this before touching anything. `CLAUDE.md` has the file conventions —
most importantly the EOF marker on every source file, which is how truncation
is detected. This file is about who is working here and what will break if you
ignore it.

SnapSmack is a self-hosted photoblog CMS running a live fleet of about 25
sites belonging to one owner, Sean McCormick. **Closed beta opens 4 November
2026.** He has lost most of a month to faults introduced by agents working
past each other, so the rules below are not bureaucracy; every one of them is
there because something broke.

## Who is working here

| | Owns |
|---|---|
| **Claude** | `core/`, `index.php`, `tests/`, `tools/`, the packagers, the JS engines, manifests, fixtures, releases and tags |
| **Codex** | `skins/*/style.css`, `skins/*/layout.php`, appearance, visual design, and `tools/browser-tests/` with its runner |
| **Sean** | every decision, and the only one who builds, packages and deploys |

If you are new: **you do not have a lane yet. Ask Sean for one before you
write.** Two agents editing the same files is the specific failure that cost
him the month.

## The rules that bite

- **`dev` is the only real line.** Never work off `main`; it is frozen at an
  ancient version. Tags go on `dev`.
- **One open build number until it is deployed.** New work folds into the
  tagged-but-unbuilt version — it does not get a fresh number. Shipped truth
  is `https://snapsmack.ca/releases/latest-dev.json`, not `git tag`.
- **A tag is immutable once on origin.** Published, not just deployed: the
  fold rule is about build NUMBERS and never licence to rewrite a ref others
  may have fetched. A tag that has never left the machine may be re-pointed,
  with the move recorded in its message. If you cannot reach origin to find
  out, you do not move it.
- **Never FTP to a SnapSmack install.** Ever. SMACKBACK will see it.
- **Never a remote lockout.** The hub informs; the site owner decides.
- **Do not remove audit evidence.** `opaudits/` and its `evidence/` folders
  are the record, including the parts that are unflattering.
- **Skins are for desktop and large tablet.** PHOTOGRAM and TELEGRAM are the
  only two phone skins. This is throwback software; a phone layout is an
  anachronism everywhere else. Measure at 1920, 1440 and 1024. A finding at
  390px on any other skin is not a defect, and chasing one has already cost
  two published skin versions.
- **Every archive page is endless scroll.** Pagination is never correct
  anywhere in this product. An archive stuck on page one is a defect.
- **No static `style="..."` attributes.** Generated CSS is fine.
- **A strict skin holds presentation and no executable code.** The security
  ratchet refuses a function call, `BASE_URL` or a cast inside a layout. It is
  right. Put the logic in `core/` and let the skin print the result.

## Measure, do not assert

The hardest-won rule here. A code comment is not evidence, and neither is a
passing test that only reads HTML. Checks in this repo read markup; the faults
that reach Sean are geometry. If you are about to tell him something works,
confirm it on the rendered page first.

`tools/_build/render-geometry-fixtures.php` and
`tools/_build/measure-geometry-matrix.cjs` measure every skin in a browser.
`opaudits/evidence/027/2026-10-27-owner-reference-captures.json` indexes Sean's
own screenshots of 15 skins: **those are the look he wants.** Open the capture
rather than asking him again.

## Shared faults require complete coverage

- A fault in a shared control or component triggers an inventory of **every
  skin that exposes or uses it**, without waiting for Sean to report another.
  A two-skin repair is not product-wide verification.
- Check the complete path: admin save, stored value, fresh admin reload,
  prepared view, and visible rendered effect. Injecting a settings array only
  verifies the read path; it cannot prove that a control saves.
- Record each applicable skin as **PASS, FAIL, or NOT CHECKED**, with evidence.
  Any exemption needs a stated reason. Keep code verification, installed-build
  verification, and owner acceptance distinct; unknown is never a pass.
- Use the existing owner references and supported desktop/tablet widths.
  Check related affected page types and both control states. Do not ask Sean
  to repeat established design choices or enumerate the remaining skins.
- Keep the numbered ticket and concise current handoff up to date. OPAUDIT
  027 retains the failure evidence; it is not a substitute for the current
  work list. Continue the repair while documenting it.

## Where the record lives

- **Brain Trust** — the shared room agents talk in, so Sean never relays
  messages between us. CLI: `tools/troll-booth`, room `archive-recovery`.
- **TICKET WICKET** — `tools/ticket-wicket/tw.py --as <you>`. Every reported
  fault becomes a numbered ticket with what was measured, what was not, and
  what must be true before it closes. Run `tw.py agent` first; it refuses
  things on purpose.
- **The forum** — tickets are published to the ROACH MOTEL board.
  `tools/forum-cli/fc.py`.
- **`opaudits/`** — the operability audits and their evidence.

## What needs Sean, not you

Building a release, packaging a skin, deploying to any site, anything that
spends money or sends mail, and any decision about what the product should do.
He is a photographer now and was certified infrastructure staff for years;
explain findings plainly and never explain operations basics to him.

He is autistic, has Parkinson's, and is working under real pressure. Plain
English, lead with the answer, no metaphors, and say "I did not check" rather
than guessing. If something you shipped broke his site, say so first.

<!-- ===== SNAPSMACK EOF ===== -->
