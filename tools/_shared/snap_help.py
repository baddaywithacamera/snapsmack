"""Shared offline Help dialog for SnapSmack desktop tools (Qt + Tkinter).

Modelled on tools/coldsnap/coldsnap_qt/help_dialog.py so every tool in the suite
has searchable offline topics bound to F1 and a Help button.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from typing import Iterable, List, Optional, Sequence, Tuple

Topic = Tuple[str, str]


def filter_topics(topics: Sequence[Topic], query: str) -> List[Topic]:
    """Return topics whose title or body matches query (case-insensitive)."""
    q = str(query or "").strip().lower()
    if not q:
        return list(topics)
    return [(t, x) for t, x in topics if q in t.lower() or q in x.lower()]


def _build_qt_dialog_cls():
    from PySide6.QtWidgets import (
        QDialog, QHBoxLayout, QLineEdit, QListWidget, QListWidgetItem,
        QTextEdit, QVBoxLayout,
    )

    class HelpDialog(QDialog):
        """Searchable offline Help dialog for PySide6 SnapSmack tools."""

        def __init__(self, parent=None, topics: Optional[Iterable[Topic]] = None,
                     title: str = "Help", stylesheet: str = ""):
            super().__init__(parent)
            self.topics: List[Topic] = list(topics or [])
            self.setWindowTitle(title)
            self.resize(760, 560)
            if stylesheet:
                self.setStyleSheet(stylesheet)

            root = QVBoxLayout(self)
            root.setContentsMargins(12, 12, 12, 12)
            root.setSpacing(8)

            self.search = QLineEdit()
            self.search.setPlaceholderText("Search help…")
            self.search.textChanged.connect(self._filter)
            root.addWidget(self.search)

            body = QHBoxLayout()
            body.setSpacing(10)
            self.list = QListWidget()
            self.list.setFixedWidth(220)
            self.list.currentRowChanged.connect(self._show_current)
            body.addWidget(self.list)

            self.body = QTextEdit()
            self.body.setReadOnly(True)
            body.addWidget(self.body, 1)
            root.addLayout(body, 1)

            for topic_title, _text in self.topics:
                self.list.addItem(QListWidgetItem(topic_title))
            if self.topics:
                self.list.setCurrentRow(0)

        def _visible_topics(self) -> List[Topic]:
            return filter_topics(self.topics, self.search.text())

        def _show_current(self, row: int) -> None:
            visible = self._visible_topics()
            if 0 <= row < len(visible):
                topic_title, text = visible[row]
                self.body.setPlainText(f"{topic_title}\n\n{text}")

        def _filter(self) -> None:
            visible = self._visible_topics()
            self.list.blockSignals(True)
            self.list.clear()
            for topic_title, _text in visible:
                self.list.addItem(QListWidgetItem(topic_title))
            self.list.blockSignals(False)
            if visible:
                self.list.setCurrentRow(0)
            else:
                self.body.setPlainText("No help topics match that search.")

    return HelpDialog


try:
    HelpDialog = _build_qt_dialog_cls()
except Exception:  # noqa: BLE001
    HelpDialog = None  # type: ignore


def bind_f1_qt(window, topics: Sequence[Topic], title: str = "Help", stylesheet: str = ""):
    """Bind F1 on a Qt window to open HelpDialog with the given topics."""
    from PySide6.QtGui import QKeySequence, QShortcut

    def _open():
        dlg = HelpDialog(parent=window, topics=topics, title=title, stylesheet=stylesheet)
        dlg.exec()

    shortcut = QShortcut(QKeySequence("F1"), window)
    shortcut.activated.connect(_open)
    return shortcut, _open


def open_tk_help(parent, topics: Sequence[Topic], title: str = "Help"):
    """Open a searchable offline Help Toplevel for Tkinter SnapSmack tools."""
    import tkinter as tk

    topic_list = list(topics)
    win = tk.Toplevel(parent)
    win.title(title)
    win.geometry("760x540")
    win.configure(bg="#111111")

    search_var = tk.StringVar()
    search = tk.Entry(
        win, textvariable=search_var, bg="#1b1b1b", fg="#eaeaea",
        insertbackground="#8cff00", relief="flat",
    )
    search.pack(fill="x", padx=12, pady=(12, 8))

    body = tk.Frame(win, bg="#111111")
    body.pack(fill="both", expand=True, padx=12, pady=(0, 12))

    listbox = tk.Listbox(
        body, width=28, bg="#161616", fg="#eaeaea",
        selectbackground="#8cff00", selectforeground="#111111", relief="flat",
    )
    listbox.pack(side="left", fill="y", padx=(0, 10))

    text = tk.Text(
        body, wrap="word", bg="#161616", fg="#eaeaea", relief="flat", padx=10, pady=10,
    )
    text.pack(side="left", fill="both", expand=True)
    text.configure(state="disabled")

    visible_ref: List[Topic] = list(topic_list)

    def _show(idx: int) -> None:
        text.configure(state="normal")
        text.delete("1.0", "end")
        if 0 <= idx < len(visible_ref):
            t_title, t_body = visible_ref[idx]
            text.insert("1.0", f"{t_title}\n\n{t_body}")
        else:
            text.insert("1.0", "No help topics match that search.")
        text.configure(state="disabled")

    def _refresh(*_args) -> None:
        nonlocal visible_ref
        visible_ref = filter_topics(topic_list, search_var.get())
        listbox.delete(0, "end")
        for t_title, _ in visible_ref:
            listbox.insert("end", t_title)
        if visible_ref:
            listbox.selection_set(0)
            _show(0)
        else:
            _show(-1)

    def _on_select(_event=None) -> None:
        sel = listbox.curselection()
        if sel:
            _show(int(sel[0]))

    listbox.bind("<<ListboxSelect>>", _on_select)
    search_var.trace_add("write", _refresh)
    _refresh()
    return win


def bind_f1_tk(root, topics: Sequence[Topic], title: str = "Help"):
    """Bind <F1> on a Tkinter root window to open_tk_help."""
    handler = lambda _event=None: open_tk_help(root, topics, title=title)
    root.bind("<F1>", handler)
    return handler


# ===== SNAPSMACK EOF =====
