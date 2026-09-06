"""ShortcodeBar emits the SAME markup the web toolbar emits.

The bar is a port of assets/js/shortcode-toolbar.js; these asserts pin the
emitted strings so the two composers never drift apart. Runs headless
(offscreen Qt platform) — no display needed.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from PySide6.QtWidgets import QApplication, QPlainTextEdit  # noqa: E402

app = QApplication.instance() or QApplication([])

from coldsnap_qt.shortcode_bar import ShortcodeBar  # noqa: E402
from coldsnap_qt.widgets import SliderRow  # noqa: E402

passed = 0


def check(name, got, want):
    global passed
    assert got == want, f"{name}:\n  got:  {got!r}\n  want: {want!r}"
    passed += 1


def fresh(text="", select=False):
    ed = QPlainTextEdit()
    ed.setPlainText(text)
    if select:
        ed.selectAll()
    bar = ShortcodeBar(ed)
    return ed, bar


# --- wrap: selection is wrapped (web insertAtCursor with selection) ----------
ed, bar = fresh("hello", select=True)
bar._wrap("<strong>", "</strong>")
check("bold wraps selection", ed.toPlainText(), "<strong>hello</strong>")

# --- wrap: no selection → cursor lands between the tags ----------------------
ed, bar = fresh()
bar._wrap("<em>", "</em>")
check("italic empty insert", ed.toPlainText(), "<em></em>")
check("cursor between tags", ed.textCursor().position(), len("<em>"))

# --- multi-line selection: Qt's U+2029 becomes real newlines ----------------
ed, bar = fresh("one\ntwo", select=True)
bar._wrap("<blockquote>", "</blockquote>")
check("multiline wrap", ed.toPlainText(), "<blockquote>one\ntwo</blockquote>")

# --- list: selected lines become <li> items (web insertList) -----------------
ed, bar = fresh("alpha\nbeta\ngamma", select=True)
bar._list("ul")
check("ul from lines", ed.toPlainText(),
      "\n<ul>\n  <li>alpha</li>\n  <li>beta</li>\n  <li>gamma</li>\n</ul>\n")

# --- list: no selection → the empty two-item scaffold ------------------------
ed, bar = fresh()
bar._list("ol")
check("ol scaffold", ed.toPlainText(),
      "\n<ol>\n  <li></li>\n  <li></li>\n</ol>\n")

# --- columns: byte-identical to the web insertColumns ------------------------
ed, bar = fresh()
bar._columns(2)
check("columns 2", ed.toPlainText(),
      "[columns=2]\nFirst column content.\n\n[col]\n\nColumn 2 content.\n[/columns]")

ed, bar = fresh()
bar._columns(3)
check("columns 3", ed.toPlainText(),
      "[columns=3]\nFirst column content.\n\n[col]\n\nColumn 2 content.\n"
      "\n[col]\n\nColumn 3 content.\n[/columns]")

# --- dropcap wrap ------------------------------------------------------------
ed, bar = fresh("W", select=True)
bar._wrap("[dropcap]", "[/dropcap]")
check("dropcap", ed.toPlainText(), "[dropcap]W[/dropcap]")

# --- add_button: a mode's own button inserts through the same door -----------
ed, bar = fresh()
bar.add_button("MOSAIC", "tip", lambda: bar.insert("[mosaic]"))
bar.insert("[mosaic]")
check("mosaic marker", ed.toPlainText(), "[mosaic]")

# --- SliderRow: the typed value drives the slider and clamps -----------------
row = SliderRow("Zoom %", 100, 300, 100)
row.value_edit.setText("250")
row._typed()
check("typed value lands", row.value(), 250)
row.value_edit.setText("")
row._typed()
check("blank restores current", row.value_edit.text(), "250")
row.slider.setValue(120)
check("slider updates the box", row.value_edit.text(), "120")

print(f"OK — {passed} asserts")

# ===== SNAPSMACK EOF =====
