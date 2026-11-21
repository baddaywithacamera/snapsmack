"""Tests for SS-1457 Desktop Stylometric Analysis Engine:
- OS keyring-only storage for admin_token + legacy plaintext migration out of INI
- Deterministic 25-float cosine similarity & union-find clustering at >= 0.80
- Confidence tiers pinned to 0.95 (STRONG) / 0.90 (LIKELY) / 0.80 (POSSIBLE)
- Dismissed pair filtering & target_colour escalation calculation
- Paginated pull client (GET ?route=admin/style/vectors) and results upload
  client (POST ?route=admin/style/results) with redirect refusal on Bearer auth.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import configparser
import io
import json
import os
import sys
import urllib.error
import urllib.parse

import pytest

_TOOL_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _TOOL_DIR not in sys.path:
    sys.path.insert(0, _TOOL_DIR)

import config as cfg_module
import scanner as scanner_module
import smackattack_core as core_module


def _unit_vec(idx: int, second_idx: int = -1, second_val: float = 0.0) -> list[float]:
    v = [0.0] * 25
    v[idx] = 1.0
    if 0 <= second_idx < 25:
        v[second_idx] = second_val
    return v


def test_admin_token_stored_in_keyring_only_and_migrated_out_of_ini(tmp_path, monkeypatch):
    ini_path = tmp_path / "gobsmacked-scanner.ini"
    store: dict[tuple[str, str], str] = {}

    monkeypatch.setattr(cfg_module, "_config_path", lambda: str(ini_path))
    monkeypatch.setattr(cfg_module, "has_keyring", lambda: True)

    class FakeKeyring:
        @staticmethod
        def get_password(service, account):
            return store.get((service, account))

        @staticmethod
        def set_password(service, account, value):
            store[(service, account)] = value

        @staticmethod
        def delete_password(service, account):
            store.pop((service, account), None)

    monkeypatch.setattr(cfg_module, "keyring", FakeKeyring)

    # Seed a legacy INI file containing plaintext secrets
    ini_path.write_text(
        "[settings]\n"
        "api_url = https://smackcentral.example/sc-enemy-api.php\n"
        "admin_token = secret_admin_token_123\n"
        "api_key = legacy_site_key_456\n"
        "db_password = legacy_db_pw\n",
        encoding="utf-8",
    )

    loaded = cfg_module.load()
    assert loaded["admin_token"] == "secret_admin_token_123"
    assert loaded["api_key"] == "legacy_site_key_456"
    assert loaded["db_password"] == "legacy_db_pw"

    # Verify the INI file on disk was scrubbed of all secrets on load
    raw_ini = configparser.ConfigParser()
    raw_ini.read(str(ini_path), encoding="utf-8")
    assert raw_ini.get("settings", "admin_token") == ""
    assert raw_ini.get("settings", "api_key") == ""
    assert raw_ini.get("settings", "db_password") == ""
    assert store.get(("gobsmacked-scanner", "https://smackcentral.example/sc-enemy-api.php:admin_token")) == "secret_admin_token_123"

    # When keyring is unavailable, saving a non-empty admin_token MUST fail closed
    monkeypatch.setattr(cfg_module, "has_keyring", lambda: False)
    with pytest.raises(RuntimeError, match="refusing to write admin_token"):
        cfg_module.save({"api_url": "https://smackcentral.example", "admin_token": "must_not_leak"})


def test_analyze_central_vectors_clusters_and_confidence_tiers():
    # Cluster 1: fp 10 (yellow), fp 20 (red), fp 30 (orange) -> connected via >=0.80 edges
    # 10 <-> 20: identical vector => sim 1.0000 (STRONG MATCH)
    # 20 <-> 30: [1.0, 0, ...] vs [0.85, 0.5268, ...] => sim ~0.85
    # Cluster 2: fp 40 (yellow) & fp 50 (orange) with sim ~0.92 (LIKELY MATCH)
    # Dismissed pair: fp 60 & fp 70 (identical vectors, but dismissed)
    # Green fingerprint: fp 80 (filtered out by default)
    v_base = _unit_vec(0)
    v_85   = _unit_vec(0, 1, 0.6197)   # 1 / sqrt(1 + 0.6197^2) = 0.8500
    v_92   = _unit_vec(2, 3, 0.4260)   # 1 / sqrt(1 + 0.4260^2) = 0.9200
    v_c2   = _unit_vec(2)
    v_dis  = _unit_vec(5)

    rows = [
        {"fingerprint_id": 10, "vector": json.dumps(v_base), "ban_type": "ip", "ban_value": "a" * 64, "score": 1.5, "colour_level": "yellow", "reporting_sites": "a.org"},
        {"fingerprint_id": 20, "vector": v_base,             "ban_type": "ip", "ban_value": "b" * 64, "score": 6.5, "colour_level": "red",    "reporting_sites": "b.org"},
        {"fingerprint_id": 30, "vector": v_85,               "ban_type": "ip", "ban_value": "c" * 64, "score": 3.2, "colour_level": "orange", "reporting_sites": "c.org"},
        {"fingerprint_id": 40, "vector": v_c2,               "ban_type": "ip", "ban_value": "d" * 64, "score": 1.2, "colour_level": "yellow", "reporting_sites": "d.org"},
        {"fingerprint_id": 50, "vector": v_92,               "ban_type": "ip", "ban_value": "e" * 64, "score": 3.5, "colour_level": "orange", "reporting_sites": "e.org"},
        {"fingerprint_id": 60, "vector": v_dis,              "ban_type": "ip", "ban_value": "f" * 64, "score": 2.0, "colour_level": "yellow", "reporting_sites": "f.org"},
        {"fingerprint_id": 70, "vector": v_dis,              "ban_type": "ip", "ban_value": "0" * 64, "score": 2.0, "colour_level": "yellow", "reporting_sites": "g.org"},
        {"fingerprint_id": 80, "vector": v_base,             "ban_type": "ip", "ban_value": "1" * 64, "score": 0.0, "colour_level": "green",  "reporting_sites": "h.org"},
    ]

    res = core_module.analyze_central_vectors(rows, dismissed_pairs=[[70, 60]])
    assert res["vector_count"] == 7  # green excluded
    assert len(res["clusters"]) == 2

    c1, c2 = res["clusters"][0], res["clusters"][1]
    assert c1["cluster_id"] == 10
    assert c1["member_ids"] == "10,20,30"
    assert c1["max_sim"] == 1.0
    assert c1["confidence"] == "STRONG MATCH"
    assert c1["target_colour"] == "red"

    assert c2["cluster_id"] == 40
    assert c2["member_ids"] == "40,50"
    assert c2["confidence"] == "LIKELY MATCH"
    assert c2["target_colour"] == "orange"

    outcomes_by_fp = {o["fingerprint_id"]: o for o in res["outcomes"]}
    assert set(outcomes_by_fp.keys()) == {10, 20, 30, 40, 50}
    assert outcomes_by_fp[30]["style_confidence"] == "POSSIBLE MATCH"
    assert outcomes_by_fp[10]["style_confidence"] == "STRONG MATCH"


def test_pull_and_upload_central_routes_and_redirect_refusal():
    calls = []

    class FakeResp:
        def __init__(self, payload: dict):
            self._raw = json.dumps(payload).encode("utf-8")

        def read(self):
            return self._raw

        def __enter__(self):
            return self

        def __exit__(self, *args):
            return False

    class FakeOpener:
        def open(self, req, timeout=20):
            calls.append({
                "url":    req.full_url,
                "method": req.get_method(),
                "auth":   req.get_header("Authorization"),
                "body":   json.loads(req.data.decode("utf-8")) if req.data else None,
            })
            parsed = urllib.parse.urlsplit(req.full_url)
            q = dict(urllib.parse.parse_qsl(parsed.query))
            if q.get("route") == "admin/style/vectors":
                if q.get("after_id") == "1":
                    return FakeResp({
                        "ok": True,
                        "vectors": [{"fingerprint_id": 2, "vector": [0.1] * 25, "colour_level": "red"}],
                        "has_more": False,
                        "next_after_id": 2,
                    })
                return FakeResp({
                    "ok": True,
                    "vectors": [{"fingerprint_id": 1, "vector": [0.1] * 25, "colour_level": "yellow"}],
                    "dismissed_pairs": [[10, 11]],
                    "has_more": True,
                    "next_after_id": 1,
                })
            if q.get("route") == "admin/style/results":
                return FakeResp({"ok": True, "stored_clusters": 1})
            raise AssertionError(f"Unexpected route: {q}")

    opener = FakeOpener()
    pulled = core_module.pull_central_vectors(
        "https://smackcentral.example/sc-enemy-api.php",
        "admin_tok_xyz",
        page_limit=1,
        opener=opener,
    )
    assert [v["fingerprint_id"] for v in pulled["vectors"]] == [1, 2]
    assert pulled["dismissed_pairs"] == [[10, 11]]
    assert len(calls) == 2
    assert calls[0]["auth"] == "Bearer admin_tok_xyz"

    up = core_module.upload_central_results(
        "https://smackcentral.example/sc-enemy-api.php",
        "admin_tok_xyz",
        clusters=[{"cluster_id": 1}],
        outcomes=[{"fingerprint_id": 1, "style_confidence": "STRONG MATCH"}],
        dismiss_pairs=[[9, 3]],
        escalations=[{"fingerprint_ids": [1, 2], "target_colour": "red"}],
        opener=opener,
    )
    assert up["ok"] is True
    assert calls[2]["method"] == "POST"
    assert calls[2]["body"]["dismiss_pairs"] == [[3, 9]]  # normalised order

    # Redirect handler must refuse to follow redirects when carrying Bearer token
    handler = core_module._RefuseRedirect()
    req = urllib.request.Request("https://smackcentral.example/sc-enemy-api.php")
    with pytest.raises(urllib.error.HTTPError, match="redirect refused"):
        handler.redirect_request(req, io.BytesIO(), 302, "Found", {}, "https://evil.example/")
# ===== SNAPSMACK EOF =====
