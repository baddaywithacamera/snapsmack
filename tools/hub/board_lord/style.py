"""BOARD LORD styling — layered on top of the shared Midnight Lime theme.

Only the console-specific chrome lives here (rail nav, flag badges, thread rows,
the one loud primary button, compose fields). Everything else is inherited from
``slapper_qt.theme`` so BOARD LORD and SNAP SLAPPER read as one family.
"""

from slapper_qt import theme as t

# Flag → (label, background, text) badge colours. Support reads warm/urgent,
# question cool, chat neutral, brag playful.
FLAG_BADGE = {
    "support":  ("SUPPORT",  "#5a3410", "#ff9f45"),
    "question": ("QUESTION", "#10314f", "#4aa3ff"),
    "chat":     ("CHAT",     "#262626", "#a8a8a8"),
    "brag":     ("BRAG",     "#3a1f52", "#c07bff"),
}


def extra_stylesheet() -> str:
    return f"""
    /* --- Left rail nav (filters + boards) ------------------------------- */
    QLabel#RailSection {{
        color: {t.DIM};
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        padding: 14px 12px 4px 12px;
    }}
    QPushButton#NavItem {{
        background: transparent;
        color: {t.BODY};
        text-align: left;
        padding: 7px 12px;
        border: none;
        border-radius: 5px;
        font-size: 12px;
    }}
    QPushButton#NavItem:hover {{
        background: {t.FIELD_HI};
        color: {t.ACCENT};
    }}
    QPushButton#NavItem:checked {{
        background: {t.ACCENT_DIM};
        color: {t.ACCENT};
        font-weight: 600;
    }}

    /* --- Buttons -------------------------------------------------------- */
    QPushButton#Primary {{
        background: {t.ACCENT_DIM};
        color: {t.ACCENT};
        border: 1px solid {t.ACCENT};
        border-radius: 5px;
        padding: 7px 16px;
        font-size: 12px;
        font-weight: 600;
    }}
    QPushButton#Primary:hover {{ background: #24801a; color: {t.ACCENT_HI}; }}
    QPushButton#Primary:disabled {{ background: {t.FIELD}; color: {t.FAINT}; border: 1px solid {t.BORDER}; }}

    QPushButton#Ghost {{
        background: {t.FIELD};
        color: {t.BODY};
        border: 1px solid {t.BORDER};
        border-radius: 5px;
        padding: 6px 12px;
        font-size: 12px;
    }}
    QPushButton#Ghost:hover {{ border: 1px solid {t.ACCENT}; color: {t.ACCENT}; }}
    QPushButton#Ghost:checked {{ background: {t.ACCENT_DIM}; border: 1px solid {t.ACCENT}; color: {t.ACCENT}; }}

    QPushButton#Danger {{
        background: transparent;
        color: {t.DANGER};
        border: 1px solid {t.BORDER};
        border-radius: 5px;
        padding: 6px 12px;
        font-size: 12px;
    }}
    QPushButton#Danger:hover {{ border: 1px solid {t.DANGER}; }}

    /* --- Thread list ---------------------------------------------------- */
    QWidget#ThreadPane {{ background: {t.BG}; }}
    QScrollArea#ThreadScroll {{ background: {t.BG}; border: none; }}
    QWidget#ThreadRow, QWidget#ThreadRowSel {{
        border-bottom: 1px solid {t.BORDER};
    }}
    QWidget#ThreadRowSel {{
        background: {t.PANEL};
        border-left: 2px solid {t.ACCENT};
    }}
    QWidget#ThreadRow:hover {{ background: {t.PANEL_HI}; }}
    QLabel#ThreadTitle {{ color: {t.INK}; font-size: 13px; font-weight: 600; }}
    QLabel#ThreadTitleUnread {{ color: {t.ACCENT}; font-size: 13px; font-weight: 700; }}
    QLabel#ThreadMeta {{ color: {t.DIM}; font-size: 10px; }}
    QLabel#UnreadDot {{ color: {t.ACCENT}; font-size: 14px; }}

    /* --- Flag + status badges (per-flag colours set on the widget) ------ */
    QLabel[role="badge"] {{
        border-radius: 4px;
        padding: 1px 7px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 1px;
    }}
    QLabel#BadgePinned  {{ background: {t.ACCENT_DIM}; color: {t.ACCENT}; border-radius: 4px; padding: 1px 7px; font-size: 9px; font-weight: 700; }}
    QLabel#BadgeLocked  {{ background: #3a2a10; color: #d9a441; border-radius: 4px; padding: 1px 7px; font-size: 9px; font-weight: 700; }}
    QLabel#BadgeSolved  {{ background: #10331a; color: #46c46a; border-radius: 4px; padding: 1px 7px; font-size: 9px; font-weight: 700; }}

    /* --- Detail panel --------------------------------------------------- */
    QWidget#Detail {{ background: {t.PANEL}; border-left: 1px solid {t.BORDER}; }}
    QScrollArea#DetailScroll {{ background: {t.PANEL}; border: none; }}
    QLabel#DetailTitle {{ color: {t.INK}; font-size: 17px; font-weight: 700; }}
    QLabel#DetailMeta  {{ color: {t.DIM};  font-size: 11px; }}
    QLabel#PostBody    {{ color: {t.BODY}; font-size: 13px; }}
    QWidget#ReplyCard  {{ background: {t.PANEL_HI}; border: 1px solid {t.BORDER}; border-radius: 6px; }}
    QLabel#ReplyAuthor {{ color: {t.ACCENT}; font-size: 11px; font-weight: 600; }}
    QLabel#ReplyMeta   {{ color: {t.FAINT}; font-size: 10px; }}
    QLabel#ReplyBody   {{ color: {t.BODY}; font-size: 12px; }}
    QLabel#EmptyHint   {{ color: {t.FAINT}; font-size: 13px; }}

    QPlainTextEdit {{
        background: #050505;
        color: {t.INK};
        border: 1px solid {t.BORDER};
        border-radius: 5px;
        padding: 6px 8px;
        font-size: 12px;
        selection-background-color: {t.ACCENT_DIM};
    }}
    QPlainTextEdit:focus {{ border: 1px solid {t.ACCENT}; }}

    /* --- Tables (roles dialog) ----------------------------------------- */
    QTableWidget {{
        background: {t.CANVAS};
        color: {t.BODY};
        gridline-color: {t.BORDER};
        border: 1px solid {t.BORDER};
        font-size: 12px;
    }}
    QHeaderView::section {{
        background: {t.PANEL_HI};
        color: {t.DIM};
        border: none;
        border-bottom: 1px solid {t.BORDER};
        padding: 6px 8px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
    }}
    QTableWidget::item:selected {{ background: {t.ACCENT_DIM}; color: {t.ACCENT}; }}
    """


def badge_qss_for(flag: str) -> str:
    """Inline QSS string for a per-flag badge label (colour varies by flag)."""
    label, bg, fg = FLAG_BADGE.get(flag, (flag.upper(), t.FIELD_HI, t.BODY))
    return (f"background:{bg}; color:{fg}; border-radius:4px; padding:1px 7px;"
            f"font-size:9px; font-weight:700; letter-spacing:1px;")

# ===== SNAPSMACK EOF =====
