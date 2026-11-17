"""MIDNIGHT LIME stylesheet for DRIVE BY, matching SNAP HQ / COLD SNAP."""

BG        = "#0c0f0c"
PANEL     = "#131713"
FIELD     = "#171c17"
BORDER    = "#273026"
ACCENT    = "#39e518"
ACCENT_HI = "#52f732"
BODY      = "#e8efe6"
MUTED     = "#90a08d"
DIM       = "#657463"
WARN      = "#e6a82e"
DANGER    = "#e05252"
FONT      = "Segoe UI"


def stylesheet() -> str:
    return f"""
    QMainWindow, QWidget {{
        background: {BG}; color: {BODY}; font-family: "{FONT}"; font-size: 13px;
    }}
    QFrame#HeaderBar, QFrame#SummaryBar {{
        background: {PANEL}; border-bottom: 1px solid {BORDER};
    }}
    QLabel#AppTitle {{
        color: {ACCENT}; font-size: 15px; font-weight: 700; letter-spacing: 0.8px;
    }}
    QLabel#ReadOnlyBadge {{
        background: #182b15; color: {ACCENT}; border: 1px solid {ACCENT};
        border-radius: 4px; padding: 3px 9px; font-size: 11px; font-weight: 700;
    }}
    QLabel#DeleteBadge {{
        background: #2f1515; color: {DANGER}; border: 1px solid {DANGER};
        border-radius: 4px; padding: 3px 9px; font-size: 11px; font-weight: 700;
    }}
    QLabel#SummaryStat {{
        color: {BODY}; font-size: 12px; font-weight: 600;
    }}
    QLabel#Muted {{
        color: {MUTED}; font-size: 12px;
    }}
    QPushButton, QComboBox {{
        background: {FIELD}; color: {BODY}; border: 1px solid {BORDER};
        border-radius: 5px; padding: 5px 11px; font-size: 12px;
    }}
    QPushButton:hover {{ border-color: {ACCENT}; }}
    QPushButton#Primary {{
        background: {ACCENT}; color: #091208; border: none; font-weight: 700;
    }}
    QPushButton#Primary:hover {{ background: {ACCENT_HI}; }}
    QTableWidget {{
        background: {PANEL}; color: {BODY}; gridline-color: {BORDER};
        border: 1px solid {BORDER}; selection-background-color: #1f3a1c;
    }}
    QHeaderView::section {{
        background: {FIELD}; color: {MUTED}; border: none;
        border-bottom: 1px solid {BORDER}; padding: 6px 8px;
        font-size: 11px; font-weight: 700; text-transform: uppercase;
    }}
    """


# ===== SNAPSMACK EOF =====
