"""Bundled, searchable offline help for the Qt COLD SNAP.

SNAP SLAPPER's help-dialog pattern (tools/hub/slapper_qt/help_dialog.py),
carried over so the suite reads as one family. F1 opens it. Topics describe
the Qt shell's actual controls — COLD SNAP shipping without help was the
ARCH-04 miss; this keeps it closed.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtWidgets import (
    QDialog, QHBoxLayout, QVBoxLayout, QLineEdit, QListWidget, QListWidgetItem,
    QTextEdit,
)

from . import theme

TOPICS = [
    ("What COLD SNAP is",
     "COLD SNAP builds posts completely offline, then publishes them to your "
     "SnapSmack site when you have a connection. Compose now — on a plane, a "
     "couch, or a dead zone — and send later. Nothing reaches the site until "
     "you press SEND QUEUED POSTS, and it always asks first, naming the site."),
    ("The three tabs",
     "COLD ONE — one photo per post, for SOLO (SmackOneOut) photoblog sites.\n\n"
     "COLD STACK — carousels, stacks and trigrams, for GRAM (GramOfSmack) grid "
     "sites.\n\n"
     "COLD TAKE — a longform SMACKTALK post: a title, a write-up, and a bucket "
     "of photos.\n\n"
     "Connect to a site that matches the tab you're posting from."),
    ("Connecting",
     "Pick a saved site at the top — the picker shows which site is loaded, and "
     "the line beside it says where posts will go. Open Connection details to "
     "type a Site URL and API key by hand (generate the key in SnapSmack Admin "
     "→ API Access). You do NOT need a connection to compose — only to send.\n\n"
     "COLD TAKE needs a SEPARATE key — the long-form key field in Connection "
     "details. Generate a 'smackpress'-type key in SnapSmack Admin → API "
     "Access. Photo sites don't need it."),
    ("The workflow",
     "1. Compose — add photos, caption and options. No network needed.\n\n"
     "2. QUEUE POST — commits the draft as ready to go. Queue as many as you "
     "like; Save as draft keeps one unfinished without queueing it.\n\n"
     "3. SEND QUEUED POSTS — when you're online, press the big button under "
     "the batch. The button shows how many are queued and goes dark when the "
     "queue is empty. COLD SNAP asks you to confirm — naming the site — then "
     "publishes each ready post and verifies it landed."),
    ("The batch panel",
     "The left rail is the batch: a folder of queued posts. It starts "
     "automatically on your first QUEUE POST. Manage… picks another batch, "
     "starts a new one, or exports/imports a batch through a thumb drive so "
     "you can compose on one machine and send from another. The ◀ button "
     "folds the rail away to give the composer the full width."),
    ("BIGGIE — the WYSIWYG editor",
     "COLD TAKE's write-up has two faces (COLD ONE and COLD STACK are "
     "deliberately basic: the plain box and bar only), switched by the TWIGGY / BIGGIE — "
     "BLOCKS pills above it. TWIGGY is the plain text box with the shortcode "
     "bar, exactly like the site editor. BIGGIE is one writing surface: click "
     "in it and type. Enter makes a new paragraph. Headings, quotes and pull "
     "quotes look like headings, quotes and pull quotes. The bar above it sets "
     "what the current paragraph is (¶ / H2 / H3 / BQ / PULL / DROP for a big "
     "first letter / UL / OL) and puts in the drawn things: IMG (an image from "
     "the site's Media Library), COL 2 / COL 3 (side-by-side columns you type "
     "into), HR (a divider), GAP (a vertical space) and, in COLD TAKE, MOSAIC.\n\n"
     "MOSAIC shows the post's photos tiled in the layout you chose, right where "
     "it will sit in the post — not a marker, the pictures. Double-click a "
     "mosaic (or right-click it) to change its photos or layout, or to remove "
     "it. The same goes for an image or a gap. Enter after a heading or a quote "
     "gives you a plain paragraph; Backspace at the start of a heading turns it "
     "back into a paragraph; Enter on an empty bullet ends the list. Ctrl+Z "
     "undoes anything, mosaics included.\n\n"
     "Switching faces never loses anything: BIGGIE reads your text and draws it "
     "(anything it doesn't recognise is kept byte-for-byte as RAW), and what you "
     "see always sends as the exact same shortcodes and HTML the TWIGGY bar "
     "makes — the site renders both identically. Columns inside columns are "
     "deliberately unavailable. The face you used last is remembered. "
     "Desktop-only: BIGGIE never appears in the web admin."),
    ("The shortcode bar",
     "The two button rows above the caption and write-up boxes are the same "
     "toolbar the CMS compose pages have — the desktop composer has the same "
     "controls as the site.\n\n"
     "B / I / U wrap the selected text bold, italic, underlined (Ctrl+B / "
     "Ctrl+I / Ctrl+U). LINK opens the link dialog (Ctrl+K). H2 / H3 make "
     "headings, BQ a blockquote, PULL a pullquote, HR a divider line. UL / OL turn the selected "
     "lines into a bullet or numbered list. IMG inserts an [img:ID|size|align] "
     "shortcode for an image already in the site's Media Library. COL 2 / "
     "COL 3 drop a column layout. DROP wraps a [dropcap], SPACER inserts a "
     "vertical gap.\n\n"
     "The one web button not here is PREVIEW — it needs the logged-in admin "
     "session in a browser."),
    ("Mosaic galleries (COLD TAKE)",
     "Writing a longform post? Put your photos in THE PHOTOS bucket, then "
     "click MOSAIC in the shortcode bar where you want them in the write-up — "
     "it drops a [mosaic] marker. On send, COLD SNAP builds a real justified "
     "tiled gallery from those photos and places it right there in the post.\n\n"
     "You can also type the markers yourself: a bare [mosaic] means THIS "
     "post's bucket photos; [mosaic:123] with a number points at an existing "
     "gallery already on the site and is left as-is. For a text-only grid "
     "with no photos, use COL 2 / COL 3. For one inline image from the "
     "Media Library, use IMG."),
    ("Per-photo controls (COLD STACK)",
     "The SELECTED PHOTO card is locked until you click a photo in the strip — "
     "its controls always work on the clicked photo, the same controls as the "
     "web poster. Every slider has a typed number beside it: type an exact "
     "value instead of landing a fine drag, or use the arrow keys on a focused "
     "slider. Colours are picked from the swatch button; the hex field stays "
     "for typing an exact value."),
    ("AI assist (titles, captions, ALT, tags)",
     "If a Gemini key is set: COLD ONE's AI Fill suggests a title, caption, "
     "ALT text and tags for the chosen photo. COLD STACK's AI Fill writes an "
     "ALT sentence for EVERY photo in the post and suggests a caption and "
     "tags from the cover when those boxes are empty. COLD TAKE's AI Fill "
     "writes an ALT sentence for every photo in the bucket. Always a starting "
     "point — edit it to your own voice before posting.\n\n"
     "ALT text is saved WITH THE IMAGE on the site (so is the Colour/B&W "
     "tag), not with the post — wherever the image appears, its description "
     "travels along."),
    ("COLD STORAGE (the fourth tab)",
     "The browsing face of the shared store: a grid of every image held "
     "offline for the connected blog. SYNC FROM SITE pulls the rest of the "
     "site's Gallery down — web-size files plus each image's title, caption, "
     "ALT and Colour/B&W tag — and skips what's already here.\n\n"
     "Click an image to see and edit its metadata. AI Fill suggests a title, "
     "caption and ALT for it; SAVE TO SITE lands your edits back on that "
     "image's row on the live site. Metadata only — COLD STORAGE never "
     "changes the photo file, never publishes, never deletes.\n\n"
     "Needs the site on 0.7.651 or newer (the sybu-images endpoint)."),
    ("The shared store",
     "Every post COLD SNAP sends is also recorded in the shared library on "
     "this computer (C:\\snapsmack\\shared_library\\<site>) — the post text, "
     "which images it used, their ALT text, and the web-size image file that "
     "was actually uploaded. Identical bytes are stored once, and the same "
     "image used in two posts belongs to both. Other tools (GYSS, SYBU) read "
     "this store offline instead of re-pulling from the site.\n\n"
     "Your originals are never copied anywhere — the store holds only what "
     "went to the site."),
    ("Your drafts are safe",
     "Drafts are saved on this computer and survive closing COLD SNAP, so you "
     "can work across several sittings. A failed send leaves the post marked "
     "with what went wrong so you can retry — nothing is lost. If a send can't "
     "reach the site, check your URL, key and connection."),
]


class HelpDialog(QDialog):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.setWindowTitle("COLD SNAP — Help")
        self.resize(760, 560)
        self.setStyleSheet(theme.stylesheet())

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

        for title, _text in TOPICS:
            self.list.addItem(QListWidgetItem(title))
        if TOPICS:
            self.list.setCurrentRow(0)

    def _show_current(self, row):
        if 0 <= row < len(self._visible_topics()):
            title, text = self._visible_topics()[row]
            self.body.setPlainText(f"{title}\n\n{text}")

    def _visible_topics(self):
        query = self.search.text().strip().lower()
        if not query:
            return TOPICS
        return [(t, x) for t, x in TOPICS if query in t.lower() or query in x.lower()]

    def _filter(self):
        visible = self._visible_topics()
        self.list.blockSignals(True)
        self.list.clear()
        for title, _text in visible:
            self.list.addItem(QListWidgetItem(title))
        self.list.blockSignals(False)
        if visible:
            self.list.setCurrentRow(0)
        else:
            self.body.setPlainText("No help topics match that search.")

# ===== SNAPSMACK EOF =====
