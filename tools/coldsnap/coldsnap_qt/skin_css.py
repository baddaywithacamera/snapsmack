"""Fetch and resolve the active site's real rendered styles for BIGGIE WYSIWYG fidelity."""

import asyncio
import json
import os
import re
import shutil
import subprocess
import tempfile
from urllib.parse import urljoin

import requests

_SITE_CACHE = {}

_EDGE_PATHS = (
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
)

_OS_SAFE_FONTS = {
    "arial", "georgia", "times new roman", "times", "verdana",
    "courier new", "courier", "segoe ui", "tahoma", "trebuchet ms", "calibri",
    "consolas", "palatino", "palatino linotype", "garamond", "impact",
    "comic sans ms", "lucida sans unicode", "lucida console",
}

_GENERIC_FALLBACK = {
    "sans-serif": "Arial",
    "serif": "Georgia",
    "monospace": "Courier New",
    "cursive": "Comic Sans MS",
    "fantasy": "Impact",
    "system-ui": "Segoe UI",
}


def resolve_visitor_font(font_stack: str, loaded_webfonts=None) -> str:
    """Resolve the font a visitor actually sees, skipping unloaded webfonts."""
    loaded = {str(f or "").strip().strip("'\"").lower() for f in (loaded_webfonts or []) if str(f or "").strip()}
    for part in str(font_stack or "").split(","):
        fam = part.strip().strip("'\"")
        low = fam.lower()
        if not fam:
            continue
        if low in _GENERIC_FALLBACK:
            return _GENERIC_FALLBACK[low]
        if low in loaded or low in _OS_SAFE_FONTS:
            return fam
    return "Arial"


def _parse_len_px(raw: str, base_px: float) -> float:
    s = str(raw or "").strip().lower()
    if not s:
        return 0.0
    m = re.match(r"^([0-9]+(?:\.[0-9]+)?)\s*(px|em|rem|pt)?$", s)
    if not m:
        return 0.0
    val = float(m.group(1))
    unit = m.group(2) or "px"
    if unit in ("em", "rem"):
        return val * base_px
    if unit == "pt":
        return val * (4.0 / 3.0)
    return val


def _rgb_to_hex(raw: str) -> str:
    s = str(raw or "").strip()
    if re.match(r"^#[0-9a-fA-F]{3,8}$", s):
        return s
    m = re.match(r"^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([0-9.]+))?\s*\)$", s, re.I)
    if not m:
        return ""
    if m.group(4) is not None and float(m.group(4)) == 0.0:
        return ""
    r, g, b = int(m.group(1)), int(m.group(2)), int(m.group(3))
    return f"#{r:02x}{g:02x}{b:02x}"


def _extract_rule_bodies(css_text: str, selector_pattern: str) -> list:
    bodies = []
    clean = re.sub(r"/\*.*?\*/", "", css_text or "", flags=re.S)
    for m in re.finditer(r"(?:^|(?<=\})|(?<=;))\s*" + selector_pattern + r"\s*\{([^}]*)\}", clean, re.I | re.S):
        bodies.append(m.group(1))
    return bodies


def _first_prop(bodies: list, prop: str) -> str:
    val = ""
    pat = re.compile(r"(?:^|;)\s*" + re.escape(prop) + r"\s*:\s*([^;]+)", re.I)
    for body in bodies:
        for m in pat.finditer(body):
            val = m.group(1).strip()
    return val


def parse_skin_css(css_text: str) -> dict:
    """Extract typography, colours, and post-spacing rules from a skin stylesheet."""
    body_rules = _extract_rule_bodies(
        css_text,
        r"(?:[^{};]*,\s*)?body(?:\.(?:blog|single|archive|post|tilez-v2))?(?:\s*,[^{};]*)?",
    )
    entry_rules = _extract_rule_bodies(
        css_text,
        r"(?:[^{};]*,\s*)?(?:\.entry-content|\.post-content)(?:\s*,[^{};]*)?",
    )
    p_rules = _extract_rule_bodies(css_text, r"\.entry-content\s*>\s*p(?:\s*,[^{]*)?")
    frame_rules = _extract_rule_bodies(css_text, r"\.entry-content\s*>\s*\.snap-inline-frame")
    owned_rules = _extract_rule_bodies(css_text, r"\.smacktalk-owned-photo")

    # Webfonts actually declared via @font-face in this stylesheet
    declared_webfonts = re.findall(r"@font-face\s*\{[^}]*font-family\s*:\s*([^;}\n]+)", css_text or "", re.I)
    loaded_wf = [w.strip().strip("'\"") for w in declared_webfonts]

    bg = _first_prop(entry_rules, "background") or _first_prop(entry_rules, "background-color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", bg):
        bg = _first_prop(body_rules, "background") or _first_prop(body_rules, "background-color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", bg):
        bg = ""
    fg = _first_prop(entry_rules, "color") or _first_prop(body_rules, "color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", fg):
        fg = ""

    font_raw = _first_prop(entry_rules, "font-family") or _first_prop(body_rules, "font-family")
    body_font = resolve_visitor_font(font_raw, loaded_wf) if font_raw else ""

    fs_raw = _first_prop(entry_rules, "font-size")
    body_px = _parse_len_px(fs_raw, 16.0) if fs_raw else 0.0
    body_pt = round(body_px * 0.75, 2) if body_px > 0 else 0.0
    base_px = body_px if body_px > 0 else 16.0

    lh_raw = _first_prop(entry_rules, "line-height")
    line_height_pct = 0.0
    if lh_raw:
        if lh_raw.endswith("%"):
            try:
                line_height_pct = float(lh_raw[:-1].strip())
            except ValueError:
                pass
        elif re.match(r"^[0-9]+(?:\.[0-9]+)?$", lh_raw):
            line_height_pct = float(lh_raw) * 100.0
        else:
            px = _parse_len_px(lh_raw, base_px)
            if px > 0 and base_px > 0:
                line_height_pct = (px / base_px) * 100.0

    p_mb_raw = _first_prop(p_rules, "margin-bottom")
    para_margin_bottom_px = _parse_len_px(p_mb_raw, base_px) if p_mb_raw else 0.0

    img_mt_raw = _first_prop(frame_rules, "margin-top")
    img_mb_raw = _first_prop(frame_rules, "margin-bottom")
    image_margin_top_px = _parse_len_px(img_mt_raw, base_px) if img_mt_raw else 0.0
    image_margin_bottom_px = _parse_len_px(img_mb_raw, base_px) if img_mb_raw else 0.0
    if image_margin_bottom_px == 0.0 and owned_rules:
        owned_m = _first_prop(owned_rules, "margin")
        parts = owned_m.split()
        if len(parts) >= 3:
            image_margin_top_px = image_margin_top_px or _parse_len_px(parts[0], base_px)
            image_margin_bottom_px = _parse_len_px(parts[2], base_px)

    loaded = bool(body_font or body_pt > 0 or bg or fg or image_margin_top_px > 0)

    fig_rules = _extract_rule_bodies(css_text, r"(?:^|\}|\s)figcaption")
    cap_color = _first_prop(fig_rules, "color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", cap_color):
        cap_color = ""
    cap_fs = _first_prop(fig_rules, "font-size")
    cap_px = _parse_len_px(cap_fs, base_px) if cap_fs else 0.0
    cap_pt = round(cap_px * 0.75, 2) if cap_px > 0 else 0.0
    cap_font_raw = _first_prop(fig_rules, "font-family")
    cap_font = resolve_visitor_font(cap_font_raw, loaded_wf) if cap_font_raw else ""
    cap_style = _first_prop(fig_rules, "font-style").lower()
    cap_italic = (cap_style != "normal") if cap_style else True
    cap_pad_top = _parse_len_px(_first_prop(fig_rules, "padding-top"), base_px)

    return {
        "loaded": loaded,
        "bg": bg,
        "fg": fg,
        "body_font": body_font,
        "body_px": body_px,
        "body_pt": body_pt,
        "line_height_pct": line_height_pct,
        "para_margin_bottom_px": para_margin_bottom_px,
        "image_margin_top_px": image_margin_top_px,
        "image_margin_bottom_px": image_margin_bottom_px,
        "caption_color": cap_color,
        "caption_font": cap_font,
        "caption_px": cap_px,
        "caption_pt": cap_pt,
        "caption_italic": cap_italic,
        "caption_padding_top_px": cap_pad_top,
    }


def parse_sybu_skin(skin: dict) -> dict:
    """Extract authoritative declared skin values from sybu-data.php's `skin` block."""
    if not isinstance(skin, dict):
        return {}
    opts = skin.get("options") if isinstance(skin.get("options"), dict) else {}
    bg = ""
    for k in ("bg_color", "background_color", "page_bg", "wall_color", "htbs_wall_color"):
        v = str(opts.get(k) or "").strip()
        if re.match(r"^#[0-9a-fA-F]{3,8}$", v):
            bg = v
            break
    fg = ""
    for k in ("ink_color", "fg_color", "text_color", "body_color"):
        v = str(opts.get(k) or "").strip()
        if re.match(r"^#[0-9a-fA-F]{3,8}$", v):
            fg = v
            break
    body_font = ""
    for k in ("body_font_family", "body_font", "font_family"):
        v = str(opts.get(k) or "").strip()
        if v:
            body_font = v.split(",")[0].strip(" '\"")
            break
    cap_color = ""
    for k in ("caption_color", "figcaption_color"):
        v = str(opts.get(k) or "").strip()
        if re.match(r"^#[0-9a-fA-F]{3,8}$", v):
            cap_color = v
            break
    cap_font = ""
    for k in ("caption_font_family", "figcaption_font_family"):
        v = str(opts.get(k) or "").strip()
        if v:
            cap_font = v.split(",")[0].strip(" '\"")
            break
    cap_pt = 0.0
    for k in ("caption_size", "caption_font_size", "figcaption_font_size"):
        v = str(opts.get(k) or "").strip()
        if v:
            px = _parse_len_px(v, 16.0)
            if px > 0:
                cap_pt = round(px * 0.75, 2)
            break
    cap_italic = None
    if "caption_style" in opts and str(opts.get("caption_style") or "").strip():
        cap_italic = str(opts.get("caption_style")).strip().lower() != "normal"
    elif "caption_italic" in opts:
        cap_italic = str(opts.get("caption_italic")).strip().lower() not in ("0", "false", "no", "normal")
    cap_align = ""
    raw_align = str(opts.get("caption_align") or "").strip().lower()
    if raw_align in ("left", "center", "right"):
        cap_align = raw_align
    elif raw_align == "inherit":
        cap_align = "left"
    cap_gap_px = 0.0
    if opts.get("caption_gap") not in (None, ""):
        cap_gap_px = _parse_len_px(str(opts.get("caption_gap")), 16.0)
    content_width = 0
    raw_w = opts.get("post_content_width")
    if raw_w not in (None, ""):
        try:
            w = int(float(raw_w))
            if 280 <= w <= 2400:
                content_width = w
        except (TypeError, ValueError):
            pass
    return {
        "skin_slug": str(skin.get("slug") or ""),
        "skin_name": str(skin.get("name") or ""),
        "skin_version": str(skin.get("version") or ""),
        "bg": bg,
        "fg": fg,
        "body_font": body_font,
        "caption_color": cap_color,
        "caption_font": cap_font,
        "caption_pt": cap_pt,
        "caption_italic": cap_italic,
        "caption_align": cap_align,
        "caption_gap_px": cap_gap_px,
        "post_content_width": content_width,
        "has_sybu": bool(skin.get("slug") or opts),
    }


def _find_browser_exe() -> str:
    for p in _EDGE_PATHS:
        if os.path.isfile(p):
            return p
    return ""


def _pick_sample_post_url(site_url: str, html_text: str) -> str:
    """If site_url is a landing page, pick the first single-post link for computed-style measurement."""
    if re.search(r'class=["\'][^"\']*\b(?:post-content|entry-content)\b', html_text, re.I):
        return site_url
    base = site_url.rstrip("/")
    for m in re.finditer(r'<a[^>]+href=["\']([^"\']+)["\']', html_text, re.I):
        href = m.group(1).strip()
        if not href or href.startswith(("#", "mailto:", "javascript:")):
            continue
        full = urljoin(site_url, href)
        if not full.startswith(base):
            continue
        path = full[len(base):].split("?", 1)[0].strip("/")
        if path and "/" not in path and path not in (
            "archive", "blogroll", "feed", "rss", "sitemap.xml", "login", "admin",
        ) and not path.endswith((".css", ".js", ".png", ".jpg", ".jpeg", ".webp")):
            return full
    return site_url


def probe_rendered_post_styles(post_url: str, *, timeout: float = 10.0) -> dict:
    """Measure live computed styles & loaded webfonts from a real headless Edge render."""
    browser = _find_browser_exe()
    if not browser:
        return {"loaded": False}
    try:
        import websockets  # noqa: F401
    except ImportError:
        return {"loaded": False}

    js = r"""
    (() => {
      const container = document.querySelector('.post-content.entry-content, .entry-content, .post-content');
      const el = container ? container.querySelector('p') : document.querySelector('article p');
      if (!el && !container) return { found: false };
      const cap = container ? container.querySelector('figcaption') : document.querySelector('figcaption');
      const img = container ? container.querySelector('.snap-inline-frame, figure') : null;
      const cs = getComputedStyle(el || container);
      const bcs = getComputedStyle(document.body);
      const capcs = cap ? getComputedStyle(cap) : null;
      const imgcs = img ? getComputedStyle(img) : null;
      const loaded = [];
      if (document.fonts) {
        for (const f of document.fonts) {
          if (f.status === 'loaded') loaded.push(f.family.replace(/^['"]|['"]$/g, ''));
        }
      }
      return {
        found: true,
        loadedFonts: [...new Set(loaded)],
        containerWidth: Math.round((el || container).getBoundingClientRect().width),
        fontFamily: cs.fontFamily,
        fontSize: cs.fontSize,
        lineHeight: cs.lineHeight,
        marginBottom: cs.marginBottom,
        color: cs.color,
        bg: bcs.backgroundColor,
        imgMarginTop: imgcs ? imgcs.marginTop : '',
        imgMarginBottom: imgcs ? imgcs.marginBottom : '',
        capFont: capcs ? capcs.fontFamily : '',
        capSize: capcs ? capcs.fontSize : '',
        capColor: capcs ? capcs.color : '',
        capStyle: capcs ? capcs.fontStyle : '',
        capAlign: capcs ? capcs.textAlign : '',
        capPadTop: capcs ? capcs.paddingTop : '',
      };
    })()
    """

    async def _run():
        import websockets
        td = tempfile.mkdtemp(prefix="coldsnap_probe_")
        p = subprocess.Popen(
            [
                browser, "--headless=new", "--disable-gpu", "--no-first-run",
                "--no-default-browser-check", "--remote-debugging-port=0",
                f"--user-data-dir={td}", "--window-size=1280,1000", "about:blank",
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
            text=True,
        )
        try:
            ws_base = None
            for _ in range(80):
                line = p.stderr.readline()
                if not line:
                    await asyncio.sleep(0.05)
                    continue
                m = re.search(r"(ws://127\.0\.0\.1:\d+/devtools/browser/\S+)", line)
                if m:
                    ws_base = m.group(1)
                    break
            if not ws_base:
                return {"loaded": False}
            port = re.search(r":(\d+)/", ws_base).group(1)
            tabs = requests.get(f"http://127.0.0.1:{port}/json/list", timeout=2).json()
            page_tab = next((t for t in tabs if t.get("type") == "page"), None)
            if not page_tab:
                return {"loaded": False}
            async with websockets.connect(page_tab["webSocketDebuggerUrl"], max_size=10_000_000) as ws:
                await ws.send(json.dumps({"id": 1, "method": "Page.enable"}))
                await ws.send(json.dumps({"id": 2, "method": "Page.navigate", "params": {"url": post_url}}))
                while True:
                    msg = json.loads(await ws.recv())
                    if msg.get("method") == "Page.loadEventFired":
                        break
                await asyncio.sleep(0.3)
                await ws.send(json.dumps({
                    "id": 3, "method": "Runtime.evaluate",
                    "params": {"expression": js, "returnByValue": True},
                }))
                while True:
                    msg = json.loads(await ws.recv())
                    if msg.get("id") == 3:
                        raw = msg.get("result", {}).get("result", {}).get("value") or {}
                        break
            if not raw.get("found"):
                return {"loaded": False}
            loaded_fonts = raw.get("loadedFonts") or []
            body_px = _parse_len_px(raw.get("fontSize", ""), 16.0)
            lh_px = _parse_len_px(raw.get("lineHeight", ""), body_px or 16.0)
            lh_pct = round((lh_px / body_px) * 100.0, 2) if (body_px > 0 and lh_px > 0) else 0.0
            cap_px = _parse_len_px(raw.get("capSize", ""), body_px or 16.0)
            return {
                "loaded": True,
                "from_real_render": True,
                "loaded_fonts": loaded_fonts,
                "container_width": int(raw.get("containerWidth") or 0),
                "bg": _rgb_to_hex(raw.get("bg", "")) or "#ffffff",
                "fg": _rgb_to_hex(raw.get("color", "")) or "#282828",
                "body_font": resolve_visitor_font(raw.get("fontFamily", ""), loaded_fonts),
                "body_px": body_px,
                "body_pt": round(body_px * 0.75, 2) if body_px > 0 else 0.0,
                "line_height_pct": lh_pct,
                "para_margin_bottom_px": _parse_len_px(raw.get("marginBottom", ""), body_px or 16.0),
                "image_margin_top_px": _parse_len_px(raw.get("imgMarginTop", ""), body_px or 16.0),
                "image_margin_bottom_px": _parse_len_px(raw.get("imgMarginBottom", ""), body_px or 16.0),
                "caption_font": resolve_visitor_font(raw.get("capFont", "") or raw.get("fontFamily", ""), loaded_fonts),
                "caption_px": cap_px,
                "caption_pt": round(cap_px * 0.75, 2) if cap_px > 0 else 0.0,
                "caption_color": _rgb_to_hex(raw.get("capColor", "")),
                "caption_italic": str(raw.get("capStyle") or "").lower() != "normal",
                "caption_align": str(raw.get("capAlign") or "").lower() if raw.get("capAlign") in ("left", "center", "right") else "left",
                "caption_padding_top_px": _parse_len_px(raw.get("capPadTop", ""), body_px or 16.0),
            }
        finally:
            p.kill()
            try:
                p.wait(timeout=2)
            except Exception:
                pass
            shutil.rmtree(td, ignore_errors=True)

    try:
        return asyncio.run(asyncio.wait_for(_run(), timeout=timeout))
    except Exception:  # noqa: BLE001
        return {"loaded": False}


def fetch_site_skin_styles(site_url: str, *, timeout: float = 6.0) -> dict:
    """Measure live rendered styles (or fall back to skin stylesheet CSS) for a site."""
    url = str(site_url or "").strip()
    if not url or not url.startswith(("http://", "https://")):
        return {"loaded": False}
    if url in _SITE_CACHE:
        return dict(_SITE_CACHE[url])

    try:
        resp = requests.get(url, timeout=timeout)
        resp.raise_for_status()
        post_url = _pick_sample_post_url(resp.url, resp.text)
        rendered = probe_rendered_post_styles(post_url, timeout=10.0)
        if rendered.get("loaded"):
            _SITE_CACHE[url] = dict(rendered)
            return rendered

        links = re.findall(r'<link[^>]+rel=["\']stylesheet["\'][^>]*>', resp.text, re.I)
        skin_href = ""
        for ln in links:
            m = re.search(r'href=["\']([^"\']+)["\']', ln, re.I)
            if m and ("/skins/" in m.group(1) or "style.css" in m.group(1)):
                skin_href = urljoin(resp.url, m.group(1))
                break
        if not skin_href:
            return {"loaded": False}
        css_resp = requests.get(skin_href, timeout=timeout)
        css_resp.raise_for_status()
        parsed = parse_skin_css(css_resp.text)
        parsed["stylesheet_url"] = skin_href
        _SITE_CACHE[url] = dict(parsed)
        return parsed
    except Exception:  # noqa: BLE001
        return {"loaded": False}
