# OPAUDIT 035 — Green on the fixture, broken on the page

> **Evidence note.** Written by the repo seat from the Brain Trust room record of 13–14 November 2026. The owner asked for it because the pattern keeps recurring. Causes marked "suspected" are not yet verified on a live render.

| Field | Value |
|---|---|
| Audit ID | 2026-11-14-035 |
| State | OPEN. The pattern is identified; the current instance (SS-1345) is not repaired; the rule below is new. |
| Scope | Changes that passed their own tests and review, then failed the first time a real page, a real install or a real person met them. And new code written where working code already existed in a sibling. |
| Tickets | SS-1345 (RATIONAL GEO post pages), SS-1342 (frozen-constants dashboard), SS-1336 (Generative Fill), SS-1322 (RATIONAL GEO tray) |

## The pattern

Within two days, four changes each passed every check written for them and broke on contact with reality:

1. **Fleet dashboard (SS-1145 → SS-1342).** The new helper's test loaded the repository's own `core/constants.php`, so the function always existed in the test. On the oldest real install, that file was frozen and the function did not exist. The dashboard stopped at the first spoke. The release note had said "rendered dashboard on a long-standing install: NOT CHECKED".
2. **Generative Fill (SS-1336).** Progress and cancel were tested against a fake runner and a fake pipeline. The first real fills came back soft, then the next build returned near-black corners. Each fault was found by the owner looking at his own photograph, not by a test.
3. **RATIONAL GEO tray (SS-1322 → SS-1345).** The browser geometry test asserted the claimed layout on a synthetic fixture, and the reviewer said plainly that it was a fixture and not a live skin render. After the fleet update, a real post shows no image and will not scroll. Suspected cause: the photo area was made non-shrinking at full viewport height and the page body stopped being the scroll container.
4. **GLIDE direct posts.** A real repair, but tested on fixtures only. At the time of writing it had not been rendered against a real post.

In every case the check measured what the author expected, not what a visitor would see.

## The second pattern: new code where working code existed

The owner's question, in his words: "may i ask why new and broken code is being written when there is working code in IMPACT PRINTER that can simply be borrowed to use here?"

There was no recorded reason, and it is worse than an oversight: the owner had **already** named IMPACT PRINTER's working code as the thing to borrow, earlier, and it still was not used. An owner's explicit pointer to working code was lost between the request and the work. IMPACT PRINTER already has a solo-post layout that shows the image and scrolls. RATIONAL GEO and GLIDE both received freshly written layout rules instead, and neither post says it looked at a sibling skin first. Fresh code carries fresh bugs; borrowed working code carries the testing it has already had in production.

## Rules from this audit

1. **Reuse before rewrite.** When the owner names code to borrow, that is an instruction: the post must say it was used, or why it could not be. Before writing layout or behaviour for a skin, look for a sibling skin that already does it and works. Borrow it, and name the skin and the rules borrowed in the post. New code needs a stated reason why the existing code cannot be used.
2. **A fixture is not a page.** A visual or layout change is not called fixed until it has been rendered on at least one real post of the kind the change affects, with a screenshot in the room. Fixture tests stay. They prove the rule exists, not that it works.
3. **A test must be able to fail in the real failure mode.** A test that loads the exact condition under which the code works (the repo's own constants file, a fake pipeline) cannot catch the condition under which it breaks. Say which real condition the test cannot reach, in the NOT CHECKED line, and treat that line as a blocker for anything a visitor will see.
4. **Revert first when live is broken.** When a shipped change breaks a live page, the first move is to restore the last good version through review, not to write a second new fix on top.

## Recovery for the current instance

SS-1345: revert RATIONAL GEO to its last good version, re-run the Skin Packager for rational-geo, confirm on the live post, and only then consider a new tray change, built from IMPACT PRINTER's solo layout.

A delivery note found while writing this: skins do not ship in the CMS release zip. A skin repair reaches sites only when the Skin Packager is re-run for that skin, not when a CMS build is published.

## The same gap in desktop builds

The same distinction appeared in two desktop tools after this draft was written. COLD SNAP's 12 November design reference images were present outside the repository but were not consumed by the rebuild work. A newly numbered executable therefore looked current while the running program still did not embody the referenced design. The number identified a build; it did not prove which requirements had reached that build.

CRONOMETER exposed the other half of the same problem. The installed executable was dated 14 September, carried no Windows FileVersion, and predated the 10 November OPAUDIT 024 repair that stops a failed fleet load from looking like an empty fleet. Source contained the repair; the program the owner could launch did not. A 0.7.13 executable was rebuilt on 15 November with a real FileVersion sourced from the same version literal as the application. The build and metadata were checked; the running application and owner acceptance were **NOT CHECKED** at the time of this note.

The rule is the same for pages and programs: a source commit, a passing fixture, a filename or a version number is evidence about an intermediate artifact. It is not evidence that the owner's referenced design is present or that the installed program performs the repaired path. Those claims require an inventory of the inputs, an identifiable build, and observation of the installed application.

## What to check in your own project

1. When a test passes, could it have failed in the way production fails, or does it set up the one condition under which the code works?
2. Is "fixed" defined as a rendered real page, or as a green check?
3. Before writing new code, does anyone look for the existing code that already does the job?
4. When live breaks, is reverting the default, or do you stack a new fix on an unverified one?

## What this audit does not claim

- It does not claim the SS-1345 cause is verified; it is suspected from the diff and the symptom.
- It does not claim fixture tests are worthless. They are kept, and they are not sufficient.
- It does not assign blame to one agent. Every one of these changes was reviewed and approved under the rules in force at the time.

Prepared by the repo seat (Claude) with AI assistance, from the room record. Status: **OPEN**.

<!-- ===== SNAPSMACK EOF ===== -->
