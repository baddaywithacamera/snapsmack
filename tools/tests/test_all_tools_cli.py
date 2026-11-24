"""Real-process CLI execution tests for all 9 newly wired desktop & import tools.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import os
import subprocess
import sys
from pathlib import Path

import pytest

TOOLS_DIR = Path(__file__).resolve().parents[1]

LAUNCHERS = [
    ("blogger-flogger", TOOLS_DIR / "blogger-flogger" / "app.py"),
    ("ghosted", TOOLS_DIR / "ghosted" / "app.py"),
    ("unzucker", TOOLS_DIR / "unzucker" / "main.py"),
    ("flkr-fckr", TOOLS_DIR / "flkr-fckr" / "flkrfckr_launcher.py"),
    ("fed-up", TOOLS_DIR / "fed-up" / "app.py"),
    ("smack-your-mouth", TOOLS_DIR / "smack-your-mouth" / "main.py"),
    ("cronometer", TOOLS_DIR / "cronometer" / "cronometer.py"),
    ("instantreplay", TOOLS_DIR / "instantreplay" / "instantreplay_qt_launcher.py"),
    ("keysplease", TOOLS_DIR / "keysplease" / "keysplease_qt_launcher.py"),
]


def _env_with_cli(tmp_path: Path, *, enabled: bool, trusted: bool = True, policy: str = "30_days") -> dict:
    import time
    sys.path.insert(0, str(TOOLS_DIR / "_shared"))
    import snap_session_gate

    home = tmp_path / "snapsmack_home"
    cfg = home / "config_files" / "snap-hq" / "desktop_cli.json"
    cfg.parent.mkdir(parents=True, exist_ok=True)
    cfg.write_text(json.dumps({"cli_enabled": enabled, "stepup_policy": policy}), encoding="utf-8")
    if trusted:
        sess = home / "config_files" / "snap-hq" / "desktop_session.json"
        now = int(time.time())
        sess.write_text(json.dumps({
            "schema": 1,
            "username": "sean",
            "login_at": now,
            "totp_at": now,
            "totp_window_days": 30,
            "last_online_ok": now,
            "totp_required_on_reconnect": False,
            "device_id": snap_session_gate.current_device_id(),
        }), encoding="utf-8")
    env = os.environ.copy()
    env["SNAPSMACK_HOME"] = str(home)
    env.pop("PYTEST_CURRENT_TEST", None)
    return env


def _run_cli(launcher: Path, args: list[str], env: dict) -> tuple[int, dict]:
    proc = subprocess.run(
        [sys.executable, str(launcher), "--cli", *args],
        cwd=str(launcher.parent),
        env=env,
        capture_output=True,
        text=True,
        encoding="utf-8",
        timeout=20,
    )
    return proc.returncode, json.loads(proc.stdout.strip())


@pytest.mark.parametrize("app_name,launcher", LAUNCHERS)
def test_launcher_capabilities_and_hq_gate(tmp_path, app_name, launcher):
    # Disabled in SNAP HQ -> refused (exit code 3)
    rc_off, env_off = _run_cli(launcher, ["capabilities"], _env_with_cli(tmp_path / "off", enabled=False))
    assert rc_off == 3
    assert env_off["ok"] is False
    assert env_off["error"]["code"] == "permission"

    # Enabled in SNAP HQ -> returns capabilities envelope + --result file works
    env_on = _env_with_cli(tmp_path / "on", enabled=True)
    rc_on, data_on = _run_cli(launcher, ["capabilities"], env_on)
    assert rc_on == 0
    assert data_on["ok"] is True
    assert data_on["app"] == app_name
    assert "capabilities" in data_on["data"]["commands"]


def test_real_engine_commands_execute(tmp_path):
    env = _env_with_cli(tmp_path, enabled=True)

    # 1. BLOGGER FLOGGER inspect-archive
    xml_path = tmp_path / "blog.xml"
    xml_path.write_text(
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:thr="http://purl.org/syndication/thread/1.0">'
        '<title>Test Blog</title><id>tag:blogger.com,1999:blog-1</id>'
        '<entry><id>tag:blogger.com,1999:blog-1.post-1</id>'
        '<category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/blogger/2008/kind#post"/>'
        '<title>Hello</title><published>2026-01-01T00:00:00Z</published><content type="html">Hi</content></entry>'
        '</feed>',
        encoding="utf-8",
    )
    rc, res = _run_cli(TOOLS_DIR / "blogger-flogger" / "app.py", ["inspect-archive", "--input", str(xml_path)], env)
    assert rc == 0 and res["data"]["count"] == 1 and res["data"]["archives"][0]["entry_count"] == 1

    # 2. GHOSTED inspect-archive
    ghost_json = tmp_path / "ghost.json"
    ghost_json.write_text(
        json.dumps({"db": [{"meta": {"version": "5.0.0"}, "data": {"settings": [{"key": "title", "value": "Ghost Blog"}], "posts": [{"id": "1", "title": "Post 1", "slug": "post-1", "type": "post", "status": "published", "html": "<p>Hi</p>", "published_at": "2026-01-01T00:00:00.000Z"}]}}]}),
        encoding="utf-8",
    )
    rc, res = _run_cli(TOOLS_DIR / "ghosted" / "app.py", ["inspect-archive", "--input", str(ghost_json)], env)
    assert rc == 0 and res["data"]["count"] == 1 and res["data"]["archives"][0]["entry_count"] == 1

    # 3. UNZUCKER inspect-export
    ig_dir = tmp_path / "ig_export"
    (ig_dir / "your_instagram_activity" / "content").mkdir(parents=True)
    (ig_dir / "your_instagram_activity" / "content" / "posts_1.json").write_text("[]", encoding="utf-8")
    rc, res = _run_cli(TOOLS_DIR / "unzucker" / "main.py", ["inspect-export", "--input", str(ig_dir)], env)
    assert rc == 0 and res["data"]["post_count"] == 0

    # 4. FLKR FCKR inspect-export
    fl_dir = tmp_path / "flickr_export"
    fl_dir.mkdir()
    rc, res = _run_cli(TOOLS_DIR / "flkr-fckr" / "flkrfckr_launcher.py", ["inspect-export", "--input", str(fl_dir)], env)
    assert rc == 0 and res["data"]["photo_count"] == 0

    # 5. FED UP inspect-archive
    fu_dir = tmp_path / "fedup_archive"
    fu_dir.mkdir()
    rc, res = _run_cli(TOOLS_DIR / "fed-up" / "app.py", ["inspect-archive", "--input", str(fu_dir)], env)
    assert rc == 0 and res["data"]["raw_post_count"] == 0

    # 6. SMACK YOUR MOUTH sessions
    rc, res = _run_cli(TOOLS_DIR / "smack-your-mouth" / "main.py", ["sessions"], env)
    assert rc == 0 and "sessions" in res["data"]

    # 7. CRONOMETER sites
    rc, res = _run_cli(TOOLS_DIR / "cronometer" / "cronometer.py", ["sites"], env)
    assert rc == 0 and "sites" in res["data"]

    # 8. INSTANT REPLAY backups
    rc, res = _run_cli(TOOLS_DIR / "instantreplay" / "instantreplay_qt_launcher.py", ["backups"], env)
    assert rc == 0 and res["data"]["count"] >= 1

    # 9. KEYS PLEASE keys
    rc, res = _run_cli(TOOLS_DIR / "keysplease" / "keysplease_qt_launcher.py", ["keys"], env)
    assert rc == 0 and "keys" in res["data"]

def test_session_gate_stepup_enforcement(tmp_path, monkeypatch):
    sys.path.insert(0, str(TOOLS_DIR / "_shared"))
    import snap_session_gate

    # 1. Missing 30-day device trust -> refused (exit code 3)
    env_untrusted = _env_with_cli(tmp_path / "untrusted", enabled=True, trusted=False)
    rc, res = _run_cli(TOOLS_DIR / "cronometer" / "cronometer.py", ["sites"], env_untrusted)
    assert rc == 3 and res["error"]["code"] == "permission"

    # 2. Policy = every_use -> refused even with 30-day device trust (exit code 3)
    env_every = _env_with_cli(tmp_path / "every", enabled=True, trusted=True, policy="every_use")
    rc2, res2 = _run_cli(TOOLS_DIR / "cronometer" / "cronometer.py", ["sites"], env_every)
    assert rc2 == 3 and res2["error"]["code"] == "permission"

    # 3. Pluggable second factor (e.g. FIDO2/WebAuthn hardware key in SS-1564) satisfies delete without TOTP
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "pluggable"))
    cfg = tmp_path / "pluggable" / "config_files" / "snap-hq" / "desktop_cli.json"
    cfg.parent.mkdir(parents=True, exist_ok=True)
    cfg.write_text(json.dumps({"cli_enabled": True, "stepup_policy": "30_days"}), encoding="utf-8")
    state = snap_session_gate.require_stepup_for_write(
        "", "hub/authorize", "", action="delete",
        second_factor_verifier=lambda: (True, "Hardware key verified"),
    )
    assert state.totp_at > 0


# ===== SNAPSMACK EOF =====
