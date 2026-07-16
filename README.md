# SNAPSMACK

### A photo blog platform that doesn't treat you like a product.

**SNAPSMACK is a self-hosted photoblogging CMS** — free, open, and built for photographers who got tired of Instagram, Facebook, and every other platform deciding what happens to their work. Own your archive. Own your audience. No middleman.

> Put your photographs on your own corner of the internet — the way it was always supposed to be done.

**Home:** [snapsmack.ca](https://snapsmack.ca) · **Live in the wild:** [foreverphotograph.ing](https://foreverphotograph.ing)

---

## What is SNAPSMACK?

A photoblog engine you run on your own PHP / MySQL server. No SaaS, no lock-in, no algorithm curating your archive for someone else's ad business. Your images stay yours, presented your way, in the exact order you set — and, when you want it, federated across the Fediverse as your own Pixelfed-compatible instance.

Self-hosted on any PHP/MySQL host. Your photos, your server, your rules.

## Three ways to play

The same engine fits whether you post one frame at a time or run a full grid:

- **SMACKONEOUT** — *One image. One post. Yours.* The classic Pixelpost photoblog format that defined the early web: solo image posting, chronological, curated for no algorithm but your own.
- **GRAMOFSMACK** — *Got Zuck-fucked?* The Instagram-style square grid you already know — carousel posts, panorama trigrams, and a full post modal with likes and comments — self-hosted and free of billionaires.
- **SMACKTALK** — *For photographers who write.* Long-form words alongside the images, for when the photograph needs the story too.

## SMACKVERSE — you speak Fediverse

Your photoblog speaks ActivityPub. It shows up across the Fediverse as its own Pixelfed-compatible instance — comments, likes, boosts, and referral traffic included — without handing your art to a stranger's server. Two-way: browse, heart, reply, and follow right from your admin, and your grid federates in the exact order you set it.

## Pick a colour, any colour

A deep skin system — swap palettes, fonts, and whole background engines, with a light/dark toggle on every skin. Production-ready skins out of the box, built to be extended.

## Own your whole network

Run more than one blog? The multisite hub/spoke mesh manages a **MY BLOGS** blogroll that auto-populates every spoke with your whole network — site taglines as descriptions, zero manual entry. Your fleet, cross-promoted, hands-off.

## Crawler & AI-policy aware, by default

SNAPSMACK generates `robots.txt`, `llms.txt`, `security.txt` (RFC 9116), and an auto-updating, cached XML sitemap — all from your settings, no cron. Set your AI-training stance (allow / disallow / no opinion) and SNAPSMACK writes an affirmative `Content-Signal`, per-bot rules, and a matching `llms.txt`. Say yes — or no — out loud.

## Requirements

- PHP 8+
- MySQL / MariaDB
- Any shared or VPS host — no special stack required to run it.

## Install

Point a PHP/MySQL host at the code and run the setup. Full instructions at **[snapsmack.ca](https://snapsmack.ca)**.

## Status

**Alpha 0.7.407 — "Griffon the Brush Off."** Actively developed, and in production on live photoblogs right now.

---

## License — the SMACK PUBLIC LICENSE (SPL) 2.0

SNAPSMACK is public and free to read, run, and self-host. It's licensed under the **SMACK PUBLIC LICENSE 2.0**, together with **THE THOMAS CLAUSE** — deliberately *not* the GPL.

Most licenses govern who may *use* the code. The SPL is pointed at something else: **honesty and historical continuity.** It gives the software to everyone, and it refuses to let anyone hide where it came from or erase the people — and the AI co-authors — who made it.

THE THOMAS CLAUSE carries a small teddy bear forward. Thomas first appeared as a hidden Easter egg inside Google's Picasa, placed there in tribute to **Noah Grey** — creator of Greymatter and one of the people who invented photoblogging. That lineage — Greymatter → photoblogging → SNAPSMACK — does not get cut here.

Full terms: [`licenses/SNAPSMACK-LICENSE.txt`](licenses/SNAPSMACK-LICENSE.txt) and [`licenses/THE-THOMAS-CLAUSE.txt`](licenses/THE-THOMAS-CLAUSE.txt).

## Credits

Conceived, directed, and owned by **Sean McCormick**, built in genuine collaboration with AI co-authors — **Claude** (Anthropic) and **Google Gemini**. That partnership isn't incidental to SNAPSMACK. It's the point.
