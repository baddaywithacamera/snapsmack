"""The SITE's mosaic composition, in Python, so BIGGIE can show what ships.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

WHY THIS FILE EXISTS. The editor had its own mosaic layout -- a uniform grid
in mosaic_layout.py, three columns of identical cells with the photograph
letterboxed inside. The site has never done that. assets/js/ss-engine-mosaic.js
sizes every cell FROM the photograph: within a row all tiles share a height and
their widths are proportional to aspect ratio. Measured on the real engine,
three photographs of aspect 1.5 / 0.75 / 1.5 in a 900px container with gap 5:

    tile 0   x 0     w 356    h 237.33
    tile 1   x 361   w 178    h 237.33
    tile 2   x 544   w 356    h 237.33      356+178+356 + 2 gaps = 900 exactly

A uniform grid cannot produce that at any setting, which is why the editor's
note "the site renders it identically" was never true. Sean asked for WYSIWYG;
this is the half of it that is arithmetic.

THIS IS A PORT, AND A PORT CAN DRIFT. That is the real cost and it is handled
rather than hoped away: tests/../test_mosaic_site_layout.py holds exact tile
rects captured from the REAL engine in a browser and fails if this file stops
agreeing with them. If someone edits assets/js/ss-engine-mosaic.js and not this
file, that test is what says so. It names the JS file in its failure message
for exactly that reason.

The alternative -- running the real engine in a QWebEngineView, one
implementation and correct by construction -- was costed and put to the owner:
tools/coldsnap/coldsnap-qt.spec excludes QtWebEngine deliberately to keep the
onefile exe lean, so adopting it means shipping a Chromium runtime. That is an
owner decision, and this port is reversible if it is taken.

PORTED FAITHFULLY, INCLUDING THE PARTS THAT LOOK ODD. Where the JS does
something surprising, it is reproduced and annotated rather than tidied: a
tidier port is a port that disagrees.
"""

from __future__ import annotations

import math

# ── the engine's constants, same values, same meaning ──────────────────────
MAX_SECTION_HEIGHT = 900      # ss-engine-mosaic.js:20 — the derivative ceiling
MOBILE_BREAKPOINT = 520       # ss-engine-mosaic.js:19 — below this, one per row


def image_ar(image: dict) -> float:
    """ss-engine-mosaic.js imageAR(). 1.5 when either side is missing or zero."""
    width = _number(image.get("width"))
    height = _number(image.get("height"))
    return width / height if width > 0 and height > 0 else 1.5


def _number(value) -> float:
    """JS Number(x) || 0 — a non-numeric or NaN value becomes 0."""
    try:
        out = float(value)
    except (TypeError, ValueError):
        return 0.0
    return 0.0 if math.isnan(out) else out


def _leaf(image, order):
    return {"type": "leaf", "image": image, "ar": image_ar(image), "order": order}


def _group(direction, children):
    return {"type": direction, "children": children}


# ── geometry: height = a x width + b ───────────────────────────────────────
def _coefficients(node, gap):
    """Every node's height is a linear function of its width.

    Keeping the gap in b is what lets nested groups land exactly on their
    container's edge -- it is why 356+178+356 plus two gaps is 900 and not
    899.7. The JS caches the children's coefficients ON the node and the
    horizontal branch of place_node reads them back; that is reproduced here
    because the two must see the same numbers.
    """
    if node["type"] == "leaf":
        return {"a": 1.0 / node["ar"], "b": 0.0}

    child = [_coefficients(item, gap) for item in node["children"]]
    node["_coefficients"] = child

    if node["type"] == "vertical":
        return {
            "a": sum(value["a"] for value in child),
            "b": sum(value["b"] for value in child) + gap * (len(child) - 1),
        }

    inverse_a = sum(1.0 / value["a"] for value in child)
    b_over_a = sum(value["b"] / value["a"] for value in child)
    return {
        "a": 1.0 / inverse_a,
        "b": (b_over_a - gap * (len(child) - 1)) / inverse_a,
    }


def _place_node(node, x, y, width, gap, output):
    own = _coefficients(node, gap)
    height = own["a"] * width + own["b"]

    if node["type"] == "leaf":
        output.append({
            "image": node["image"], "order": node["order"],
            "x": x, "y": y, "width": width, "height": height,
        })
        return height

    if node["type"] == "vertical":
        child_y = y
        for child in node["children"]:
            child_height = _place_node(child, x, child_y, width, gap, output)
            child_y += child_height + gap
        return height

    child_x = x
    for index, child in enumerate(node["children"]):
        coeff = node["_coefficients"][index]
        child_width = (height - coeff["b"]) / coeff["a"]
        _place_node(child, child_x, y, child_width, gap, output)
        child_x += child_width + gap
    return height


# ── which shapes are allowed ───────────────────────────────────────────────
def _tile_exceeds_limit(tile) -> bool:
    source_width = max(1.0, _number(tile["image"].get("width")) or MAX_SECTION_HEIGHT)
    source_height = max(1.0, _number(tile["image"].get("height")) or MAX_SECTION_HEIGHT)
    return (tile["width"] > min(MAX_SECTION_HEIGHT, source_width) + 0.01
            or tile["height"] > min(MAX_SECTION_HEIGHT, source_height) + 0.01)


def _tile_is_too_small(tile, container_width) -> bool:
    scale = min(1.0, container_width / 1200.0)
    return (tile["width"] < 260 * scale
            or tile["height"] < 200 * scale
            or tile["width"] * tile["height"] < 70000 * scale * scale)


def _block_is_visually_balanced(items, container_width) -> bool:
    smallest, largest = math.inf, 0.0
    for tile in items:
        if _tile_is_too_small(tile, container_width):
            return False
        area = tile["width"] * tile["height"]
        smallest = min(smallest, area)
        largest = max(largest, area)
    return smallest > 0 and largest / smallest <= 6


def _block_matches_emphasis(items, emphasis) -> bool:
    if emphasis not in ("portrait", "landscape"):
        return True
    hero = items[0]
    for tile in items[1:]:
        if tile["width"] * tile["height"] > hero["width"] * hero["height"]:
            hero = tile
    hero_is_portrait = image_ar(hero["image"]) < 1.15
    return hero_is_portrait if emphasis == "portrait" else not hero_is_portrait


def _block_score(items, height, container_width, emphasis, variant) -> float:
    portrait_max = 0.0
    landscape_max = 0.0
    smallest = math.inf
    lead_area = 0.0
    variant_area = 0.0
    variant_order = variant % min(len(items), 3) if items else 0
    for tile in items:
        area = tile["width"] * tile["height"]
        if tile["order"] == 0:
            lead_area = area
        if tile["order"] == variant_order:
            variant_area = area
        smallest = min(smallest, area)
        if image_ar(tile["image"]) < 1.15:
            portrait_max = max(portrait_max, area)
        else:
            landscape_max = max(landscape_max, area)

    if emphasis == "portrait":
        score = portrait_max * 20 + landscape_max
    elif emphasis == "landscape":
        score = landscape_max * 20 + portrait_max
    elif emphasis == "balanced":
        score = min(portrait_max, landscape_max) * 5 + max(portrait_max, landscape_max)
    else:
        score = (variant_area * 4 + lead_area * 0.35
                 + max(portrait_max, landscape_max) + min(portrait_max, landscape_max))

    score += smallest * 0.2
    score -= abs(height - 850) * container_width * 0.12
    return score


# ── composition ────────────────────────────────────────────────────────────
def _build_section(images, prefer_landscape_hero, template):
    cells = [_leaf(image, index) for index, image in enumerate(images)]

    if len(cells) == 1:
        return cells[0]
    if len(cells) == 2:
        return _group("horizontal", cells)

    if len(cells) == 3:
        if template == "one-left":
            return _group("horizontal", [cells[0], _group("vertical", cells[1:])])
        if template == "one-right":
            return _group("horizontal", [_group("vertical", cells[1:]), cells[0]])
        if template == "three-across":
            return _group("horizontal", cells)
        if template == "one-top":
            return _group("vertical", [cells[0], _group("horizontal", cells[1:])])
        if cells[0]["ar"] < 1.15:
            return _group("horizontal", [cells[0], _group("vertical", cells[1:])])
        if prefer_landscape_hero:
            return _group("horizontal", [cells[0], _group("vertical", cells[1:])])
        return _group("horizontal", cells)

    if cells[0]["ar"] < 1.15:
        return _group("horizontal", [cells[0], _group("vertical", cells[1:])])

    return _group("horizontal", [
        _group("vertical", [cells[0], _group("horizontal", cells[2:4])]),
        cells[1],
    ])


def _section_size(remaining: int) -> int:
    if remaining <= 6:
        return remaining
    if remaining in (7, 8):
        return 4
    if remaining == 9:
        return 5
    if remaining == 10:
        return 5
    if remaining in (11, 12):
        return 6
    if remaining % 6 == 1:
        return 5
    if remaining % 6 == 2:
        return 4
    return 6


def _candidate_trees(images, start, end, memo):
    """Every binary split, both orientations. Bounded: a block is at most six
    photographs, so the worst case is 42 tree shapes times their orientations."""
    key = (start, end)
    if key in memo:
        return memo[key]
    if end - start == 1:
        memo[key] = [_leaf(images[start], start)]
        return memo[key]

    trees = []
    for split in range(start + 1, end):
        left = _candidate_trees(images, start, split, memo)
        right = _candidate_trees(images, split, end, memo)
        for a in left:
            for b in right:
                trees.append(_group("horizontal", [a, b]))
                trees.append(_group("vertical", [a, b]))
    memo[key] = trees
    return trees


def _solve_block(images, y, container_width, gap, emphasis, enforce_useful_size, variant):
    best = None
    for tree in _candidate_trees(images, 0, len(images), {}):
        trial = []
        height = _place_node(tree, 0, y, container_width, gap, trial)
        # A block MUST draw every photograph it was handed; the caller advances
        # by the number it ASKED for, so an incomplete composition would skip a
        # photograph silently.
        if len(trial) != len(images):
            continue
        if height <= 0 or any(_tile_exceeds_limit(t) for t in trial):
            continue
        if enforce_useful_size and (not _block_is_visually_balanced(trial, container_width)
                                    or not _block_matches_emphasis(trial, emphasis)):
            continue
        score = _block_score(trial, height, container_width, emphasis, variant or 0)
        # Strictly greater: a tie keeps the FIRST candidate, as the JS does.
        if best is None or score > best["score"]:
            best = {"items": trial, "height": height, "score": score}
    return best


def _preferred_block(images, y, container_width, gap, emphasis, template, variant):
    enforce_useful_size = container_width >= 1200
    if len(images) >= 5:
        return _solve_block(images, y, container_width, gap, emphasis,
                            enforce_useful_size, variant)
    tree = _build_section(images, False, template)
    items = []
    height = _place_node(tree, 0, y, container_width, gap, items)
    if (len(items) == len(images) and height > 0
            and not any(_tile_exceeds_limit(t) for t in items)
            and (not enforce_useful_size or _block_is_visually_balanced(items, container_width))
            and _block_matches_emphasis(items, emphasis)):
        return {"items": items, "height": height, "score": 0}
    return _solve_block(images, y, container_width, gap, emphasis,
                        enforce_useful_size, variant)


def _bounded_single(image, y, container_width):
    ar = image_ar(image)
    source_width = max(1.0, _number(image.get("width")) or MAX_SECTION_HEIGHT)
    source_height = max(1.0, _number(image.get("height")) or MAX_SECTION_HEIGHT)
    max_width = min(container_width, MAX_SECTION_HEIGHT, source_width)
    max_height = min(MAX_SECTION_HEIGHT, source_height)
    width = min(max_width, max_height * ar)
    height = width / ar
    x = max(0.0, (container_width - width) / 2.0)
    return {
        "items": [{"image": image, "order": 0, "x": x, "y": y,
                   "width": width, "height": height}],
        "height": height, "x": x, "width": width, "score": 0,
    }


def compute_layout(images, container_width, gap=4, emphasis="natural", template=""):
    """ss-engine-mosaic.js computeLayout(), same arguments, same answer.

    Returns {"items": [...], "sections": [...], "height": float} with each item
    carrying image, order, x, y, width, height in container pixels.

    THE ONE CASE THIS CANNOT REPRODUCE, and it is not a defect here: a mosaic of
    exactly THREE photographs saved with the default 'asymmetric' layout. The
    site then picks one-left or one-right by hashing window.location.pathname
    (stableTripletSide, ss-engine-mosaic.js:25-34) and alternates against the
    previous mosaic on the same page. An editor has no URL yet and does not know
    what will sit above it. core/parser.php:492 emits data-template for every
    OTHER saved layout, so everything else is pinned and exact; _build_section
    only consults the template at three photographs, so four or more are exact
    too. Callers should tell the owner when they are in that one case rather
    than present a guess as the finished page.
    """
    gap = max(0, min(20, _number(gap)))
    container_width = max(0.0, _number(container_width))
    if emphasis not in ("natural", "balanced", "landscape", "portrait"):
        emphasis = "natural"
    if not images or container_width == 0:
        return {"items": [], "sections": [], "height": 0.0}

    sections = []
    items = []
    index = 0
    y = 0.0
    section_index = 0

    if container_width <= MOBILE_BREAKPOINT:
        for image in images:
            single = _bounded_single(image, y, container_width)
            items.extend(single["items"])
            sections.append({"x": single["x"], "y": y,
                             "width": single["width"], "height": single["height"]})
            y += single["height"] + gap
        return {"items": items, "sections": sections, "height": max(0.0, y - gap)}

    while index < len(images):
        remaining = len(images) - index
        count = _section_size(remaining)
        solved = None
        section_images = []

        while count >= 2 and not solved:
            section_images = images[index:index + count]
            solved = _preferred_block(section_images, y, container_width, gap,
                                      emphasis, template, section_index)
            # One saved mosaic stays one composition whenever its photographs
            # fit the hard ceiling: relax the aesthetic preferences before
            # splitting the bundle into ragged sections.
            if not solved:
                solved = _solve_block(section_images, y, container_width, gap,
                                      emphasis, False, section_index)
            if not solved:
                count -= 1

        if not solved:
            count = 1
            solved = _bounded_single(images[index], y, container_width)

        section_items = sorted(solved["items"], key=lambda t: t["order"])
        items.extend(section_items)
        sections.append({
            "x": solved.get("x", 0), "y": y,
            "width": solved.get("width", container_width),
            "height": solved["height"],
        })
        y += solved["height"] + gap
        index += count
        section_index += 1

    return {"items": items, "sections": sections, "height": max(0.0, y - gap)}

# ===== SNAPSMACK EOF =====
