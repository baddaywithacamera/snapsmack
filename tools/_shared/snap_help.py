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


def render_web_help_html(topics: Sequence[Topic], title: str = "Help") -> str:
    """Return a self-contained ? / F1 Help button + modal snippet for Flask/HTML tools."""
    import html
    import json

    items = [{"title": t, "body": b} for t, b in topics]
    items_json = json.dumps(items, ensure_ascii=False).replace("</", "<\\/")
    safe_title = html.escape(title)
    return (
        f'<button type="button" id="snap-help-btn" title="Help (F1)" '
        f'style="margin-left:8px;padding:6px 12px;border-radius:6px;border:1px solid #3a4;background:#161a16;color:#9f3;cursor:pointer;font-weight:600;">'
        f'Help (F1)</button>'
        f'<div id="snap-help-modal" hidden style="position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:none;align-items:center;justify-content:center;">'
        f'<div style="width:min(760px,92vw);max-height:82vh;background:#121512;color:#eaeaea;border:1px solid #3a4;border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:10px;">'
        f'<div style="display:flex;justify-content:space-between;align-items:center;">'
        f'<strong style="color:#9f3;">{safe_title}</strong>'
        f'<button type="button" id="snap-help-close" style="background:#222;color:#eee;border:1px solid #444;border-radius:6px;padding:4px 10px;cursor:pointer;">Close</button>'
        f'</div>'
        f'<input id="snap-help-search" type="search" placeholder="Search help..." style="padding:8px;background:#1a1e1a;color:#eee;border:1px solid #333;border-radius:6px;" />'
        f'<div id="snap-help-list" style="overflow:auto;display:flex;flex-direction:column;gap:10px;"></div>'
        f'</div></div>'
        f'<script>(function(){{var topics={items_json};'
        f'var btn=document.getElementById("snap-help-btn"),modal=document.getElementById("snap-help-modal"),'
        f'closeBtn=document.getElementById("snap-help-close"),search=document.getElementById("snap-help-search"),'
        f'list=document.getElementById("snap-help-list");'
        f'function render(q){{q=(q||"").trim().toLowerCase();list.innerHTML="";'
        f'var hits=topics.filter(function(t){{return !q||t.title.toLowerCase().indexOf(q)>=0||t.body.toLowerCase().indexOf(q)>=0;}});'
        f'if(!hits.length){{list.innerHTML="<p style=\\"color:#999\\">No help topics match that search.</p>";return;}}'
        f'hits.forEach(function(t){{var d=document.createElement("div");d.style.cssText="padding:10px;background:#181c18;border-radius:6px;";'
        f'var h=document.createElement("div");h.style.cssText="font-weight:700;color:#9f3;margin-bottom:4px;";h.textContent=t.title;'
        f'var p=document.createElement("div");p.style.cssText="white-space:pre-wrap;line-height:1.45;";p.textContent=t.body;'
        f'd.appendChild(h);d.appendChild(p);list.appendChild(d);}});}}'
        f'function openHelp(){{render(search.value);modal.style.display="flex";modal.hidden=false;search.focus();}}'
        f'function closeHelp(){{modal.style.display="none";modal.hidden=true;}}'
        f'if(btn)btn.addEventListener("click",openHelp);if(closeBtn)closeBtn.addEventListener("click",closeHelp);'
        f'if(search)search.addEventListener("input",function(){{render(search.value);}});'
        f'document.addEventListener("keydown",function(e){{if(e.key==="F1"){{e.preventDefault();openHelp();}}else if(e.key==="Escape"&&!modal.hidden){{closeHelp();}}}});'
        f'}})();</script>'
    )


TOOL_HELP_TOPICS: dict[str, List[Topic]] = {
    "sybu": [
        ("What SYBU does", "Point it at a folder of photos. It drafts a title, caption, tags, category and album for each one, then posts the whole batch to your site in one go."),
        ("First run", "Pick a site you have already set up, then pick a folder of photos. Check the drafts it makes before anything posts."),
        ("Main actions", "Review the queue, fix any title or caption, then post the batch. Nothing posts until you say so."),
        ("Where your data lives", "The queue and the drafts live on your computer until you post. Posted photos live on your site."),
    ],
    "shots-fired": [
        ("What SHOTS FIRED does", "Shows every scheduled post across all your sites in one agenda, so you can see the whole lineup and move posts to a different day."),
        ("First run", "Open it once your sites are set up. It lists the posts already scheduled on each site."),
        ("Main actions", "Move a post to a new day, spread a bunched week out, or bump one post. It does not create posts. That is SYBU and COLD SNAP's job."),
        ("Where your data lives", "The schedule lives on each site, as the post's date. This tool only shows it and changes the date when you move a post."),
    ],
    "oh-snap": [
        ("What OH SNAP does", "Designs and checks skins, the look of a SnapSmack site. Inspect a skin, check it is valid, render a preview, and export it."),
        ("First run", "Open a skin project, or start a new one. Render a preview to see how it looks before you export."),
        ("Main actions", "Inspect, validate, render, export. Export still needs the normal review and signing before a skin can be used."),
        ("Where your data lives", "Skin projects live on your computer. Exported skins go through the normal review and signing step."),
    ],
    "smack-your-mouth": [
        ("What SMACK YOUR MOUTH does", "Pulls your sites' comments in, so you can moderate and reply with no connection, then syncs your decisions back when you are online again."),
        ("First run", "Connect a site, pull the comments in, then work through the queue offline."),
        ("Main actions", "Approve, delete, or mark a comment as spam, and write replies. Press SYNC to send your decisions back."),
        ("Where your data lives", "The queue lives on your computer while you work. A decision only counts as done once the site confirms it."),
    ],
    "cronometer": [
        ("What CRONOMETER does", "Watches the scheduled jobs on every site, like RSS fetch, version check, Fediverse delivery, backups, and SMACKBACK checks, and shows each one red, amber, grey, or green."),
        ("First run", "Open it once your sites are set up. Each site appears with no re-typing."),
        ("Main actions", "Check the board, press RE-CHECK on one site, or REFRESH ALL to re-poll the whole fleet."),
        ("Where your data lives", "Job reports come from each site. A grey job means the site has not reported yet, never a green that was guessed."),
    ],
    "instantreplay": [
        ("What INSTANT REPLAY does", "Restores a backup onto a blog so you can prove the restore works, before you ever need it for real."),
        ("First run", "Pick a backup and a test blog to restore it onto. Restore onto a test blog, never your live one."),
        ("Main actions", "Run the restore, then check the test blog. To confirm, type OVERwRITE and the blog name exactly as shown, plus your authenticator code."),
        ("Where your data lives", "Backups live where SMACK UP YOUR BACKUP put them. The restored copy lives on the test blog. Note: this preview build lists offline fixture data until live catalog wiring lands."),
    ],
    "keysplease": [
        ("What KEYS PLEASE does", "Keeps the API keys your desktop tools use in one locked vault, so you can add, list, and take away a key in one place."),
        ("First run", "Set up the vault, then add the key for each site. The tools pick their key up from here."),
        ("Main actions", "Add a key, list the keys you hold, and revoke one when it should stop working. Too many wrong tries locks the vault."),
        ("Where your data lives", "Keys live in the locked vault on your computer. Note: this preview build lists offline fixture data until live vault listing lands."),
    ],
    "blogger-flogger": [
        ("What BLOGGER FLOGGER does", "Moves a Blogger blog into SMACKTALK, keeping the photographs. It opens a Google Takeout file, a feed, or an old Blogger backup from your computer."),
        ("First run", "Download your Blogger blog through Google Takeout first. Then make a BLOGGER FLOGGER key on the destination site."),
        ("Main actions", "Open the export, review what is there, pick what comes across, then import. Posts, pages, comments, labels, dates, drafts, and photographs all come across."),
        ("Where your data lives", "The export stays on your computer until you import. Imported posts live on your SnapSmack site."),
        ("One more thing", "If the destination site already has more than five items, allow imports for one hour from the same API Keys page."),
    ],
    "ghosted": [
        ("What GHOSTED does", "Moves a Ghost blog into SMACKTALK, keeping slugs, dates, tags, authors, excerpts, drafts, and photographs."),
        ("First run", "Export your Ghost blog as a JSON file or a ZIP, then open it here."),
        ("Main actions", "Run a dry run first to see exact counts of posts, pages, images, and tags. Then import. Run it again any time, it will not make duplicates."),
        ("Where your data lives", "The Ghost export stays on your computer. Imported posts live on your SnapSmack site."),
        ("Leftovers", "Anything Ghost-specific it cannot carry across is listed in the report, not quietly dropped."),
    ],
    "fed-up": [
        ("What FED-UP does", "Backs up your Fediverse profile, restores a backup onto a SnapSmack site, and explains your alias."),
        ("First run", "Connect your Fediverse account and pick a folder for the backup. The first backup takes everything. Later ones only take what is new."),
        ("Main actions", "Back up, restore an archive onto a site, and see the follower handles to tell where you went."),
        ("Where your data lives", "The backup is a folder of plain files you keep. You can also keep a second copy in a mirror folder or on Backblaze B2 through SMACK UP YOUR BACKUP."),
        ("Your alias", "Your alias, like @you@photoblogs.fyi, is set on your site's Fediverse settings. This tool only explains it and points you there."),
    ],
}


# ===== SNAPSMACK EOF =====
