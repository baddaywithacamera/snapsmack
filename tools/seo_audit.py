#!/usr/bin/env python3
"""Validate the tracked SnapSmack.ca marketing site before FTP deployment."""

from __future__ import annotations

import html
import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urlparse

ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / "projects" / "snapsmack-ca"
SITEMAP = SITE / "sitemap.xml"
CANONICAL_HOST = "snapsmack.ca"


def php_string(source: str, variable: str) -> str | None:
    match = re.search(
        rf"\${re.escape(variable)}\s*=\s*'((?:\\.|[^'])*)'\s*;",
        source,
        flags=re.S,
    )
    if not match:
        return None
    return match.group(1).replace("\\'", "'").replace("\\\\", "\\")


def fail(errors: list[str], message: str) -> None:
    errors.append(message)


def main() -> int:
    errors: list[str] = []

    try:
        sitemap_root = ET.parse(SITEMAP).getroot()
    except (OSError, ET.ParseError) as exc:
        print(f"FAIL: invalid sitemap: {exc}", file=sys.stderr)
        return 1

    ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    urls = [node.text.strip() for node in sitemap_root.findall("sm:url/sm:loc", ns) if node.text]
    if not urls:
        fail(errors, "sitemap contains no URLs")
    if len(urls) != len(set(urls)):
        fail(errors, "sitemap contains duplicate URLs")

    titles: dict[str, str] = {}
    descriptions: dict[str, str] = {}
    indexed_files: set[Path] = set()

    for url in urls:
        parsed = urlparse(url)
        if parsed.scheme != "https" or parsed.netloc != CANONICAL_HOST:
            fail(errors, f"noncanonical sitemap URL: {url}")
            continue
        relative = parsed.path.lstrip("/")
        page = SITE / ("index.php" if relative == "" else relative)
        if page.suffix != ".php" or not page.is_file():
            fail(errors, f"sitemap URL has no tracked PHP page: {url}")
            continue
        indexed_files.add(page.resolve())
        source = page.read_text(encoding="utf-8")
        if re.search(r'<meta\b[^>]*\bnoindex\b', source, flags=re.I | re.S):
            fail(errors, f"{page.name}: indexed page contains noindex")

        title = php_string(source, "page_title")
        description = php_string(source, "page_description")
        canonical = php_string(source, "page_og_url")
        if not title:
            fail(errors, f"{page.name}: missing page_title")
        elif title in titles:
            fail(errors, f"duplicate title in {page.name} and {titles[title]}: {title}")
        else:
            titles[title] = page.name
        if not description:
            fail(errors, f"{page.name}: missing page_description")
        elif description in descriptions:
            fail(errors, f"duplicate description in {page.name} and {descriptions[description]}")
        else:
            descriptions[description] = page.name
        if canonical != url:
            fail(errors, f"{page.name}: canonical variable {canonical!r} does not match {url!r}")

        if "includes/seo-landing.php" in source:
            if not php_string(source, "landing_h1"):
                fail(errors, f"{page.name}: shared landing page has no landing_h1")
        else:
            h1_count = len(re.findall(r"<h1\b", source, flags=re.I))
            if h1_count != 1:
                fail(errors, f"{page.name}: expected one H1, found {h1_count}")

    header = (SITE / "includes" / "header.php").read_text(encoding="utf-8")
    required_header_tokens = [
        'rel="canonical"',
        'property="og:site_name"',
        'name="twitter:card"',
        'application/ld+json',
        "'@type' => 'WebSite'",
        "'@type' => 'SoftwareApplication'",
        "'operatingSystem' => 'Linux'",
    ]
    for token in required_header_tokens:
        if token not in header:
            fail(errors, f"shared header missing {token}")
    if re.search(r"operatingSystem.{0,80}macOS", header, flags=re.I | re.S):
        fail(errors, "structured data claims macOS support")

    try:
        rendered = subprocess.run(
            ["php", str(SITE / "instagram-alternative.php")],
            cwd=SITE,
            check=True,
            capture_output=True,
            text=True,
            timeout=20,
        ).stdout
        json_ld_match = re.search(
            r'<script type="application/ld\+json">(.*?)</script>',
            rendered,
            flags=re.I | re.S,
        )
        if not json_ld_match:
            fail(errors, "rendered page contains no JSON-LD")
        else:
            json.loads(json_ld_match.group(1))
    except (OSError, subprocess.SubprocessError, json.JSONDecodeError) as exc:
        fail(errors, f"rendered JSON-LD validation failed: {exc}")

    robots = (SITE / "robots.txt").read_text(encoding="utf-8")
    if "User-agent: *" not in robots or "Allow: /" not in robots:
        fail(errors, "robots.txt is not permissive for the public site")
    if "Sitemap: https://snapsmack.ca/sitemap.xml" not in robots:
        fail(errors, "robots.txt does not advertise the canonical sitemap")

    llms = (SITE / "llms.txt").read_text(encoding="utf-8")
    for required in [
        "https://snapsmack.ca/",
        "https://github.com/baddaywithacamera/snapsmack",
        "SNAPSMACK-LICENSE.txt",
        "not authorized for AI training",
    ]:
        if required not in llms:
            fail(errors, f"llms.txt missing policy/reference: {required}")

    href_pattern = re.compile(r'href=["\']([^"\']+)["\']', flags=re.I)
    img_pattern = re.compile(r"<img\b[^>]*>", flags=re.I | re.S)
    alt_pattern = re.compile(r"\balt\s*=", flags=re.I)
    width_pattern = re.compile(r"\bwidth\s*=", flags=re.I)
    height_pattern = re.compile(r"\bheight\s*=", flags=re.I)

    for page in sorted(SITE.rglob("*.php")):
        source = page.read_text(encoding="utf-8")
        for raw_href in href_pattern.findall(source):
            href = html.unescape(raw_href)
            if "<?" in href or href.startswith(("#", "/", "http:", "https:", "mailto:", "tel:")):
                continue
            target_text = href.split("#", 1)[0].split("?", 1)[0]
            if not target_text:
                continue
            # Shared includes render at web-root URLs, so their relative links
            # are rooted beside the public page rather than beside /includes/.
            target = (SITE / target_text).resolve()
            if not target.exists():
                fail(errors, f"{page.relative_to(SITE)}: broken local link {href}")

        for tag in img_pattern.findall(source):
            if "<?" in tag:
                continue
            src_match = re.search(r'\bsrc=["\']([^"\']*)["\']', tag, flags=re.I)
            if src_match and src_match.group(1) == "":
                continue
            if not alt_pattern.search(tag):
                fail(errors, f"{page.relative_to(SITE)}: image missing alt: {tag[:100]}")
            if not width_pattern.search(tag) or not height_pattern.search(tag):
                fail(errors, f"{page.relative_to(SITE)}: image missing dimensions: {tag[:100]}")
            if src_match and not src_match.group(1).startswith(("http:", "https:", "data:")):
                image_target = (SITE / src_match.group(1).split("?", 1)[0]).resolve()
                if not image_target.exists():
                    fail(errors, f"{page.relative_to(SITE)}: broken image source {src_match.group(1)}")

    if errors:
        print("SEO AUDIT FAILED", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1

    print(
        f"SEO AUDIT PASS: {len(urls)} canonical pages, "
        f"{len(titles)} unique titles, {len(descriptions)} unique descriptions"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
