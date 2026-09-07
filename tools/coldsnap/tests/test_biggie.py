"""BIGGIE blocks ⇄ body-string contract.

Pins: every block type serializes to the exact string the CMS shortcode
toolbar emits; parsing recognises them back; anything unrecognised survives
byte-for-byte as a RAW block; the BodyEditor toggle is lossless both ways.
Headless (offscreen Qt, SNAPSMACK_HOME sandbox).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import tempfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="biggie-test-")
_TOOL = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _TOOL)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(_TOOL)), "tools", "_shared"))

from PySide6.QtWidgets import QApplication  # noqa: E402

app = QApplication.instance() or QApplication([])

from coldsnap_qt import biggie  # noqa: E402
from coldsnap_qt.body_editor import BodyEditor  # noqa: E402
import sumna_offline as O  # noqa: E402

passed = 0


def check(name, got, want=True):
    global passed
    assert got == want, f"{name}:\n  got:  {got!r}\n  want: {want!r}"
    passed += 1


S = biggie.serialize_block
# --- serialization: byte-identical to the web toolbar vocabulary -------------
check("heading", S({"type": "heading", "level": 2, "text": "Hi"}), "<h2>Hi</h2>")
check("h3", S({"type": "heading", "level": 3, "text": "Sub"}), "<h3>Sub</h3>")
check("quote", S({"type": "quote", "text": "said so"}), "<blockquote>said so</blockquote>")
check("hr", S({"type": "hr"}), "<hr>")
check("list", S({"type": "list", "ordered": False, "items": ["a", "b"]}),
      "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>")
check("ol", S({"type": "list", "ordered": True, "items": ["x"]}),
      "<ol>\n  <li>x</li>\n</ol>")
check("image", S({"type": "image", "img_id": "42", "size": "wall", "align": "left"}),
      "[img:42|wall|left]")
check("columns matches web insertColumns", S({"type": "columns",
      "cols": [[{"type": "para", "text": "First column content."}],
               [{"type": "para", "text": "Column 2 content."}]], "ratio": "equal"}),
      "[columns=2]\nFirst column content.\n\n[col]\n\nColumn 2 content.\n[/columns]")
check("dropcap belongs to paragraph",
      S({"type": "para", "text": "Words here.", "dropcap": True}),
      "[dropcap]W[/dropcap]ords here.")
check("pullquote", S({"type": "pullquote", "text": "Look at this."}),
      "[pullquote]Look at this.[/pullquote]")
check("one-column container", S({"type": "columns", "cols": [[
      {"type": "heading", "level": 2, "text": "Inside"},
      {"type": "para", "text": "Copy"}]]}),
      "[columns=1]\n<h2>Inside</h2>\n\nCopy\n[/columns]")
check("asymmetric columns", S({"type": "columns", "ratio": "1-2", "cols": [
      [{"type": "image", "img_id": "9", "size": "full", "align": "center"}],
      [{"type": "para", "text": "Wide copy"}]]}),
      "[columns=2 ratio=1-2]\n[img:9|full|center]\n\n[col]\n\nWide copy\n[/columns]")
check("spacer", S({"type": "spacer", "px": 30}), "[spacer:30]")
check("spacer clamps", S({"type": "spacer", "px": 900}), "[spacer:100]")
check("mosaic", S({"type": "mosaic"}), "[mosaic]")
check("para verbatim", S({"type": "para", "text": "hello <em>there</em>"}),
      "hello <em>there</em>")

# --- parse: recognised constructs come back typed ----------------------------
body = ("<h2>Title</h2>\n\n"
        "A paragraph with <strong>bold</strong>.\n\n"
        "<ul>\n  <li>one</li>\n  <li>two</li>\n</ul>\n\n"
        "[columns=2 ratio=1-2]\n<h3>Left</h3>\n\nleft side\n\n[col]\n\nright side\n[/columns]\n\n"
        "[img:7|full|center]\n\n"
        "[spacer:20]\n\n"
        "[dropcap]O[/dropcap]pening words.\n\n"
        "[pullquote]pulled words[/pullquote]\n\n"
        "<blockquote>quoted</blockquote>\n\n"
        "<hr>\n\n"
        "[mosaic]")
blocks = biggie.parse_body(body)
types = [b["type"] for b in blocks]
check("parse types", types, ["heading", "para", "list", "columns", "image",
                             "spacer", "para", "pullquote", "quote", "hr", "mosaic"])
check("columns contain blocks", blocks[3]["cols"], [
      [{"type": "heading", "level": 3, "text": "Left"}, {"type": "para", "text": "left side"}],
      [{"type": "para", "text": "right side"}]])
check("column ratio parsed", blocks[3]["ratio"], "1-2")
check("dropcap parsed as paragraph option", blocks[6],
      {"type": "para", "text": "Opening words.", "dropcap": True})
check("list items", blocks[2]["items"], ["one", "two"])

# --- lossless: serialize(parse(body)) == body --------------------------------
check("round trip", biggie.serialize_blocks(blocks), body)

# --- unknown shortcode → RAW, byte-preserved ---------------------------------
odd = "[lede]big intro[/lede]"
pb = biggie.parse_body(odd)
check("unknown is raw", pb[0]["type"], "raw")
check("raw byte-preserved", biggie.serialize_blocks(pb), odd)
check("mosaic:ID stays raw", biggie.parse_body("[mosaic:123]")[0]["type"], "raw")

# --- BodyEditor: toggle is lossless both ways --------------------------------
ed = BodyEditor(allow_mosaic=True)
ed.setPlainText(body)
ed._set_biggie(True)
check("editor to blocks and back", ed.toPlainText(), body)
check("blocks_json present in BIGGIE face", len(ed.blocks_json()) > 2)
ed._set_biggie(False)
check("simple face restored text", ed.editor.toPlainText(), body)
check("blocks_json empty in simple face", ed.blocks_json(), "")

# --- widget round-trip: from_blocks → to_blocks ------------------------------
ed2 = BodyEditor(allow_mosaic=True)
ed2.set_state(body, biggie.blocks_to_json(blocks))
check("set_state activates BIGGIE", ed2._biggie_on, True)
check("widget model round-trips", ed2.biggie.to_blocks(), blocks)

# --- nested columns are deliberately unavailable ----------------------------
outer = biggie.BiggieEditor()
outer.add_block({"type": "columns", "cols": [[{"type": "para", "text": "cell"}]]})
cell_editor = outer._rows[0]._col_editors[0]
check("column editor forbids nested columns", cell_editor.allow_columns, False)
check("v1 dropcap block migrates to paragraph option",
      biggie.blocks_from_json('[{"type":"dropcap","text":"W"}]'),
      [{"type": "para", "text": "W", "dropcap": True}])

# --- draft schema v2 ---------------------------------------------------------
check("schema version bumped", O.SCHEMA_VERSION, 3)
d = O.Draft.from_dict({"draft_id": "x", "kind": O.KIND_SMACKTALK,
                       "mode": O.MODE_SMACKTALK, "schema_version": 1,
                       "caption": "plain body"})
check("v1 draft migrates", d.body_blocks, "")
check("v1 caption kept", d.caption, "plain body")

print(f"OK — {passed} asserts")

# ===== SNAPSMACK EOF =====
