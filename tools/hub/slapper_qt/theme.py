"""SNAP SLAPPER Qt theme — MIDNIGHT LIME.

Colours are taken directly from the SnapSmack admin Midnight Lime theme
(assets/adminthemes/midnight-lime/) so the desktop editor is visually
consistent with the admin and the other companion apps: deep near-black
panels with a single neon-green accent and a red danger colour.

Everything lives here so the whole editor can be retuned in one place.
Note: Qt Style Sheets do not support box-shadow, so the admin's green glow is
expressed here through colour shifts rather than shadow.
"""

# --- Midnight Lime palette (from admin-theme-colours-midnight-lime.css) -------
BG         = "#141414"   # app background (admin sidebar/body)
PANEL      = "#1c1c1c"   # rails and boxes (.box)
PANEL_HI   = "#111111"   # section headers (.box-header)
FIELD      = "#1a1a1a"   # slider troughs / range track
FIELD_HI   = "#2a2a2a"   # hover / raised chrome (also the border grey)
BORDER     = "#2a2a2a"   # hairline separators
INK        = "#eeeeee"   # primary text
BODY       = "#cccccc"   # body text
DIM        = "#777777"   # secondary labels
FAINT      = "#555555"   # tertiary / disabled
ACCENT     = "#39FF14"   # neon green — the Midnight Lime accent
ACCENT_HI  = "#5bff42"   # brighter green for hover
ACCENT_DIM = "#1E6610"   # muted green for slider fill (from the theme)
DANGER     = "#ff3e3e"   # destructive / error
CANVAS     = "#000000"   # image backdrop (admin .preview-frame)

FONT = "Segoe UI"


def stylesheet() -> str:
    """Return the application-wide Qt stylesheet."""
    return f"""
    * {{
        font-family: "{FONT}", "Inter", sans-serif;
        font-size: 13px;
        color: {BODY};
        outline: none;
    }}

    QMainWindow, QWidget {{
        background: {BG};
    }}

    /* --- Toolbar -------------------------------------------------------- */
    QToolBar {{
        background: {BG};
        border: none;
        border-bottom: 1px solid {BORDER};
        padding: 6px 8px;
        spacing: 4px;
    }}
    QToolBar QToolButton {{
        background: transparent;
        color: {DIM};
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
    }}
    QToolBar QToolButton:hover {{
        background: {FIELD_HI};
        color: {ACCENT};
    }}
    QToolBar QToolButton:pressed {{
        background: {FIELD};
        color: {ACCENT};
    }}
    QToolBar QToolButton:disabled {{
        color: {FAINT};
        background: transparent;
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
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 1px;
    }}
    QPushButton#AccordionHeader:hover {{
        color: {ACCENT};
    }}
    QPushButton#AccordionHeader:checked {{
        color: {ACCENT};
    }}

    /* --- Mini toggles (histogram LUMA/RGB) ----------------------------- */
    QPushButton#MiniToggle {{
        background: {FIELD};
        color: {DIM};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 1px 8px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1px;
    }}
    QPushButton#MiniToggle:hover {{
        color: {BODY};
    }}
    QPushButton#MiniToggle:checked {{
        background: {ACCENT_DIM};
        color: {ACCENT};
        border: 1px solid {ACCENT};
    }}

    /* Colour pickers keep the application chrome; only the small chip changes. */
    QPushButton#SwatchBtn {{
        background: {CANVAS};
        color: {ACCENT};
        border: 1px solid {ACCENT};
        border-radius: 4px;
        padding: 5px 8px;
        text-align: left;
    }}
    QPushButton#SwatchBtn:hover {{
        background: {ACCENT};
        color: {CANVAS};
        border: 1px solid {ACCENT};
    }}
    QPushButton#ToneSwatch {{
        background: {FIELD};
        border: 1px solid {BORDER};
        border-radius: 3px;
        padding: 2px;
    }}
    QPushButton#ToneSwatch:hover {{
        background: {FIELD};
        border: 1px solid {ACCENT};
    }}

    /* --- Layers panel --------------------------------------------------- */
    QPushButton#LayerAddBtn {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 5px;
        padding: 5px 6px;
        font-size: 12px;
    }}
    QPushButton#LayerAddBtn:hover {{
        border: 1px solid {ACCENT};
        color: {ACCENT};
    }}
    QPushButton#LayerAddBtn:checked {{
        background: {ACCENT_DIM};
        border: 1px solid {ACCENT};
        color: {ACCENT};
    }}
    QWidget#LayerRow {{
        background: transparent;
        border-radius: 5px;
    }}
    QWidget#LayerRowActive {{
        background: {ACCENT_DIM};
        border-radius: 5px;
    }}
    QPushButton#LayerName {{
        background: transparent;
        border: none;
        text-align: left;
        padding: 3px 4px;
        color: {BODY};
        font-size: 12px;
    }}
    QWidget#LayerRowActive QPushButton#LayerName {{
        color: {ACCENT};
        font-weight: 600;
    }}
    QPushButton#LayerName:hover {{
        color: {ACCENT};
    }}
    QPushButton#LayerOrderBtn {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 3px;
        font-size: 12px;
    }}
    QPushButton#LayerOrderBtn:hover {{ border: 1px solid {ACCENT}; color: {ACCENT}; }}
    QPushButton#LayerDeleteBtn {{
        background: transparent;
        color: {DANGER};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 4px 10px;
        font-size: 12px;
    }}
    QPushButton#LayerDeleteBtn:hover {{ border: 1px solid {DANGER}; }}
    QPushButton#TeachSettingsToggle {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 4px 8px;
    }}
    QPushButton#TeachSettingsToggle:hover {{
        border: 1px solid {DANGER};
        color: {DANGER};
    }}
    QPushButton#TeachSettingsToggle:checked {{
        background: {DANGER};
        border: 1px solid {DANGER};
        color: {CANVAS};
    }}
    QLabel#TargetLabel {{
        color: {DIM};
        font-size: 12px;
        font-style: italic;
        padding: 3px 12px 6px 12px;
    }}
    QLineEdit {{
        background: #050505;
        color: {INK};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 5px 8px;
        font-size: 12px;
        selection-background-color: {ACCENT_DIM};
    }}
    QLineEdit:focus {{
        border: 1px solid {ACCENT};
    }}
    QComboBox {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 3px 8px;
        font-size: 12px;
    }}
    QComboBox:hover {{ border: 1px solid {ACCENT}; }}
    QComboBox QAbstractItemView {{
        background: {PANEL_HI};
        color: {BODY};
        border: 1px solid {BORDER};
        selection-background-color: {ACCENT_DIM};
        selection-color: {ACCENT};
        outline: none;
    }}

    /* --- Control rows --------------------------------------------------- */
    QLabel#ControlName {{
        color: {DIM};
        font-size: 12px;
    }}
    QLabel#ControlValue {{
        color: {INK};
        font-size: 12px;
        font-weight: 600;
    }}

    /* --- Sliders (green thumb on a dark track, admin range style) ------- */
    QSlider::groove:horizontal {{
        height: 3px;
        background: {FIELD};
        border-radius: 2px;
    }}
    QSlider::sub-page:horizontal {{
        background: {ACCENT_DIM};
        border-radius: 2px;
    }}
    QSlider::handle:horizontal {{
        background: {ACCENT};
        width: 13px;
        height: 13px;
        margin: -6px 0;
        border-radius: 7px;
        border: 2px solid {PANEL};
    }}
    QSlider::handle:horizontal:hover {{
        background: {ACCENT_HI};
    }}
    /* Geometry needs a precision point, not a fat adjustment knob. */
    QSlider#PrecisionSlider::groove:horizontal {{
        height: 2px;
        background: {FIELD_HI};
    }}
    QSlider#PrecisionSlider::handle:horizontal {{
        background: {ACCENT};
        width: 9px;
        height: 9px;
        margin: -4px 0;
        border-radius: 5px;
        border: 1px solid {CANVAS};
    }}
    QLineEdit#ControlValue {{
        background: {CANVAS};
        color: {BODY};
        border: 1px solid transparent;
        border-radius: 3px;
        padding: 1px 3px;
    }}
    QLineEdit#ControlValue:hover,
    QLineEdit#ControlValue:focus {{
        color: {ACCENT};
        border: 1px solid {ACCENT_DIM};
    }}

    /* --- Checkboxes (green when checked, admin style) ------------------- */
    QCheckBox {{
        color: {BODY};
        font-size: 12px;
        spacing: 8px;
        padding: 6px 12px;
    }}
    QCheckBox::indicator {{
        width: 15px;
        height: 15px;
        border-radius: 4px;
        border: 1px solid {FAINT};
        background: #050505;
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
    QScrollBar::handle:vertical:hover {{ background: {ACCENT_DIM}; }}
    QScrollBar::add-line, QScrollBar::sub-line {{ height: 0; background: none; }}
    QScrollBar::add-page, QScrollBar::sub-page {{ background: none; }}

    /* --- Library grid --------------------------------------------------- */
    QListWidget {{
        background: {CANVAS};
        border: none;
        color: {DIM};
        font-size: 12px;
        padding: 6px;
    }}
    QListWidget::item {{
        color: {DIM};
        border: 1px solid transparent;
        border-radius: 6px;
        padding: 4px;
    }}
    QListWidget::item:hover {{
        border: 1px solid {BORDER};
    }}
    QListWidget::item:selected {{
        background: {ACCENT_DIM};
        border: 1px solid {ACCENT};
        color: {ACCENT};
    }}

    /* --- Image canvas --------------------------------------------------- */
    #ImageView {{
        background: {CANVAS};
        border: none;
    }}

    /* --- Status line ---------------------------------------------------- */
    QStatusBar {{
        background: {BG};
        color: {DIM};
        border-top: 1px solid {BORDER};
        font-size: 12px;
    }}

    QToolTip {{
        background: {PANEL_HI};
        color: {INK};
        border: 1px solid {BORDER};
        padding: 4px 7px;
    }}

    /* --- Mask type chooser + paint canvas ------------------------------ */
    QPushButton#MaskTypeBtn {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 5px 4px;
        font-size: 12px;
    }}
    QPushButton#MaskTypeBtn:hover {{ border: 1px solid {ACCENT}; }}
    QPushButton#MaskTypeBtn:checked {{
        background: {ACCENT_DIM};
        border: 1px solid {ACCENT};
        color: {ACCENT};
    }}
    QWidget#MaskBrushCanvas {{
        border: 1px solid {BORDER};
        border-radius: 3px;
    }}

    /* --- Filmstrip ------------------------------------------------------ */
    QListWidget#Filmstrip {{
        background: {PANEL_HI};
        border: none;
        border-top: 1px solid {BORDER};
        padding: 4px 2px;
        outline: none;
    }}
    QListWidget#Filmstrip::item {{
        border: 1px solid transparent;
        border-radius: 3px;
        margin: 2px;
        padding: 2px;
    }}
    QListWidget#Filmstrip::item:hover {{
        border: 1px solid {FIELD_HI};
    }}
    QListWidget#Filmstrip::item:selected {{
        border: 1px solid {ACCENT};
        background: {ACCENT_DIM};
    }}
    """

# ===== SNAPSMACK EOF =====
