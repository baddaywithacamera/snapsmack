<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical HTML-comment
  SNAPSMACK EOF marker used by this repository.
-->

# snapsmack.ca Homepage Refit Continuity

**Captured:** 2026-08-27
**Purpose:** Preserve the existing homepage and its ideas before restructuring its
message. This document is continuity for a later session; it does not authorize a live
deployment.

## Exact pre-refit baseline

The pre-refit homepage is the tracked file `projects/snapsmack-ca/index.php` at Git
revision:

`2135f9bcfa05506dfa71de362003e901be6d18b4`

Its SHA-256 at capture time is:

`EDAFE77634805E3F6D3E012B4F5596C05CACD549A0526433CBADBD831CE237B8`

The file had no working-tree modification when captured. Its exact contents remain
recoverable from that revision even after the working copy is refitted. Do not overwrite
or delete history to perform the refit.

## What the homepage currently says

The current page contains, in order:

1. Closed-beta announcement.
2. Hero: `Retro Photo Blogging. Modern Technology.`
3. Ownership and self-hosting explanation, including POSSE and ActivityPub.
4. `Three Ways to Play`: SMACKONEOUT, GRAMOFSMACK, and SMACKTALK.
5. Fediverse explanation: `The ship has sailed on the lonely blog`, including Dip a Toe
   and Dive In.
6. Production skin system: `One engine. No house style`, with real-site examples.
7. Featured companion app: Smack Your Batch Up.
8. `Coming Up the Rear`, containing Lookbook, 52 Card Pickup, Oh Snap!, Midnight Move,
   Cold Snap, Take Your Shit With You, Memento Mori, The Challenge Network,
   Photoblogs.fyi, Snap Slapper, and LEWK AGAIN.
9. The complete eight-layer security story.
10. `Built to stay yours`: Yours, Free, Connected, and Defended.
11. Closed-beta application.
12. `Respect Where It's Due`, including Pixelpost and Noah Grey/Greymatter lineage.
13. `Who's Responsible for All This?!?`, including Sean, Claude, and Codex.
14. The shared exploration links and footer.

The detailed `Working Right Now` inventory currently lives on `features.php`, not on the
homepage.

## Diagnosis prompting the refit

The existing homepage contains substantial information, but it does not establish one
clear first-visit narrative. Shipping capabilities, installation modes, skin
architecture, future products, security design, lineage, and contributor biographies
receive similar visual weight. The visitor encounters a large future catalogue while
the clearest description of what already works is one click away on THE GOODS.

This is an information-hierarchy problem, not a request to erase the existing voice,
ideas, acknowledgements, or technical substance.

## Core distinction that must be added

SnapSmack is not only a CMS. Its differentiator is a complete free photography ecosystem:

- Desktop tools deliberately move resource-heavy work off constrained shared hosting and
  onto the photographer's Windows or Linux computer. This keeps the server footprint lean
  enough for a full-featured CMS to run on cheaper hosting plans.
- Keep Sean's voice in the explanation. The homepage's deliberately saucy shorthand is:
  "WordPress is Jabba the Hutt trying to ride a landspeeder built for Princess Leia."

- a self-hosted photography website and archive;
- three purpose-built publishing modes;
- RSS, IndieWeb, POSSE, and two-way ActivityPub;
- free Windows and Linux desktop tools;
- tools for leaving Instagram and Flickr;
- local organization, metadata, editing, preparation, and publishing;
- backup, audit, recovery, and data-portability tools;
- multisite and fleet management; and
- no subscription, premium tier, advertising network, or hostage situation.

Working positioning line:

> Your photography website, archive, social presence, and desktop workflow—one free
> ecosystem that you control.

The website is the centre of the system. The desktop tools perform the heavy work around
it. The homepage should present tools by human task before expecting a new visitor to
remember every product name:

- Import and escape
- Organize and edit
- Prepare and publish
- Back up and recover
- Manage multiple sites
- Export and leave

## Proposed homepage narrative

1. **What SnapSmack is** — a free, self-hosted photography publishing system: website,
   archive, social connection, and desktop workflow.
2. **What photographers can publish** — single-photo blogs, grids/carousels, and
   longform photo essays.
3. **What works today** — a concise homepage version of THE GOODS' `Working Right Now`
   section.
4. **What makes it different** — ownership plus the ecosystem of free, powerful desktop
   tools, without a WordPress-style arbitrary plugin pile.
5. **See it working** — real production sites, modes, and skins.
6. **Closed-beta invitation** — the primary conversion action.

Coming-soon projects, the detailed eight-layer security explanation, full lineage, and
contributor biographies should remain available but move below the primary product and
beta story or onto clearly linked supporting pages.

## Content that must not be lost

- The three publishing modes and their distinct purposes.
- The ownership, archive, and chronological-publishing argument.
- The Fediverse explanation and the difference between Dip a Toe and Dive In.
- The manifest-driven skin architecture and no-arbitrary-plugin boundary.
- Real production-site examples.
- Every honest claim about what is working versus coming soon.
- The complete security explanation and links to published audits.
- Pixelpost, Jay Williams, Noah Grey, and Greymatter acknowledgements.
- Sean, Claude, and Codex contributor acknowledgements.
- Credit Sean accurately as SnapSmack's creator, photographer, product designer,
  requirements source, chief tester, director, and final decision-maker. Do not imply he
  programmed the system: his direct coding contribution is limited to minor CSS work.
- The site's irreverent voice; clarity should improve without sanding it into generic
  corporate copy.
- Closed-beta dates, capacity, archive expectations, and application mechanism, verified
  against current launch plans before publication.

## Refit constraints

- Do not change shared header or footer behavior while restructuring homepage content.
- Do not move a pending feature into `Working Right Now` merely to improve the pitch.
- Do not describe the desktop suite as an optional afterthought; it is a primary product
  differentiator.
- Do not allow the coming-soon catalogue to outrank the working product.
- Preserve or deliberately redirect existing homepage anchors used by incoming links.
- Review mobile reading order as well as desktop layout.
- Refit locally, compare against this baseline, and obtain explicit approval before any
  deployment.

<!-- ===== SNAPSMACK EOF ===== -->
