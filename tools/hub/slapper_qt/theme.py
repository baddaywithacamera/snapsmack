"""SNAP SLAPPER Qt theme — one dark visual language, no bright native gaps.

Every colour lives here so the whole editor can be retuned in one place. The
palette is deliberately neutral so photographs read true; a single warm accent
is used sparingly on the parts the user is actively touching (slider handles,
focus, the active section).
"""

# --- Palette -----------------------------------------------------------------
BG        = "#16161a"   # app background — deep neutral, warm-black
PANEL     = "#1e1e23"   # rails and panels
PANEL_HI  = "#26262c"   # accordion headers / raised chrome
FIELD     = "#2a2a31"   # slider troughs, inputs
FIELD_HI  = "#33333b"   # hover
BORDER    = "#34343c"   # hairline separators
INK       = "#ededf2"   # primary text
DIM       = "#9b9ba6"   # secondary labels
FAINT     = "#6a6a74"   # tertiary / disabled
ACCENT    = "#d8a24a"   # warm brass — the one accent, used sparingly
ACCENT_HI = "#e6b667"   # accent hover
CANVAS    = "#0c0c0e"   # image backdrop behind the photo

FONT = "Segoe UI"


def stylesheet() -> str:
    """Return the application-wide Qt stylesheet."""
    return f"""
    * {{
        font-family: "{FONT}", "Inter", sans-serif;
        color: {INK};
        outline: none;
    }}

    QMainWindow, QWidget {{
        background: {BG};
    }}

    /* --- Toolbar -------------------------------------------------------- */
    QToolBar {{
        background: {PANEL};
        border: none;
        border-bottom: 1px solid {BORDER};
        padding: 6px 8px;
        spacing: 4px;
    }}
    QToolBar QToolButton {{
        background: transparent;
        color: {INK};
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
    }}
    QToolBar QToolButton:hover {{
        background: {FIELD_HI};
    }}
    QToolBar QToolButton:pressed {{
        background: {FIELD};
    }}
    QToolBar QToolButton:disabled {{
        color: {FAINT};
    }}
    QToolBar::separator {{
        background: {BORDER};
        width: 1px;
        margin: 4px 6px;
    }}

    /* --- The editing rail ---------------------------------------------- */
    #Rail {{
        background: {PANEL};
        border-left: 1px solid {BORDER};
    }}

    /* --- Accordion sections -------------------------------------------- */
    QPushButton#AccordionHeader {{
        background: {PANEL_HI};
        color: {DIM};
        text-align: left;
        padding: 9px 12px;
        border: none;
        border-top: 1px solid {BORDER};
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 1px;
    }}
    QPushButton#AccordionHeader:hover {{
        color: {INK};
    }}
    QPushButton#AccordionHeader:checked {{
        color: {INK};
    }}

    /* --- Control rows --------------------------------------------------- */
    QLabel#ControlName {{
        color: {DIM};
        font-size: 11px;
    }}
    QLabel#ControlValue {{
        color: {INK};
        font-size: 11px;
        font-weight: 600;
    }}

    /* --- Sliders -------------------------------------------------------- */
    QSlider::groove:horizontal {{
        height: 3px;
        background: {FIELD};
        border-radius: 2px;
    }}
    QSlider::sub-page:horizontal {{
        background: {ACCENT};
        border-radius: 2px;
    }}
    QSlider::handle:horizontal {{
        background: {INK};
        width: 13px;
        height: 13px;
        margin: -6px 0;
        border-radius: 7px;
        border: 2px solid {PANEL};
    }}
    QSlider::handle:horizontal:hover {{
        background: {ACCENT_HI};
    }}

    /* --- Checkboxes ----------------------------------------------------- */
    QCheckBox {{
        color: {INK};
        font-size: 11px;
        spacing: 8px;
        padding: 6px 12px;
    }}
    QCheckBox::indicator {{
        width: 15px;
        height: 15px;
        border-radius: 4px;
        border: 1px solid {BORDER};
        background: {FIELD};
    }}
    QCheckBox::indicator:checked {{
        background: {ACCENT};
        border: 1px solid {ACCENT};
    }}

    /* --- Scrollbars (never blinding white) ----------------------------- */
    QScrollArea {{ border: none; background: {PANEL}; }}
    QScrollBar:vertical {{
        background: {PANEL};
        width: 12px;
        margin: 0;
    }}
    QScrollBar::handle:vertical {{
        background: {FIELD_HI};
        min-height: 30px;
        border-radius: 6px;
        margin: 2px;
    }}
    QScrollBar::handle:vertical:hover {{ background: {BORDER}; }}
    QScrollBar::add-line, QScrollBar::sub-line {{ height: 0; background: none; }}
    QScrollBar::add-page, QScrollBar::sub-page {{ background: none; }}

    /* --- Image canvas --------------------------------------------------- */
    #ImageView {{
        background: {CANVAS};
        border: none;
    }}

    /* --- Status line ---------------------------------------------------- */
    QStatusBar {{
        background: {PANEL};
        color: {DIM};
        border-top: 1px solid {BORDER};
        font-size: 11px;
    }}

    QToolTip {{
        background: {PANEL_HI};
        color: {INK};
        border: 1px solid {BORDER};
        padding: 4px 7px;
    }}
    """

# ===== SNAPSMACK EOF =====
