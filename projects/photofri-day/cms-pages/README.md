<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical EOF marker for this
  file type: an HTML comment containing five equals, space, the literal string
  'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# photofri.day — the four pages, as CMS page bodies

These are the bodies of the four static pages, converted to paste straight into
**Pages** in the PHOTOFRI.DAY CMS admin. They are the same copy as
`projects/photofri-day/*.html`, with the design lifted out into the ONYX skin
(`skins/onyx/style.css`, Crimson palette) so the words can be edited without touching
CSS, and the CSS can be tuned without touching the words.

They are **not** shipped inside the skin package. A skin is a look; these are
content, and content belongs in the database.

## How to seed them

For each file, in **Pages → Add New**:

| File | Title | Slug | Menu order |
| --- | --- | --- | --- |
| `home.html` | `PHOTOFRI.DAY` | `home` | 0 |
| `how-it-works.html` | `HOW IT WORKS` | `how-it-works` | 1 |
| `faq.html` | `WHUT THE WHUT` | `faq` | 2 |
| `join.html` | `JOIN THE PARTY` | `join` | 3 |

Paste the file contents into the body. Then:

1. **Settings → Homepage mode → Static page**, and choose **PHOTOFRI.DAY**.
2. **Appearance → Customize** with ONYX and the Crimson palette active; upload
   `skins/onyx/img/logo-pf-white.png` as the header logo, and
   `img/logo-pf-black.png` as the **Profile Avatar** (that one becomes the
   Fediverse actor icon other servers show).

The nav builds itself. `core/header.php` lists every active page in `menu_order`
and excludes whichever one is the homepage, so HOW IT WORKS / WHUT THE WHUT /
JOIN THE PARTY appear because those pages exist — not because a skin file names
them. Add a fifth page and it turns up in the nav on its own.

## Two rules if you edit these

**1. Titles do not need a full stop.** The skin adds the crimson one after every
page title. Typing `HOW IT WORKS.` gets you `HOW IT WORKS..`.

**2. Do not nest a `<div>` directly inside a `<div>`.** This is the one real
constraint, and it comes from the content parser, not the skin. `parseContent()`
protects top-level block elements from auto-paragraph by matching an opening tag
to its first matching close — so an outer `<div>` closes at the first `</div>` it
finds, which is the inner one, and everything after it gets wrapped in stray
`<p>` tags. Inner blocks in these files therefore use `<section>`, which nests
inside a `<div>` without confusing the match. Follow the same pattern and it
stays fine.

## The JOIN page needs a decision

`join.html` here keeps the MailerLite email-capture form as a **commented-out
placeholder**, because the original page's whole job was "tell me when following
starts working" — and on the CMS install, following `@participate@photofri.day`
IS the mechanism, so the form may be obsolete on arrival.

Pick one before it goes live:

- **Follow instructions** (what's in the file now) — no third-party anything.
- **Keep the signup form** — uncomment the block and paste MailerLite's own
  `<script>` in with it. That script is *page content you added*, not something
  the skin ships; skins carry no JavaScript at all. The CRIMSON ONYX stylesheet
  still contains the `.ml-*` rules so the form keeps its look either way.

<!-- ===== SNAPSMACK EOF ===== -->
