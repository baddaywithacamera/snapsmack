"""Fetch and parse the active site's skin CSS for BIGGIE WYSIWYG fidelity."""

import re
from urllib.parse import urljoin

import requests

_SITE_CACHE = {}


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


def _extract_rule_bodies(css_text: str, selector_pattern: str) -> list:
    bodies = []
    # Strip CSS comments first so commented-out examples don't pollute matches
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

    # Colour & background
    bg = _first_prop(entry_rules, "background") or _first_prop(entry_rules, "background-color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", bg):
        bg = _first_prop(body_rules, "background") or _first_prop(body_rules, "background-color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", bg):
        bg = ""
    fg = _first_prop(entry_rules, "color") or _first_prop(body_rules, "color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", fg):
        fg = ""

    # Font family & size
    font_raw = _first_prop(entry_rules, "font-family") or _first_prop(body_rules, "font-family")
    body_font = font_raw.split(",")[0].strip(" '\"") if font_raw else ""

    # Use first non-media .entry-content font-size (desktop), fallback to last
    fs_raw = ""
    for body in entry_rules:
        m = re.search(r"(?:^|;)\s*font-size\s*:\s*([^;]+)", body, re.I)
        if m:
            fs_raw = m.group(1).strip()
            break
    body_px = _parse_len_px(fs_raw, 16.0) if fs_raw else 0.0
    body_pt = round(body_px * 0.75, 2) if body_px > 0 else 0.0
    base_px = body_px if body_px > 0 else 16.0

    # Line height
    lh_raw = ""
    for body in entry_rules:
        m = re.search(r"(?:^|;)\s*line-height\s*:\s*([^;]+)", body, re.I)
        if m:
            lh_raw = m.group(1).strip()
            break
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

    # Paragraph bottom margin
    p_mb_raw = _first_prop(p_rules, "margin-bottom")
    para_margin_bottom_px = _parse_len_px(p_mb_raw, base_px) if p_mb_raw else 0.0

    # Image frame top/bottom margins
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

    # Figcaption styling (e.g. TILEZ / ALFRED / TELEGRAM: color #666; font-size: 14px; font-style: italic; padding-top: 12px)
    fig_rules = _extract_rule_bodies(css_text, r"(?:^|\}|\s)figcaption")
    cap_color = _first_prop(fig_rules, "color")
    if not re.match(r"^#[0-9a-fA-F]{3,8}$", cap_color):
        cap_color = ""
    cap_fs = _first_prop(fig_rules, "font-size")
    cap_px = _parse_len_px(cap_fs, base_px) if cap_fs else 0.0
    cap_pt = round(cap_px * 0.75, 2) if cap_px > 0 else 0.0
    cap_font_raw = _first_prop(fig_rules, "font-family")
    cap_font = cap_font_raw.split(",")[0].strip(" '\"") if cap_font_raw else ""
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
    """Extract authoritative declared skin values from sybu-data.php's `skin` block.

    Absence is information: keys not declared by the skin manifest are left empty/0
    so callers can fall back deliberately to the stylesheet parser and report honestly.
    """
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


def fetch_site_skin_styles(site_url: str, *, timeout: float = 6.0) -> dict:
    """Fetch the site's <head> skin stylesheet and return parsed skin styles."""
    url = str(site_url or "").strip()
    if not url or not url.startswith(("http://", "https://")):
        return {"loaded": False}
    if url in _SITE_CACHE:
        return dict(_SITE_CACHE[url])

    try:
        resp = requests.get(url, timeout=timeout)
        resp.raise_for_status()
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
