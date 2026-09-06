"""COLD SNAP Qt theme — MIDNIGHT LIME.

Palette and stylesheet lifted from SNAP SLAPPER's Qt shell
(tools/hub/slapper_qt/theme.py) so the companion apps read as one family:
deep near-black panels, one neon-green accent, red for danger. Anything the
whole app should be retuned by lives here.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

# COLD SNAP runs its own slightly-lifted greys (vs the SLAPPER source): the
# near-identical panel/background of the SLAPPER palette washed out at COLD
# SNAP's larger sizes, so cards, chrome and borders are stepped up for real
# separation and the secondary text greys are brighter (Sean, 2026-09-06).
BG         = "#141414"   # app background
PANEL      = "#272727"   # cards / boxes — clearly above the background now
PANEL_HI   = "#1b1b1b"   # card headers
FIELD      = "#303030"   # input chrome / quiet buttons
FIELD_HI   = "#3a3a3a"   # hover chrome
BORDER     = "#3d3d3d"   # visible hairline separators
INK        = "#f2f2f2"   # primary text
BODY       = "#d8d8d8"   # body text — lifted
DIM        = "#9a9a9a"   # secondary labels — was #777, too dim to read
FAINT      = "#6a6a6a"   # tertiary / disabled
ACCENT     = "#39FF14"   # neon green
ACCENT_HI  = "#5bff42"
ACCENT_DIM = "#1E6610"
DANGER     = "#ff3e3e"
WARN       = "#D4872A"
OK         = "#4EC994"
CANVAS     = "#000000"

FONT = "Segoe UI"

# Draft status → (badge text, colour). One vocabulary for every mode.
STATUS_BADGES = {
    "draft":   ("DRAFT", DIM),
    "ready":   ("QUEUED — will send", ACCENT),
    "syncing": ("SENDING…", WARN),
    "synced":  ("ON THE SITE ✓", OK),
    "failed":  ("FAILED — see note", DANGER),
    "queued":  ("WAITING FOR SIBLINGS", WARN),
}


def stylesheet() -> str:
    return f"""
    * {{
        font-family: "{FONT}", "Inter", sans-serif;
        font-size: 15px;
        color: {BODY};
        outline: none;
    }}
    QMainWindow, QWidget {{ background: {BG}; }}

    /* --- Cards ----------------------------------------------------------- */
    QFrame#Card {{
        background: {PANEL};
        border: 1px solid {BORDER};
        border-radius: 8px;
    }}
    QLabel#CardTitle {{
        color: {ACCENT};
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 1px;
        background: transparent;
    }}
    /* Text-ish widgets inside cards sit on the card colour — but NEVER blanket
       QWidget here: that flattened the Primary button's green fill. */
    QFrame#Card QLabel, QFrame#Card QCheckBox, QFrame#Card QRadioButton {{
        background: transparent;
    }}
    QLabel#Hint {{ color: {DIM}; font-size: 14px; background: transparent; }}
    QLabel#FieldLabel {{
        color: {DIM}; font-size: 13px; font-weight: 600;
        letter-spacing: 0.5px; background: transparent;
    }}
    QLabel#BigTitle {{
        color: {INK}; font-size: 20px; font-weight: 700; background: transparent;
    }}

    /* --- Tabs (pill strip, SLAPPER-toolbar family) ------------------------ */
    QTabWidget::pane {{ border: none; }}
    QTabBar {{ background: transparent; }}
    QTabBar::tab {{
        background: {PANEL};
        color: {DIM};
        padding: 9px 26px;
        margin-right: 6px;
        margin-bottom: 6px;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 1.5px;
        border: 1px solid {BORDER};
        border-radius: 7px;
    }}
    QTabBar::tab:hover {{ color: {BODY}; border: 1px solid {FIELD_HI}; }}
    QTabBar::tab:selected {{
        background: {ACCENT_DIM};
        color: {ACCENT};
        border: 1px solid {ACCENT};
    }}

    /* --- Buttons ----------------------------------------------------------*/
    QPushButton {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 6px;
        padding: 7px 14px;
        font-size: 14px;
    }}
    QPushButton:hover    {{ border: 1px solid {ACCENT}; color: {ACCENT}; }}
    QPushButton:disabled {{ color: {FAINT}; border: 1px solid {BORDER}; }}
    QPushButton#Primary {{
        background: {ACCENT};
        color: #000000;
        font-weight: 700;
        border: none;
        padding: 10px 18px;
    }}
    QPushButton#Primary:hover    {{ background: {ACCENT_HI}; color: #000000; }}
    QPushButton#Primary:disabled {{ background: {ACCENT_DIM}; color: {FAINT}; }}
    QPushButton#Danger {{
        background: transparent;
        color: {DANGER};
        border: 1px solid {BORDER};
    }}
    QPushButton#Danger:hover {{ border: 1px solid {DANGER}; color: {DANGER}; }}
    QPushButton#Quiet {{
        background: transparent; border: none; color: {DIM}; padding: 4px 8px;
    }}
    QPushButton#Quiet:hover {{ color: {ACCENT}; }}
    /* Shortcode-bar buttons — compact but still a real target (min 30px tall). */
    QPushButton#ScBtn {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 5px;
        padding: 5px 10px;
        min-height: 20px;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.5px;
    }}
    QPushButton#ScBtn:hover {{ border: 1px solid {ACCENT}; color: {ACCENT}; }}
    /* Colour well — the swatch IS the button; click opens the picker. */
    QPushButton#ColourWell {{
        border: 1px solid {BORDER};
        border-radius: 5px;
        min-width: 40px;
        max-width: 40px;
        min-height: 30px;
    }}
    QPushButton#ColourWell:hover {{ border: 1px solid {ACCENT}; }}

    /* --- Inputs ------------------------------------------------------------*/
    QLineEdit, QPlainTextEdit, QTextEdit {{
        background: #101010;
        color: {INK};
        border: 1px solid {BORDER};
        border-radius: 5px;
        padding: 6px 9px;
        font-size: 15px;
        selection-background-color: {ACCENT_DIM};
    }}
    QLineEdit:focus, QPlainTextEdit:focus, QTextEdit:focus {{
        border: 1px solid {ACCENT};
    }}
    QComboBox {{
        background: {FIELD};
        color: {BODY};
        border: 1px solid {BORDER};
        border-radius: 5px;
        padding: 5px 9px;
        font-size: 14px;
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
    QCheckBox {{ color: {BODY}; font-size: 14px; spacing: 8px; background: transparent; }}
    QCheckBox::indicator {{
        width: 16px; height: 16px; border-radius: 4px;
        border: 1px solid {FAINT}; background: #101010;
    }}
    QCheckBox::indicator:checked {{ background: {ACCENT}; border: 1px solid {ACCENT}; }}
    QRadioButton {{ color: {BODY}; font-size: 14px; spacing: 7px; background: transparent; }}
    QRadioButton::indicator {{
        width: 15px; height: 15px; border-radius: 8px;
        border: 1px solid {FAINT}; background: #101010;
    }}
    QRadioButton::indicator:checked {{ background: {ACCENT}; border: 1px solid {ACCENT}; }}

    /* --- Sliders ------------------------------------------------------------*/
    QSlider::groove:horizontal {{ height: 3px; background: {FIELD}; border-radius: 2px; }}
    QSlider::sub-page:horizontal {{ background: {ACCENT_DIM}; border-radius: 2px; }}
    QSlider::handle:horizontal {{
        background: {ACCENT}; width: 14px; height: 14px;
        margin: -6px 0; border-radius: 7px; border: 2px solid {PANEL};
    }}
    QSlider::handle:horizontal:hover {{ background: {ACCENT_HI}; }}

    /* Disabled controls read as OFF, not merely dim (a QSS-styled label keeps
       its colour on disable unless told otherwise — tell it otherwise). */
    QSlider::handle:horizontal:disabled {{ background: {FAINT}; }}
    QSlider::sub-page:horizontal:disabled {{ background: {FIELD_HI}; }}
    QLabel:disabled, QCheckBox:disabled, QRadioButton:disabled {{ color: {FAINT}; }}
    QLabel#CardTitle:disabled {{ color: {FAINT}; }}
    QLineEdit:disabled, QPlainTextEdit:disabled, QComboBox:disabled {{
        color: {FAINT}; border: 1px solid {FIELD};
    }}
    QCheckBox::indicator:disabled {{ border: 1px solid {FIELD}; background: {BG}; }}

    /* --- Scroll ------------------------------------------------------------*/
    QScrollArea {{ border: none; background: transparent; }}
    QScrollBar:vertical {{ background: {BG}; width: 12px; margin: 0; }}
    QScrollBar::handle:vertical {{
        background: {FIELD_HI}; min-height: 30px; border-radius: 6px; margin: 2px;
    }}
    QScrollBar::handle:vertical:hover {{ background: {ACCENT_DIM}; }}
    QScrollBar::add-line, QScrollBar::sub-line {{ height: 0; background: none; }}
    QScrollBar::add-page, QScrollBar::sub-page {{ background: none; }}
    QScrollBar:horizontal {{ background: {BG}; height: 12px; margin: 0; }}
    QScrollBar::handle:horizontal {{
        background: {FIELD_HI}; min-width: 30px; border-radius: 6px; margin: 2px;
    }}

    QStatusBar {{
        background: {BG}; color: {DIM};
        border-top: 1px solid {BORDER}; font-size: 14px;
    }}
    QToolTip {{
        background: {PANEL_HI}; color: {INK};
        border: 1px solid {BORDER}; padding: 4px 7px;
    }}
    """

# ===== SNAPSMACK EOF =====
