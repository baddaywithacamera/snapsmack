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
check("composed mosaic keeps photos + layout",
      S({"type": "mosaic", "order": [2, 1, 3], "layout": "one-left"}),
      "[mosaic=2,1,3 layout=one-left]")
check("composed mosaic parses back",
      biggie.parse_body("[mosaic=2,1,3 layout=one-left]"),
      [{"type": "mosaic", "order": [2, 1, 3], "layout": "one-left"}])
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
# --- BIGGIE canvas (WYSIWYG): the document round-trips byte-for-byte ------
# Sean 2026-09-09: one surface, Enter = paragraph, the mosaic is the photos.
from PySide6.QtGui import QKeyEvent, QImage, QColor  # noqa: E402
from PySide6.QtCore import Qt, QEvent  # noqa: E402
from coldsnap_qt.canvas import BiggieCanvas  # noqa: E402

full = body + "\n\n[mosaic=2,1,3 layout=one-left]\n\n<ol>\n  <li>x</li>\n</ol>"
cv = BiggieCanvas(allow_mosaic=True)
cv.resize(900, 700)
cv.from_blocks(biggie.parse_body(full))
check("canvas round trip is byte-identical", biggie.serialize_blocks(cv.to_blocks()), full)
check("canvas shows no shortcode text for a mosaic",
      "[mosaic" in cv.toPlainText(), False)

cv2 = BiggieCanvas()
cv2.resize(800, 600)
cv2.show()
cv2.set_kind("h2")
cv2.insertPlainText("Head")
app.sendEvent(cv2, QKeyEvent(QEvent.KeyPress, Qt.Key_Return, Qt.NoModifier))
cv2.insertPlainText("Body one")
app.sendEvent(cv2, QKeyEvent(QEvent.KeyPress, Qt.Key_Return, Qt.NoModifier))
cv2.insertPlainText("Body two")
cv2.insert_mosaic([1, 2], "columns")
cv2.insertPlainText("After")
check("Enter after a heading makes a plain paragraph; mosaic is its own block",
      biggie.serialize_blocks(cv2.to_blocks()),
      "<h2>Head</h2>\n\nBody one\n\nBody two\n\n[mosaic=1,2 layout=columns]\n\nAfter")
cv2.undo()
cv2.undo()
check("Ctrl+Z removes the mosaic", biggie.serialize_blocks(cv2.to_blocks()),
      "<h2>Head</h2>\n\nBody one\n\nBody two")

_d = tempfile.mkdtemp()
_paths = []
for _i, _col in enumerate(("#ff0000", "#00ff00", "#0000ff")):
    _img = QImage(400, 300, QImage.Format_RGB32)
    _img.fill(QColor(_col))
    _p = os.path.join(_d, f"p{_i}.png")
    _img.save(_p)
    _paths.append(_p)
cv3 = BiggieCanvas()
cv3.resize(900, 700)
cv3.show()
cv3.set_bucket(_paths)
cv3.insert_mosaic([1, 2, 3], "one-left")
shot = cv3.grab().toImage()
# the hero tile (one-left) is the red photo: sample well inside it
check("mosaic paints the real photos", shot.pixelColor(200, 200).red() > 200)
check("second photo painted in its tile", shot.pixelColor(720, 120).green() > 200)

check("v1 dropcap block migrates to paragraph option",
      biggie.blocks_from_json('[{"type":"dropcap","text":"W"}]'),
      [{"type": "para", "text": "W", "dropcap": True}])

# --- COLD ONE / COLD STACK: basic only (Sean 2026-09-10) ---------------------
basic = BodyEditor(allow_mosaic=False, rich=False)
check("basic box has no BIGGIE pill", basic.biggie_btn.isHidden(), True)
basic._set_biggie(True)
check("basic box cannot switch to BIGGIE", basic.is_biggie(), False)
basic.set_state("", biggie.blocks_to_json([{"type": "heading", "level": 2, "text": "Old"},
                                          {"type": "para", "text": "draft"}]))
check("old BIGGIE draft in a basic box keeps its words as text",
      basic.toPlainText(), "<h2>Old</h2>\n\ndraft")
check("basic box never stores blocks", basic.blocks_json(), "")

# --- draft schema v2 ---------------------------------------------------------
check("schema version bumped", O.SCHEMA_VERSION, 3)
d = O.Draft.from_dict({"draft_id": "x", "kind": O.KIND_SMACKTALK,
                       "mode": O.MODE_SMACKTALK, "schema_version": 1,
                       "caption": "plain body"})
check("v1 draft migrates", d.body_blocks, "")
check("v1 caption kept", d.caption, "plain body")

print(f"OK — {passed} asserts")

# ===== SNAPSMACK EOF =====
