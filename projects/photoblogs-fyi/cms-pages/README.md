<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for .md:
  an HTML comment containing five equals, space, 'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# photoblogs.fyi — the two pages, as CMS page bodies

These are the bodies of the two static pages (`projects/photoblogs-fyi/index.html`
and `for-admins.html`), converted so they can be pasted straight into **Pages** in
the photoblogs.fyi CMS admin. Same words, with the design lifted out into
`custom.css` so the copy can be edited without touching CSS and the CSS can be
tuned without touching the copy.

Same arrangement as `projects/photofri-day/cms-pages/`.

**These cannot be pasted yet.** There is no SnapSmack install on photoblogs.fyi —
the domain currently serves the flat `index.html` file. The install comes first.

## How to seed them

For each file, in **Pages → Add New**:

| File | Title | Slug | Menu order |
| --- | --- | --- | --- |
| `home.html` | `PHOTOBLOGS.FYI` | `home` | 0 |
| `for-admins.html` | `For server administrators` | `for-admins` | 1 |

Paste the file contents into the body. The comment block at the top of each file
is a note to you — it does not render, so paste it or don't, either is fine.

Then:

1. **Settings → Homepage mode → Static page**, and choose **PHOTOBLOGS.FYI**.
2. **Appearance → Custom CSS** — paste the whole of `custom.css`.

The slug `for-admins` gives the URL `https://photoblogs.fyi/for-admins`, which is
the address every SnapSmack blog already sends in its outgoing requests. That
address is a 404 today. Seeding this page fixes it.

## Why the markup is shaped the way it is

The page body goes through `core/parser.php`, which auto-wraps loose text in
`<p>` tags. Two consequences, both already handled in these files — **do not
undo them**:

- **No `<div>` inside a `<div>`.** The parser matches the first closing `</div>`,
  so a nested one orphans the rest of the block. The home page's three-step grid
  and the fact table are therefore lists, not nested divs.
- **No `<dl>`.** It isn't in the parser's protected block list, so a `<dl>` gets
  wrapped in a `<p>` and breaks. The "short version" table is a `<ul>` with a
  bold label, styled by `.pbf-facts` to look like the original.

The CMS also prints the page title above the body. Both pages carry their own
heading in the content, so `custom.css` hides the automatic one.

## Before this page goes public — decisions and gaps

Flagged rather than guessed. Every one of these is on the `for-admins` page,
which is written for *other server administrators*, so a wrong claim there costs
real credibility.

1. **The relay handle on the page does not match the live relay.** The page says
   `@relay@photoblogs.fyi`. The relay actually answers as
   `relay@smackverse.photoblogs.fyi` (verified live 2026-08-15 —
   `smackverse.photoblogs.fyi/actor` returns that id). Either move the relay to
   the apex or change the copy. This depends on where the hub install lands, so
   it is your call, not a copy edit.
2. **`@curator@photoblogs.fyi` does not exist.** The page describes it as the
   actor other admins are most likely to meet. It has never been built. Either
   build it before publishing, or cut both curator sections.
3. **Signing key rotation is not automated.** That section is marked with a
   `[[NOT YET AUTOMATED]]` placeholder. Do not publish it until rotation ships.
4. **Placeholders to fill**, all marked in red on the rendered page by the
   `.pbf-todo` style so they cannot ship unnoticed:
   - the hashtag list we actually read
   - how often we read it
   - the SnapSmack version in the User-Agent string
   - the current signing key fingerprint and its start date
   - the "last updated" date
5. **The "Coming 2026" badge** on the home page — drop it once the directory is
   actually live.

## What is not here

The directory itself — the people finder, the topic browsing, the opt-in flow.
That is the hub build, specced in
`_spec/fedistructure-package-bifurcation-spec-v0_2.md` §15. These two pages are
the wrapper around it, not the thing itself.

<!-- ===== SNAPSMACK EOF ===== -->
