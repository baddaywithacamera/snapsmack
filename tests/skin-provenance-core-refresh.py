# SNAPSMACK_EOF_HEADER
"""Execute the production cleanup SQL against mixed ownership records."""
import re
import sqlite3
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / 'core/smackback.php').read_text(encoding='utf-8')
sql = re.search(r'\$pdo->exec\("(DELETE FROM snap_file_manifest WHERE file_path LIKE \'skins/%\'[^"\n]*)"\)', source).group(1)
rows = [
    ('core/render.php', None, 'core-hash'),
    ('skins/legacy/style.css', None, 'legacy-hash'),
    ('skins/50-shades-of-noah-grey/style.css', '50-shades-of-noah-grey', 'signed-font-skin'),
    ('skins/chaplin/manifest.json', 'chaplin', 'signed-chaplin'),
]

def cleanup(statement):
    with sqlite3.connect(':memory:') as db:
        db.execute('CREATE TABLE snap_file_manifest(file_path TEXT, skin_id TEXT, expected_hash TEXT)')
        db.executemany('INSERT INTO snap_file_manifest VALUES (?,?,?)', rows)
        db.execute(statement)
        return db.execute('SELECT file_path,skin_id,expected_hash FROM snap_file_manifest ORDER BY file_path').fetchall()

expected = sorted([rows[0], rows[2], rows[3]])
assert cleanup(sql) == expected, 'Core refresh must preserve signed skin records unchanged and remove only legacy core-owned skin rows'
assert cleanup(sql.replace(' AND skin_id IS NULL', '')) != expected, 'Mutation must reproduce lost skin provenance'
print('PASS: core cleanup retains both signed skins and hashes; removes legacy row; old deletion fails')
# ===== SNAPSMACK EOF =====
