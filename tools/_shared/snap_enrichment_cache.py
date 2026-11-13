"""
SNAPSMACK shared AI-enrichment cache.

Stores the complete, reviewable AI response in the per-blog catalog.sqlite. The
CMS copy is authoritative; ``merge_remote`` applies its revision unless both
sides changed from the same base, in which case a collision is returned for the
user to resolve. Image bytes are identified by SHA-256, never by filename.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import hashlib
import json
import os
import sqlite3
import time
from typing import Any, Dict, Optional

import snap_home

DEFAULT_TTL_DAYS = 90

_SCHEMA = """
CREATE TABLE IF NOT EXISTS enrichment_cache (
  cache_key TEXT PRIMARY KEY,
  image_sha256 TEXT NOT NULL,
  domain TEXT NOT NULL,
  model TEXT NOT NULL,
  prompt_sha256 TEXT NOT NULL,
  prompt_version TEXT NOT NULL DEFAULT '1',
  bundle_json TEXT NOT NULL,
  raw_response TEXT NOT NULL DEFAULT '',
  accepted_json TEXT NOT NULL DEFAULT '{}',
  revision INTEGER NOT NULL DEFAULT 1,
  base_revision INTEGER NOT NULL DEFAULT 0,
  generated_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  modified_at INTEGER NOT NULL,
  source TEXT NOT NULL DEFAULT 'desktop',
  dirty INTEGER NOT NULL DEFAULT 1,
  base_prompt_sha256 TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_enrichment_lookup
  ON enrichment_cache(image_sha256, domain, model, prompt_sha256, expires_at);
CREATE INDEX IF NOT EXISTS idx_enrichment_reuse
  ON enrichment_cache(image_sha256, model, base_prompt_sha256);
"""


def image_sha256(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def prompt_sha256(prompt: str) -> str:
    return hashlib.sha256((prompt or "").encode("utf-8")).hexdigest()


def cache_key(image_hash: str, domain: str, model: str, prompt_hash: str,
              prompt_version: str = "1") -> str:
    material = "\n".join((image_hash, domain.lower().strip(), model,
                            prompt_hash, str(prompt_version)))
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def _db_path(site: str) -> str:
    return os.path.join(snap_home.site_db_dir(site), "catalog.sqlite")


def _connect(site: str) -> sqlite3.Connection:
    return _connect_path(_db_path(site))


def _connect_path(path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.executescript(_SCHEMA)
    # SS-1137. CREATE TABLE IF NOT EXISTS does not add a column to a database
    # that already exists, and every one of Sean's sites already has one. The
    # ALTER is the migration, and it is deliberately the only one: nothing is
    # re-keyed, so no row he has already paid for is invalidated.
    try:
        conn.execute("ALTER TABLE enrichment_cache "
                     "ADD COLUMN base_prompt_sha256 TEXT NOT NULL DEFAULT ''")
        conn.commit()
    except sqlite3.OperationalError:
        pass            # already present
    conn.executescript(
        "CREATE INDEX IF NOT EXISTS idx_enrichment_reuse"
        "  ON enrichment_cache(image_sha256, model, base_prompt_sha256);")
    return conn


def get(site: str, image_hash: str, domain: str, model: str, prompt_hash: str,
        prompt_version: str = "1", include_expired: bool = False) -> Optional[dict]:
    key = cache_key(image_hash, domain, model, prompt_hash, prompt_version)
    conn = _connect(site)
    try:
        row = conn.execute("SELECT * FROM enrichment_cache WHERE cache_key = ?", (key,)).fetchone()
        if not row or (not include_expired and int(row["expires_at"]) <= int(time.time())):
            return None
        result = dict(row)
        result["bundle"] = json.loads(result.pop("bundle_json"))
        result["accepted"] = json.loads(result.pop("accepted_json"))
        return result
    finally:
        conn.close()


def put(site: str, image_hash: str, domain: str, model: str, prompt_hash: str,
        bundle: Dict[str, Any], *, raw_response: str = "", accepted: Optional[dict] = None,
        prompt_version: str = "1", ttl_days: int = DEFAULT_TTL_DAYS,
        source: str = "desktop", remote_revision: Optional[int] = None,
        base_prompt_hash: str = "") -> dict:
    now = int(time.time())
    ttl_days = max(1, min(int(ttl_days or DEFAULT_TTL_DAYS), 3650))
    key = cache_key(image_hash, domain, model, prompt_hash, prompt_version)
    conn = _connect(site)
    try:
        old = conn.execute("SELECT revision FROM enrichment_cache WHERE cache_key = ?", (key,)).fetchone()
        revision = int(remote_revision) if remote_revision is not None else (int(old[0]) + 1 if old else 1)
        dirty = 0 if source == "cms" else 1
        with conn:
            conn.execute("""
                INSERT INTO enrichment_cache
                  (cache_key,image_sha256,domain,model,prompt_sha256,prompt_version,
                   bundle_json,raw_response,accepted_json,revision,base_revision,
                   generated_at,expires_at,modified_at,source,dirty,base_prompt_sha256)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(cache_key) DO UPDATE SET
                  base_prompt_sha256=excluded.base_prompt_sha256,
                  bundle_json=excluded.bundle_json, raw_response=excluded.raw_response,
                  accepted_json=excluded.accepted_json, revision=excluded.revision,
                  base_revision=excluded.base_revision, generated_at=excluded.generated_at,
                  expires_at=excluded.expires_at, modified_at=excluded.modified_at,
                  source=excluded.source, dirty=excluded.dirty
            """, (key, image_hash, domain.lower().strip(), model, prompt_hash,
                    str(prompt_version), json.dumps(bundle, ensure_ascii=False, sort_keys=True),
                    raw_response or "", json.dumps(accepted or {}, ensure_ascii=False, sort_keys=True),
                    revision, int(old[0]) if old else 0, now, now + ttl_days * 86400,
                    now, source, dirty, str(base_prompt_hash or "")))
        return get(site, image_hash, domain, model, prompt_hash, prompt_version, True) or {}
    finally:
        conn.close()


def _site_catalogues() -> list:
    """Every site's catalog.sqlite under the shared library.

    SS-1137 cause 2: the store is per site, so a photograph enriched for one
    blog could never be found when it arrived at another -- a different file
    entirely. The enrichment describes the PHOTOGRAPH, so it is reusable
    wherever the photograph goes.
    """
    root = snap_home.shared_library()
    found = []
    try:
        names = sorted(os.listdir(root))
    except OSError:
        return found
    for name in names:
        path = os.path.join(root, name, "db", "catalog.sqlite")
        if os.path.isfile(path):
            found.append(path)
    return found


def find_reusable(image_hash: str, model: str, base_prompt_hash: str,
                  prefer_site: Optional[str] = None,
                  legacy_prompt_hashes=()) -> Optional[dict]:
    """Enrichment already paid for, for THESE IMAGE BYTES and THIS prompt.

    SS-1137 -- the money bug. Sean pays Gemini per image per blog, and the
    answer was already on his disk every time.

    WHAT IT MATCHES ON, and why each part:
      image_sha256        the photograph's own bytes. Not its name, not its
                          path, not its mtime -- a copied or renamed file is
                          the same photograph and the same answer.
      model               a different model genuinely gives a different answer.
      base_prompt_sha256  the OWNER'S prompt: his custom text, or the default
                          built from his categories and albums. Sean's call,
                          12 Nov. A changed instruction still re-enriches.

    WHAT IT DELIBERATELY DOES NOT MATCH ON:
      domain              the enrichment describes the photograph. Caption,
                          alt text, tags, colour and orientation are not
                          properties of which blog it is posted to. The domain
                          in cache_key is what made a second blog always miss.
      prompt_sha256       that hash is of the RENDERED prompt, which has the
                          filename bound into it (gemini.prompt_with_filename)
                          and, on a retry, a previously generated title. It can
                          never match across files. That is why this function
                          exists instead of a call to get().
      expires_at          a photograph does not change in 90 days and neither
                          does its caption. Expiring PAID content-keyed data
                          charges him again for nothing.

    NOTHING IS RE-KEYED. cache_key, get() and the CMS merge contract are
    untouched, so no row he has already paid for is invalidated by this.

    LEGACY ROWS. Muse's finding, and she was right: every row written before
    this change stores '' for base_prompt_sha256, so matching only on that
    column means the FIRST run after the fix re-pays for everything already
    enriched. My "no re-charge" claim was true of the key and misleading about
    the money, which is the whole ticket.

    So `legacy_prompt_hashes` is tried when the content match misses: the
    caller recomputes the hash the OLD code would have written for this file
    (the owner prompt with this filename bound in) and a pre-fix row is matched
    on `prompt_sha256` instead. That recovers the re-charge for a photograph
    still under the name it was enriched with -- the same-site, same-folder,
    past-90-days case, and a copy that kept its filename.

    WHAT IT CANNOT RECOVER: a pre-fix row whose file has since been RENAMED.
    Its stored hash contains the old stem and nothing on disk can reproduce it.
    That enrichment is paid for once more and then cached properly forever. I
    am stating it rather than leaving it to be discovered on the bill.
    """
    image_hash = str(image_hash or "")
    base_prompt_hash = str(base_prompt_hash or "")
    if not image_hash or not base_prompt_hash:
        return None
    paths = _site_catalogues()
    if prefer_site:
        own = _db_path(prefer_site)
        # Look in the site being posted to first: it is the likeliest hit and
        # the cheapest, and it keeps provenance local when both have a copy.
        paths = [own] + [p for p in paths if os.path.normcase(p) != os.path.normcase(own)]
    for path in paths:
        if not os.path.isfile(path):
            continue
        try:
            conn = _connect_path(path)
        except sqlite3.Error:
            continue
        try:
            row = conn.execute(
                """SELECT * FROM enrichment_cache
                   WHERE image_sha256 = ? AND model = ? AND base_prompt_sha256 = ?
                   ORDER BY revision DESC, modified_at DESC LIMIT 1""",
                (image_hash, str(model or ""), base_prompt_hash)).fetchone()
        except sqlite3.Error:
            row = None
        finally:
            conn.close()
        if not row:
            legacy = [str(h) for h in (legacy_prompt_hashes or ()) if str(h or "")]
            if legacy:
                marks = ",".join("?" for _ in legacy)
                try:
                    conn = _connect_path(path)
                    row = conn.execute(
                        """SELECT * FROM enrichment_cache
                           WHERE image_sha256 = ? AND model = ?
                             AND prompt_sha256 IN (%s)
                           ORDER BY revision DESC, modified_at DESC LIMIT 1""" % marks,
                        tuple([image_hash, str(model or "")] + legacy)).fetchone()
                except sqlite3.Error:
                    row = None
                finally:
                    try:
                        conn.close()
                    except Exception:
                        pass
        if row:
            result = dict(row)
            result["bundle"] = json.loads(result.pop("bundle_json"))
            result["accepted"] = json.loads(result.pop("accepted_json"))
            result["reused_from_db"] = path
            result["reused_from_domain"] = result.get("domain", "")
            return result
    return None


def merge_remote(site: str, record: dict) -> dict:
    """Merge one authoritative CMS record; report, never hide, a true collision."""
    key = str(record.get("cache_key", ""))
    if not key:
        raise ValueError("Remote enrichment record has no cache_key")
    conn = _connect(site)
    try:
        local = conn.execute("SELECT * FROM enrichment_cache WHERE cache_key = ?", (key,)).fetchone()
        if local and int(local["dirty"]) and int(record.get("revision", 0)) > int(local["base_revision"]):
            return {"status": "collision", "cache_key": key,
                    "local": dict(local), "remote": record}
        with conn:
            conn.execute("""
              INSERT OR REPLACE INTO enrichment_cache
              (cache_key,image_sha256,domain,model,prompt_sha256,prompt_version,bundle_json,
               raw_response,accepted_json,revision,base_revision,generated_at,expires_at,
               modified_at,source,dirty) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)
            """, (key, record["image_sha256"], record["domain"], record["model"],
                    record["prompt_sha256"], str(record.get("prompt_version", "1")),
                    json.dumps(record.get("bundle", {}), ensure_ascii=False, sort_keys=True),
                    str(record.get("raw_response", "")),
                    json.dumps(record.get("accepted", {}), ensure_ascii=False, sort_keys=True),
                    int(record.get("revision", 1)), int(record.get("revision", 1)),
                    int(record.get("generated_at", time.time())),
                    int(record.get("expires_at", time.time())),
                    int(record.get("modified_at", time.time())), "cms"))
        return {"status": "updated", "cache_key": key}
    finally:
        conn.close()


def pending(site: str) -> list:
    conn = _connect(site)
    try:
        rows = conn.execute("SELECT * FROM enrichment_cache WHERE dirty = 1 ORDER BY modified_at").fetchall()
        output = []
        for row in rows:
            item = dict(row)
            item["bundle"] = json.loads(item.pop("bundle_json"))
            item["accepted"] = json.loads(item.pop("accepted_json"))
            output.append(item)
        return output
    finally:
        conn.close()


def mark_synced(site: str, cache_key_value: str, revision: int) -> None:
    """Mark one desktop record accepted by the authoritative CMS copy."""
    conn = _connect(site)
    try:
        with conn:
            conn.execute(
                """UPDATE enrichment_cache
                   SET revision = ?, base_revision = ?, dirty = 0
                   WHERE cache_key = ?""",
                (int(revision), int(revision), str(cache_key_value)),
            )
    finally:
        conn.close()

# ===== SNAPSMACK EOF =====
