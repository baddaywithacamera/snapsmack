"""Small presentational widgets for BOARD LORD (thread rows, reply cards, badges)."""

from datetime import datetime, timezone

from PySide6.QtCore import Qt, Signal
from PySide6.QtWidgets import QWidget, QLabel, QHBoxLayout, QVBoxLayout

from . import style


def fmt_when(value) -> str:
    """A compact relative time from a MySQL UTC datetime string."""
    if not value:
        return ""
    try:
        dt = datetime.strptime(str(value), "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except (ValueError, TypeError):
        return str(value)
    secs = (datetime.now(timezone.utc) - dt).total_seconds()
    if secs < 60:
        return "just now"
    if secs < 3600:
        return f"{int(secs // 60)}m ago"
    if secs < 86400:
        return f"{int(secs // 3600)}h ago"
    if secs < 604800:
        return f"{int(secs // 86400)}d ago"
    return dt.strftime("%b %d")  # cross-platform (no %-d, which is invalid on Windows)


def make_flag_badge(flag: str) -> QLabel:
    label = style.FLAG_BADGE.get(flag, (flag.upper(),))[0]
    lbl = QLabel(label)
    lbl.setStyleSheet(style.badge_qss_for(flag))
    return lbl


def _status_badge(text: str, object_name: str) -> QLabel:
    lbl = QLabel(text)
    lbl.setObjectName(object_name)
    return lbl


class ThreadRow(QWidget):
    """One selectable thread summary in the middle list."""

    clicked = Signal(int)

    def __init__(self, thread: dict, parent=None):
        super().__init__(parent)
        self.thread_id = int(thread.get("id", 0))
        self._selected = False
        self.setObjectName("ThreadRow")
        self.setCursor(Qt.PointingHandCursor)

        unread = bool(thread.get("has_new"))
        flag = thread.get("flag", "none")

        row = QHBoxLayout(self)
        row.setContentsMargins(12, 9, 12, 9)
        row.setSpacing(9)

        dot = QLabel("●" if unread else "")
        dot.setObjectName("UnreadDot")
        dot.setFixedWidth(10)
        dot.setAlignment(Qt.AlignTop)
        row.addWidget(dot)

        center = QVBoxLayout()
        center.setContentsMargins(0, 0, 0, 0)
        center.setSpacing(3)

        top = QHBoxLayout()
        top.setContentsMargins(0, 0, 0, 0)
        top.setSpacing(6)
        if flag and flag != "none":
            top.addWidget(make_flag_badge(flag), 0, Qt.AlignTop)

        title = QLabel(thread.get("title", "(untitled)"))
        title.setObjectName("ThreadTitleUnread" if unread else "ThreadTitle")
        title.setWordWrap(True)
        top.addWidget(title, 1)

        if thread.get("is_pinned"):
            top.addWidget(_status_badge("PINNED", "BadgePinned"), 0, Qt.AlignTop)
        if thread.get("is_locked"):
            top.addWidget(_status_badge("LOCKED", "BadgeLocked"), 0, Qt.AlignTop)
        if thread.get("is_solved"):
            top.addWidget(_status_badge("SOLVED", "BadgeSolved"), 0, Qt.AlignTop)
        center.addLayout(top)

        author = thread.get("display_name") or thread.get("author_domain") or "unknown"
        replies = int(thread.get("reply_count", 0))
        views = int(thread.get("view_count", 0))
        when = fmt_when(thread.get("last_reply_at") or thread.get("created_at"))
        parts = [author, f"{replies} repl{'y' if replies == 1 else 'ies'}", f"{views} views"]
        if when:
            parts.append(when)
        if thread.get("is_following"):
            parts.append("★ following")
        meta = QLabel("   •   ".join(parts))
        meta.setObjectName("ThreadMeta")
        center.addWidget(meta)

        row.addLayout(center, 1)

    def set_selected(self, selected: bool):
        if selected == self._selected:
            return
        self._selected = selected
        self.setObjectName("ThreadRowSel" if selected else "ThreadRow")
        # Re-apply the stylesheet for the changed object name.
        self.style().unpolish(self)
        self.style().polish(self)

    def mousePressEvent(self, event):
        if event.button() == Qt.LeftButton:
            self.clicked.emit(self.thread_id)
        super().mousePressEvent(event)


class ReplyCard(QWidget):
    """One reply inside the thread detail."""

    def __init__(self, reply: dict, solved_id=None, parent=None):
        super().__init__(parent)
        self.setObjectName("ReplyCard")
        box = QVBoxLayout(self)
        box.setContentsMargins(11, 9, 11, 10)
        box.setSpacing(4)

        head = QHBoxLayout()
        head.setSpacing(8)
        author = QLabel(reply.get("display_name") or reply.get("author_domain") or "unknown")
        author.setObjectName("ReplyAuthor")
        head.addWidget(author)
        if solved_id is not None and int(reply.get("id", 0)) == int(solved_id):
            head.addWidget(_status_badge("ACCEPTED", "BadgeSolved"))
        head.addStretch(1)
        meta = QLabel(fmt_when(reply.get("created_at")) + (" · edited" if reply.get("is_edited") else ""))
        meta.setObjectName("ReplyMeta")
        head.addWidget(meta)
        box.addLayout(head)

        body = QLabel(reply.get("body", ""))
        body.setObjectName("ReplyBody")
        body.setWordWrap(True)
        body.setTextInteractionFlags(Qt.TextSelectableByMouse)
        box.addWidget(body)

# ===== SNAPSMACK EOF =====
