# OPAUDIT 032 — Wrong licence declared on our own code: GPL-3.0 shown in OH SNAP and elsewhere, contrary to the SPL

> **DRAFT — unpublished. Pending Sean's approval before anything is public.**
> Drafted by Claude Code (the PM reassigned 031/032 drafting to Code on 2026-11-10
> 04:32 MT; Muse was unavailable and stays read-only verifier of these facts).
> Authored as Markdown; renders to the published opaudit HTML on Sean's approval.
> No copy is written in Sean's voice (OPAUDIT 028).

| Field | Value |
|---|---|
| Audit ID | 2026-11-10-032 |
| State | OPEN. The tree is fixed and history rewritten; closure waits on the limits in §6 and the publish-gate interaction in §8. |
| Scope | The licence SnapSmack declared on its OWN code: the copyleft GPL-3.0 was shown on first-party desktop tools and in a public sentence, contrary to the project's own permissive SMACK PUBLIC LICENSE (SPL) 2.4. |
| Subject source | SEAN, 2026-11-09 ("GPL is also an OPAUDIT"); PM post 6KFWEURJ; Codex's SS-1270 posts. |
| Author | Claude Code (draft); Muse (read-only fact verification). |

## 1. Decision

Declaring the wrong licence on your own code is not cosmetic. The SPL 2.4 is
permissive-only; GPL-3.0 is copyleft. Shipping first-party tools stamped GPL-3.0
tells every recipient they have copyleft rights the project never granted and
never intended. It is a false statement about the terms of our own software, and
it sat in shipped artifacts because no check ever read the footer.

Like OPAUDIT 031, this is an operability finding: a control that should have
caught a wrong licence declaration did not exist, so the wrong text propagated
silently from 2026-05 until a human happened to read it in 2026-11.

## 2. Measured — what was wrong

GPL-3.0 (or an equivalent GPL declaration) appeared on first-party code in:

- **OH SNAP** `package.json`, `package-lock`, and `Cargo.toml` (the Tauri desktop
  tool's own declaration files).
- Both OH SNAP **footers** (the rendered UI).
- The **smack-help** paragraph and the **build-spec footer**.
- A sentence on the **public site**.

These are first-party declarations — our code describing its own licence — not the
legitimate third-party licence files the project still carries and should (e.g.
`licenses/GPL-3.0.txt` and the LGPL notices exist because external tools such as
darktable and Qt/PySide6 are GPL/LGPL; those are correct and untouched).

## 3. Since when

- **First declaration: 60afaf74, 2026-05-20, "SnapSmack 0.7.159"** — Sean's own
  commit. (Verified: commit exists, dated 2026-05-20, that subject.)
- **Linux copy: cf46d595, 2026-08-21, "Build SMACKCAST, PHOTOFRI, and OH SNAP for
  0.7.545D."** (Verified: commit exists, that date and subject.)
- Present **in public tags from 0.7.207** onward (from the PM/room record; the
  exact first tag is not independently re-measured in this draft).
- **At least one installed build showed it** (the rendered footer Sean read on
  2026-11-09).

So the wrong declaration was live, in public tags and in at least one installed
build, for roughly six months.

## 4. How it was found

Sean read the footer on 2026-11-09. No automated check had ever looked at a
first-party licence declaration until then — the same silent-absence class as the
rest of this audit series: the failure was not an error that fired, it was a check
that never existed.

## 5. Fix

- **Tree fix: f9ee0415, 2026-11-09, "Remove false GPL declarations from desktop
  tools (SS-1270)."** (Verified.) Plus **94eac75a, 2026-11-09, "Remove stray GPL
  claims from smack-help.php and build-spec-080.js (SS-1270)."** (Verified.)
- **History rewrite** removing the licence material from history: 5,456 commits
  and 1,271 tags rewritten, 0 signed tags broken (figures from Codex's SS-1270
  posts; the rewrite is Codex's work, cited not re-measured here).
- **New gate:** `tools/browser-tests/desktop-license-declarations.cjs` — asserts
  the authoritative SPL title and "Version 2.4", and fails on any first-party
  GPL/AGPL declaration (and on PyQt5/6 dependencies) found while walking `tools/`
  and `projects/` for `package.json`/`Cargo.toml`/`pyproject.toml` and `.html`
  files. This is the check that did not exist in §4. (Gate owned by Codex,
  `tools/browser-tests/`.)
- **Hockney PyQt6 → PySide6 port** (removing a GPL-linked Qt binding in favour of
  the LGPL one), published on `claude/hockney-pyside6-port` @ 9e53336c, pending
  owner-lane review.

Current measured state: a search of current `Github/dev` finds no first-party GPL
declaration — the only remaining GPL mentions are the legitimate third-party
licence files and the detection/audit tooling itself.

## 6. Limits

- **Downloaded copies and old clones keep the wrong text.** The history rewrite
  changes our remote; it cannot reach a copy someone already pulled.
- **GitHub may keep unreachable objects** until its own garbage collection runs,
  so the old blobs can remain fetchable by direct hash for a while.

Rewriting history does not retract what was already distributed; it only stops new
readers getting it from us. This mirrors OPAUDIT 028's conclusion about the
sc-config tags: you cannot un-ship what is already out.

## 7. Who was affected

**Not established beyond Sean's own machines**, stated plainly. The declaration was
in public tags and at least one installed build, so wider exposure is possible,
but this audit has not shown any third party relied on or redistributed under the
wrong terms. See §9 for the specific unchecked items.

## 8. Publish-gate interaction (measured here; flag for the gate owner)

This audit, when rendered to HTML and filed under `projects/snapsmack-ca/opaudits/`,
will contain the literal string "GPL-3.0" in its prose. The new gate
(`desktop-license-declarations.cjs`) walks `projects/` and tests every `.html`
file against `forbiddenDeclaration = /\b(?:GNU\s+)?(?:A?GPL)(?:[-\s]?v?\.?3(?:\.0)?|\b)/i`.
That regex matches audit prose, not just a licence field, so the **published HTML
of OPAUDIT 032 will trip the gate it describes.** Before 032 is published as HTML:
the gate should be scoped to licence-declaration contexts (or exclude the
`opaudits/` audit prose), or the published HTML must encode the term so it does
not match. This is a `tools/browser-tests/` change — flagged for Codex, not fixed
here. (The Markdown draft is safe: the gate scans only `.html`/declaration files
under `tools/` and `projects/`, and this draft is `.md` under `opaudits/`.)

## 9. What to check in your own project

1. Does the licence declared in your code (manifests, footers and about pages) match the licence you actually intend to grant?
2. Is there a single source of truth for your licence that every declaration points back to?
3. Does any automated check read your first-party licence declarations, or has a human ever actually looked at the footer?
4. If you bundle third-party code under a different licence, is your own code's licence clearly separated from it?
5. When you change your licence, do old published copies and tags still state the old one — and have you accounted for that?

## 10. What this audit does not claim / NOT CHECKED

- **Downloads by anyone else** — whether any third party cloned or pulled a
  GPL-stamped build. Not checked.
- **Whether SYBU ever shipped to other users** (i.e. whether a wrong-licence build
  reached a non-Sean machine through it). Not checked.
- **Hockney's non-Qt dependency licences** — the port fixed the Qt binding; the
  other dependencies' licences have not been audited.
- The history-rewrite figures (5,456 commits / 1,271 tags / 0 signed tags broken)
  are Codex's measurement, cited not independently re-counted here.

<!-- ===== SNAPSMACK EOF ===== -->
