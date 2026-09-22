"""SMACKPRESS — help (F1). Shown in COLD SNAP's help dialog with these topics."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

TOPICS = [
    ("What SMACKPRESS is",
     "SMACKPRESS moves posts from a WordPress site into a SnapSmack SMACKTALK site, "
     "one at a time, with you looking at each one. It is a tool you use once, then "
     "put away.\n\n"
     "The left side is the WordPress you are leaving. The right side is COLD SNAP's "
     "editor — the same COLD TAKE editor, batch and SEND you already know. SMACKPRESS "
     "has no editor of its own."),
    ("Before you start",
     "1. Install the SMACKPRESS companion plugin on the WordPress site "
     "(tools/smackpress-wp-companion). Delete it when you are done.\n"
     "2. In WordPress: Users → Profile → Application Passwords → make one for SMACKPRESS.\n"
     "3. At the top, pick the SnapSmack site you are migrating INTO. COLD TAKE needs that "
     "site's long-form ('smackpress') key — Connection details → long-form key.\n\n"
     "The WordPress address must be https://. The application password is kept in the "
     "shared vault, never written to disk in the clear."),
    ("Pulling a post across",
     "TEST + LOAD POSTS lists what WordPress has. Highlight one and press PULL ACROSS.\n\n"
     "SMACKPRESS downloads every picture the post uses — the featured image, gallery "
     "pictures, pictures inside figures, even pictures hot-linked from other sites — and "
     "opens the post in the editor with each picture in its place as a bucket picture. "
     "Nothing in the migrated post points back at WordPress, so switching WordPress off "
     "later breaks nothing.\n\n"
     "Title, date, tags, category, alt text and the old URL slug all come across. If a "
     "picture cannot be downloaded, the pull stops and says which one — a post is never "
     "migrated half-way."),
    ("Finishing and sending",
     "The editor is COLD SNAP's. Tidy the text, add a MOSAIC, set draft or published, "
     "then QUEUE POST and SEND QUEUED POSTS exactly as in COLD TAKE. It asks first, "
     "naming the site.\n\n"
     "Every pulled post lands in a batch called \"WordPress import\" so it is easy to "
     "find again later."),
    ("Marking it migrated",
     "After SEND has posted it, highlight the WordPress post on the left and press "
     "MARK MIGRATED. SMACKPRESS records which SMACKTALK post it became (the list shows a "
     "✓ and the migrated filter finds it) and offers to hide the original on WordPress "
     "— it is set to private and notes where it moved. Nothing on WordPress is ever "
     "deleted."),
    ("What does not come across",
     "Comments — not yet. Excerpts — SMACKTALK has none. Everything else does.\n\n"
     "Pages: switch the list to Pages; they pull across the same way."),
]

# ===== SNAPSMACK EOF =====
