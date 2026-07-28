<!-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. -->

# SnapSmack.ca SEO Pre-change Audit

Date: 2026-07-28

Scope: the public marketing site in `projects/snapsmack-ca/` and the live
canonical host `https://snapsmack.ca/`. This report was written before changing
the public-site implementation, as required by `SnapSmack_SEO_Codex_Spec.md`.

## Executive summary

SnapSmack.ca already has crawlable server-rendered HTML, one visible H1 on each
of its eight editorial pages, unique page titles and descriptions, useful alt
text, HTTPS enforcement, a canonical-host redirect, and real 404 responses.
The site voice and visible content are strong.

The main discoverability problem is not thinness; it is ambiguity. The homepage
leads with "photo blog" but does not immediately connect that identity to
current search language such as self-hosted photography publishing, an
Instagram or Flickr alternative, independent photo sharing, and ownership.
The site also lacks canonical tags, Twitter card metadata, structured data,
crawler control files, a sitemap, focused search-intent landing pages, and a
prominent portability page.

Recommended positioning:

> Retro Photo Blogging. Modern Technology.
>
> SnapSmack is a free, self-hosted photography publishing platform for people
> who want to own their photos, website, audience, and archive.

"The Joy of the Old Web. Without the Old Software." is suitable supporting
copy. "Own Your Photos Like It's 2005." should remain a cheekier callout rather
than the primary H1.

## Current architecture

- Public source: `projects/snapsmack-ca/`
- Rendering: PHP includes with server-rendered HTML
- Shared metadata/navigation: `includes/header.php`
- Shared footer: `includes/footer.php`
- Existing public editorial pages: homepage, news, emergency updates, privacy,
  ethics/licensing, FAQ, security audits, and contact
- Canonical hostname: `https://snapsmack.ca`
- Current URL convention: explicit `.php` URLs, except `/` for the homepage
- Deployment: Git-tracked source followed by a separate manual FTP deployment;
  CMS release publication does not deploy this site

## What is already good

- Every existing editorial page defines a unique title and meta description.
- Every existing editorial page has one visible H1.
- Critical metadata is server-rendered and does not depend on JavaScript.
- Existing images have alt attributes; the lightbox's empty runtime image uses
  an intentionally empty alt.
- HTTP redirects permanently to HTTPS.
- `www.snapsmack.ca` redirects permanently to `snapsmack.ca`.
- Unknown URLs return HTTP 404.
- Open Graph type, URL, title, description, and image are present.
- The primary navigation is ordinary crawlable HTML.
- No analytics, advertising, or third-party marketing scripts are present.
- The project voice, humour, ownership position, and anti-lock-in case are
  already well developed in visible copy.

## Findings

### High priority

1. The shared header emits no `<link rel="canonical">`.
2. `/index.php` returns 200 instead of redirecting to `/`, creating a homepage
   duplicate. Query-string variants also have no canonical signal.
3. No tracked `robots.txt`, `sitemap.xml`, or `llms.txt` exists for the
   marketing site; all three return 404 live.
4. No JSON-LD is present. WebSite and SoftwareApplication data should be added
   without ratings, adoption numbers, macOS support, or other invented claims.
5. The homepage title, H1, and opening copy do not clearly state the modern
   product category and ownership promise.
6. There are no focused landing pages for the strongest search intents and no
   dedicated export/portability page.

### Medium priority

1. Open Graph lacks `og:site_name`.
2. Twitter/X card metadata is absent.
3. The logo is used as the social preview. It is valid and crawlable, but a
   purpose-built large preview image would communicate the product better.
4. Fifty-eight image elements lack explicit width and height, increasing layout
   shift risk. Most below-the-fold screenshots also lack lazy loading.
5. Existing navigation labels are deliberately branded rather than descriptive.
   Contextual links and a small descriptive discovery section are needed so
   crawlers and new visitors understand the destinations without renaming the
   main navigation.
6. The public image directory is about 84 MB, with several PNG screenshots
   between 2 MB and 3.5 MB. Modern derivative formats should be considered
   without degrading the photography or breaking the lightbox.

### Low priority or manual verification

1. The live host correctly redirects HTTP and `www` in one permanent hop and
   returns real 404 responses.
2. `/index.php` canonicalization requires a server rule on the live host. The
   repository currently contains no marketing-site `.htaccess`.
3. Response compression, cache headers, Core Web Vitals, and full keyboard/
   contrast behavior require post-deployment browser and server testing.
4. Search Console ownership and sitemap submission are external manual actions.
5. The canonical SPL URL should be the public repository copy:
   `https://github.com/baddaywithacamera/snapsmack/blob/master/licenses/SNAPSMACK-LICENSE.txt`.

## Implementation direction

- Extend the shared header instead of duplicating metadata across pages.
- Preserve explicit `.php` canonical URLs for existing secondary pages.
- Canonicalize the homepage to `/` and add a permanent `/index.php` redirect.
- Lead with the retro-web differentiator while immediately explaining that
  SnapSmack is free, self-hosted photography publishing software.
- Add focused pages for Instagram alternatives, Flickr alternatives,
  self-hosted photography, photo-blog software, Fediverse photography, and
  export/portability.
- Add static crawler files and an automatically validated sitemap for the
  marketing site only; do not confuse these with the CMS-generated crawler
  files shipped to individual SnapSmack blogs.
- Add `tools/seo_audit.py` to validate metadata, canonicals, H1s, JSON-LD,
  sitemap entries, local links, and image attributes before deployment.

<!-- ===== SNAPSMACK EOF ===== -->
