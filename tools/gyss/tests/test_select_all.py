"""
GYSS — SELECT ALL / SELECT NONE regression tests.

Sean, 2026-09-22: "GYSS needs select all". The Images page already had
CHECK ALL RESULTS (tick boxes); the four drag-and-drop photo lists that feed a
SELECTED action had nothing but Ctrl+A. These pin:

  * every list that feeds a SELECTED action has a working pair of buttons
  * the two paged organizer lists say ON PAGE, because they hold one 180-photo
    page and selecting what is not on screen would be a lie
  * the Images page's own tick-box buttons are untouched
  * no existing action row got wider (the first attempt crowded Organize past
    the window and put a horizontal scrollbar on it)

Runs headless: QT_QPA_PLATFORM=offscreen.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import os
import sys
from pathlib import Path

import pytest

TOOL = Path(__file__).resolve().parents[1]
for p in (str(TOOL), str(TOOL.parent / "_shared")):
    if p not in sys.path:
        sys.path.insert(0, p)

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

pytest.importorskip("PySide6")
from PySide6.QtCore import Qt                                   # noqa: E402
from PySide6.QtWidgets import QApplication, QListWidgetItem, QPushButton   # noqa: E402

import gyss_qt                                                  # noqa: E402


@pytest.fixture(scope="module")
def app():
    return QApplication.instance() or QApplication([])


@pytest.fixture
def win(app):
    w = gyss_qt.Window()
    yield w
    w.close()


def _buttons(w):
    out = {}
    for b in w.findChildren(QPushButton):
        out.setdefault(b.text(), []).append(b)
    return out


def _fill(lw, n=6):
    lw.clear()
    for i in range(n):
        it = QListWidgetItem(f"p{i}")
        it.setData(Qt.UserRole, {"id": i})
        lw.addItem(it)


def _lists(w):
    return (w.photo_list, w.gram, w.organizer_members, w.organizer_tray)


def test_every_selection_list_has_a_button_pair(win):
    b = _buttons(win)
    # Photos + Grid are not paged; the two organizer lists are.
    assert len(b.get("SELECT ALL", [])) == 2
    assert len(b.get("SELECT ALL ON PAGE", [])) == 2
    assert len(b.get("SELECT NONE", [])) == 4


def test_select_all_then_none_covers_all_four_lists(win):
    b = _buttons(win)
    for lw in _lists(win):
        _fill(lw)
    for btn in b["SELECT ALL"] + b["SELECT ALL ON PAGE"]:
        btn.click()
    assert [len(lw.selectedItems()) for lw in _lists(win)] == [6, 6, 6, 6]
    for btn in b["SELECT NONE"]:
        btn.click()
    assert [len(lw.selectedItems()) for lw in _lists(win)] == [0, 0, 0, 0]


def test_select_all_updates_the_enrich_button_count(win):
    """Scoped to the Photos page — "SELECT ALL" is also the Grid page's button."""
    _fill(win.photo_list, 6)
    photos_page = win.pages.widget(1)
    btn = [b for b in photos_page.findChildren(QPushButton) if b.text() == "SELECT ALL"]
    assert len(btn) == 1
    btn[0].click()
    assert len(win.photo_list.selectedItems()) == 6
    assert win.sort_enrich.text() == "ENRICH 6 SELECTED PHOTOS"


def test_images_page_tick_box_buttons_are_untouched(win):
    b = _buttons(win)
    assert len(b.get("CHECK ALL RESULTS", [])) == 1
    assert len(b.get("CLEAR CHECKS", [])) == 1
    for i in range(4):
        it = QListWidgetItem(f"a{i}")
        it.setFlags(it.flags() | Qt.ItemIsUserCheckable)
        it.setCheckState(Qt.Unchecked)
        it.setData(Qt.UserRole, {"id": i})
        win.audit.addItem(it)
    b["CHECK ALL RESULTS"][0].click()
    assert all(win.audit.item(i).checkState() == Qt.Checked for i in range(win.audit.count()))
    b["CLEAR CHECKS"][0].click()
    assert all(win.audit.item(i).checkState() == Qt.Unchecked for i in range(win.audit.count()))


def test_no_action_row_got_wider(win, app):
    """The buttons live on the label line above each list, not in the action
    rows. Those rows were already near the window edge; Organize overflowed by
    300px when the buttons were put in them."""
    win.resize(1920, 1040)
    win.show()
    app.processEvents()
    # Baselines measured on 0.7.33, before the buttons existed.
    for page, baseline in ((1, 1010), (2, 1498), (3, 1268)):
        win.show_page(page)
        app.processEvents()
        need = win.pages.currentWidget().widget().minimumSizeHint().width()
        assert need <= baseline, f"page {page} needs {need}px, was {baseline}px"


def test_paged_lists_say_on_page(win):
    """Honest labels: those widgets hold one page, so the button cannot claim
    to select the whole container."""
    assert win.organizer_page_size == 180
    src = (TOOL / "gyss_qt.py").read_text(encoding="utf-8")
    assert "SELECT ALL ON PAGE" in src
    help_text = " ".join(c for c in gyss_qt.Window.show_help.__code__.co_consts
                         if isinstance(c, str))
    assert "SELECT ALL ON PAGE" in help_text

# ===== SNAPSMACK EOF =====
