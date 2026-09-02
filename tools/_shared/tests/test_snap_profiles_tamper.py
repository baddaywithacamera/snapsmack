"""
SECAUDIT 054 F1 — shared-profile tamper check (test).

The CRITICAL: a shared profile is stored as <site_key(site_url)>.json. If an
attacker rewrites the file's in-file site_url to their own host while keeping the
real api_key, any tool that loads it and builds a Bearer session would send that
site's posting key to the attacker. The loader now refuses a profile whose in-file
site_url no longer reduces to its filename — enforced once, so ALL readers inherit it.

Deny-path first: the tampered profile must NOT come back from any read path; an
honest profile (including trivial URL formatting differences) must still load.

Run with a throwaway SNAPSMACK_HOME so it never touches the real store:
    SNAPSMACK_HOME=<tmp> python tools/_shared/tests/test_snap_profiles_tamper.py
(the test sets it itself).
"""

import json
import os
import sys
import tempfile

# Hermetic root BEFORE importing the modules that read it.
_TMP = tempfile.mkdtemp(prefix="snapprof-test-")
os.environ["SNAPSMACK_HOME"] = _TMP

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_profiles as P


def _checks():
    n = 0

    # Honest profile round-trips and loads from every path.
    path = P.save({"name": "Forever", "site_url": "https://foreverphotograph.ing",
                   "api_key": "REAL-KEY-123"})
    assert os.path.basename(path) == "foreverphotograph.ing.json", path
    assert P.load_by_site("https://foreverphotograph.ing")["api_key"] == "REAL-KEY-123"
    # trivial formatting differences (case, trailing slash, path) still resolve —
    # both sides normalise through site_key(), so no false tamper-trip.
    assert P.load_by_site("https://FOREVERphotograph.ing/gallery/") is not None
    assert any(p["name"] == "Forever" for p in P.list_profiles())
    assert P.load_by_name("Forever") is not None
    n += 1

    # TAMPER: flip the in-file site_url to an attacker host, keep the real key +
    # the original filename. This is the exact CRITICAL vector.
    with open(path, encoding="utf-8") as f:
        disk = json.load(f)
    attacker = dict(disk)
    attacker["site_url"] = "https://attacker.example.com"   # key (api_key_enc) untouched
    with open(path, "w", encoding="utf-8") as f:
        json.dump(attacker, f)

    # DENY on every read path — the key must never reach a caller from this file.
    assert P.load_by_site("https://foreverphotograph.ing") is None, "tampered load_by_site not refused"
    assert P.load_by_name("Forever") is None, "tampered load_by_name not refused"
    assert all(p.get("site_url") != "https://attacker.example.com" for p in P.list_profiles()), \
        "tampered profile leaked through list_profiles"
    assert P.list_profiles() == [], "tampered profile should be the only one and refused"
    n += 1

    # A profile with no site_url is also refused (can't be verified).
    empty = os.path.join(P.profiles_dir(), "bogus.json")
    with open(empty, "w", encoding="utf-8") as f:
        json.dump({"name": "x", "site_url": "", "api_key_enc": "abc"}, f)
    assert P.load_by_name("x") is None, "site_url-less profile not refused"
    n += 1

    return n


if __name__ == "__main__":
    try:
        count = _checks()
        print("OK — %d checks passed" % count)
    finally:
        import shutil
        shutil.rmtree(_TMP, ignore_errors=True)
