"""
GOBSMACKED Scanner — db.py
Database operations: fetch comments, fetch banned vectors, upload reports.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import hashlib
import json
from typing import Optional

import pymysql
import pymysql.cursors

# ─── Connection ───────────────────────────────────────────────────────────────

def connect(cfg: dict):
    return pymysql.connect(
        host    = cfg['db_host'],
        port    = int(cfg.get('db_port', 3306)),
        user    = cfg['db_user'],
        password= cfg['db_password'],
        database= cfg['db_name'],
        charset = 'utf8mb4',
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=8,
    )

# ─── Data fetchers ────────────────────────────────────────────────────────────

def fetch_comment_authors(conn, min_words: int = 30) -> dict:
    """
    Return a dict keyed by (fp_hash or email_hash) → list of comment texts.
    Only includes commenters with enough text to produce a vector.
    Groups by fp_hash first; falls back to SHA-256(email) for comments without one.
    """
    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                COALESCE(
                    NULLIF(fp_hash, ''),
                    SHA2(LOWER(TRIM(COALESCE(comment_email, ''))), 256)
                ) AS author_key,
                GROUP_CONCAT(comment_text SEPARATOR ' ') AS combined_text,
                COUNT(*) AS comment_count,
                MAX(comment_email) AS email,
                MAX(comment_author) AS display_name,
                MAX(comment_ip) AS last_ip
            FROM snap_comments
            WHERE comment_text IS NOT NULL
              AND comment_text != ''
              AND is_approved = 1
            GROUP BY author_key
            HAVING author_key IS NOT NULL AND author_key != ''
        """)
        rows = cur.fetchall()

    authors = {}
    for row in rows:
        key  = row['author_key']
        text = row['combined_text'] or ''
        words = text.split()
        if len(words) >= min_words:
            authors[key] = {
                'texts':         [text],
                'comment_count': row['comment_count'],
                'display_name':  row['display_name'] or '(anonymous)',
                'email':         row['email'] or '',
                'last_ip':       row['last_ip'] or '',
            }
    return authors


def fetch_banned_vectors(conn) -> list[dict]:
    """
    Fetch stylometric vectors attached to banned fingerprints in snap_bans.
    Looks for a snap_bans_style_cache table; returns empty list if not present.
    """
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT b.ban_value AS fp_hash, b.reason,
                       bsc.vector, bsc.word_count
                FROM snap_bans b
                JOIN snap_bans_style_cache bsc ON bsc.fp_hash = b.ban_value
                WHERE b.ban_type = 'fingerprint'
                  AND b.is_active = 1
                  AND bsc.vector IS NOT NULL
            """)
            rows = cur.fetchall()
        result = []
        for row in rows:
            try:
                vec = json.loads(row['vector'])
            except Exception:
                continue
            if isinstance(vec, list) and len(vec) == 25:
                result.append({
                    'fp_hash':    row['fp_hash'],
                    'reason':     row['reason'] or '',
                    'vector':     vec,
                    'word_count': row['word_count'],
                })
        return result
    except Exception:
        return []


def store_scan_results(conn, results: list[dict]) -> None:
    """
    Cache scan results into snap_gobsmacked_scan (created on first use).
    Each row: author_key, matched_key, similarity, flagged_at.
    """
    try:
        with conn.cursor() as cur:
            cur.execute("""
                CREATE TABLE IF NOT EXISTS snap_gobsmacked_scan (
                    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    author_key   VARCHAR(64)  NOT NULL,
                    matched_key  VARCHAR(64)  NOT NULL,
                    match_type   ENUM('peer','banned') NOT NULL DEFAULT 'peer',
                    similarity   DECIMAL(5,4) NOT NULL,
                    display_name VARCHAR(150) DEFAULT NULL,
                    email        VARCHAR(150) DEFAULT NULL,
                    flagged_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    reviewed     TINYINT(1)   NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_pair (author_key, matched_key),
                    KEY idx_sim (similarity),
                    KEY idx_flagged (flagged_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            """)
            conn.commit()

            for r in results:
                cur.execute("""
                    INSERT INTO snap_gobsmacked_scan
                        (author_key, matched_key, match_type, similarity, display_name, email)
                    VALUES (%s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        similarity   = VALUES(similarity),
                        flagged_at   = NOW(),
                        reviewed     = 0
                """, (
                    r['author_key'], r['matched_key'], r['match_type'],
                    r['similarity'], r.get('display_name',''), r.get('email',''),
                ))
            conn.commit()
    except Exception as e:
        conn.rollback()
        raise


def fetch_stored_results(conn) -> list[dict]:
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT id, author_key, matched_key, match_type,
                       similarity, display_name, email, flagged_at, reviewed
                FROM snap_gobsmacked_scan
                ORDER BY similarity DESC, flagged_at DESC
                LIMIT 500
            """)
            return cur.fetchall()
    except Exception:
        return []


def mark_reviewed(conn, row_id: int) -> None:
    with conn.cursor() as cur:
        cur.execute("UPDATE snap_gobsmacked_scan SET reviewed = 1 WHERE id = %s", (row_id,))
    conn.commit()
# ===== SNAPSMACK EOF =====
