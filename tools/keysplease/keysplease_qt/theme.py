"""MIDNIGHT LIME stylesheet for KEYS PLEASE."""

BG = "#0e100e"
SURFACE = "#141714"
SURFACE_ALT = "#191d19"
BORDER = "#2a302a"
TEXT = "#e6ece6"
DIM = "#96a296"
LIME = "#76ff03"
DANGER = "#ff4f4f"


def stylesheet() -> str:
    return f"""
    QMainWindow, QWidget {{
        background-color: {BG};
        color: {TEXT};
        font-size: 13px;
    }}
    QFrame#HeaderBar, QFrame#SummaryBar {{
        background-color: {SURFACE};
        border-bottom: 1px solid {BORDER};
    }}
    QLabel#AppTitle {{
        color: {LIME};
        font-size: 17px;
        font-weight: 700;
        letter-spacing: 1px;
    }}
    QLabel#Muted {{
        color: {DIM};
    }}
    QTableWidget, QListWidget {{
        background-color: {SURFACE};
        border: 1px solid {BORDER};
        gridline-color: {BORDER};
    }}
    QHeaderView::section {{
        background-color: {SURFACE_ALT};
        color: {DIM};
        border: none;
        border-bottom: 1px solid {BORDER};
        padding: 6px 8px;
        font-weight: 600;
    }}
    QPushButton {{
        background-color: {SURFACE_ALT};
        color: {TEXT};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 6px 12px;
    }}
    QPushButton:hover {{
        border-color: {LIME};
    }}
    QPushButton#Primary {{
        background-color: {LIME};
        color: #0b0d0b;
        font-weight: 700;
        border-color: {LIME};
    }}
    QPushButton#Danger {{
        background-color: #2a1111;
        color: {DANGER};
        border-color: {DANGER};
        font-weight: 700;
    }}
    QLineEdit, QComboBox {{
        background-color: {SURFACE_ALT};
        color: {TEXT};
        border: 1px solid {BORDER};
        border-radius: 4px;
        padding: 5px 8px;
    }}
    """


# ===== SNAPSMACK EOF =====
