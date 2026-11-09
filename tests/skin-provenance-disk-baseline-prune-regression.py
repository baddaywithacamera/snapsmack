# SNAPSMACK_EOF_HEADER
"""The disk-baseline orphan prune must keep signed skin provenance rows.

THE BETA BLOCKER (SS-1038). smackback_init_from_disk() runs at the end of every
core update. It walks the monitored tree -- which EXCLUDES skins/ (should_monitor)
-- into a seen-set, then prunes every snap_file_manifest row whose file_path is
not in that set. A signed skin's provenance rows (skin_id = slug, file_path
skins/<slug>/...) are never in the set, so the prune deleted them on EVERY core
update; the next skin save then hit "no signed-package integrity record" and was
refused, with no user exit. Sites hit it repeatedly (CHAPLIN/acolourlesslife,
NEW HORIZON/pixhellated, GLIDE/portalproject, ...).

THE FIX. The orphan prune is scoped to core rows only (WHERE skin_id IS NULL).
Signed skin rows (skin_id NOT NULL) are owned by the signed install/recovery
path and are never touched by the core disk baseline.

This test binds to the real query in core/smackback.php and exercises the prune
over mixed-ownership rows, including a repeat refresh (every core update reruns
it).
"""
import re
import sqlite3
from pathlib import Path

SOURCE = (Path(__file__).resolve().parents[1] / 'core/smackback.php').read_text(encoding='utf-8')

# The query the disk-baseline orphan prune iterates. Bound to source so a
# regression that drops the skin_id guard fails here.
m = re.search(r'SELECT id, file_path FROM snap_file_manifest(?: WHERE skin_id IS NULL)?', SOURCE)
assert m, 'disk-baseline prune query not found in core/smackback.php'
PRUNE_SELECT = m.group(0)
assert 'WHERE skin_id IS NULL' in PRUNE_SELECT, (
    'the disk-baseline orphan prune must be scoped to core rows (skin_id IS NULL), '
    'or it deletes signed skin provenance on every core update (SS-1038)'
)

# skins/ is excluded from the monitored tree, so NO skins/ path is ever "seen".
SEEN = {'core/render.php', 'index.php'}

ROWS = [
    ('core/render.php', None, 'core-hash'),          # core, on disk -> keep
    ('core/removed.php', None, 'stale-core'),         # core, gone from disk -> prune
    ('skins/legacy/old.css', None, 'legacy-null'),    # old core-shipped skin file, no skin_id
    ('skins/50-shades-of-noah-grey/style.css', '50-shades-of-noah-grey', 'signed-50'),  # signed -> keep
    ('skins/chaplin/manifest.json', 'chaplin', 'signed-chaplin'),                        # signed -> keep
]


def run_prune(select_sql, passes=1):
    db = sqlite3.connect(':memory:')
    db.execute('CREATE TABLE snap_file_manifest(id INTEGER PRIMARY KEY AUTOINCREMENT, file_path TEXT, skin_id TEXT, expected_hash TEXT)')
    db.executemany('INSERT INTO snap_file_manifest(file_path,skin_id,expected_hash) VALUES (?,?,?)', ROWS)
    for _ in range(passes):
        doomed = [rid for (rid, fp) in db.execute(select_sql).fetchall() if fp not in SEEN]
        db.executemany('DELETE FROM snap_file_manifest WHERE id = ?', [(r,) for r in doomed])
    kept = {fp for (fp,) in db.execute('SELECT file_path FROM snap_file_manifest ORDER BY file_path')}
    db.close()
    return kept

# --- fixed query: signed skin rows survive; only stale core rows go ---
kept = run_prune(PRUNE_SELECT)
assert 'skins/50-shades-of-noah-grey/style.css' in kept, 'signed skin row was pruned by the disk baseline'
assert 'skins/chaplin/manifest.json' in kept, 'signed skin row was pruned by the disk baseline'
assert 'core/render.php' in kept, 'an on-disk core row was pruned'
assert 'core/removed.php' not in kept, 'a stale core row survived the prune'
# the legacy null-skin_id skin file is a core-owned row not on disk -> still pruned (unchanged behaviour)
assert 'skins/legacy/old.css' not in kept, 'legacy null-skin_id skin row must still be pruned'

# --- repeat refresh (every core update reruns it): signed rows still survive ---
kept_twice = run_prune(PRUNE_SELECT, passes=3)
assert 'skins/50-shades-of-noah-grey/style.css' in kept_twice and 'skins/chaplin/manifest.json' in kept_twice, (
    'repeat core refreshes must not erode signed skin provenance'
)

# --- mutation: the old unguarded query destroys signed skin provenance ---
mutated = run_prune(PRUNE_SELECT.replace(' WHERE skin_id IS NULL', ''))
assert 'skins/chaplin/manifest.json' not in mutated, (
    'without the skin_id guard the prune deletes signed skin rows -- that is the bug'
)

print('PASS: disk-baseline prune keeps signed skin provenance across repeat refreshes; mutation reproduces the loss')
# ===== SNAPSMACK EOF =====
