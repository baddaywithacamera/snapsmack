"""Desktop CLI contract and real local workflows. SNAPSMACK_EOF_HEADER."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path[:0] = [str(ROOT / "tools/_shared"), str(ROOT / "tools/hub")]
import snap_cli
import snap_hq_gate
import snap_session_gate
import snap_stepup


def adapter(folder):
    spec = importlib.util.spec_from_file_location("test_cli_" + folder.replace("-", "_"), ROOT / "tools" / folder / "app_cli.py")
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    return module


class DesktopCLI(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(ignore_cleanup_errors=True)
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.env = patch.dict(os.environ, {
            "SNAPSMACK_HOME": str(self.root / "home"),
            "SNAPSMACK_DEVICE_ID": "device-alpha",
        })
        self.env.start(); self.addCleanup(self.env.stop)
        snap_hq_gate.set_cli_settings(cli_enabled=True, stepup_policy=snap_hq_gate.POLICY_30_DAYS)

    def seed_stepup_trust(self, *, device_id="device-alpha", now=None, days=30):
        ok_auth = lambda base_url, route, api_key, u, p, t: snap_stepup.AuthResult(
            ok=True, message="Authorized", username=u, totp_window_days=days
        )
        return snap_session_gate.authorize(
            "https://fixture.invalid", "gyss/authorize", "test-key",
            "sean", "correct-password", "123456",
            now=now, device_id=device_id, requester=ok_auth,
        )

    def invoke(self, module, argv):
        stdout = io.StringIO()
        with contextlib.redirect_stdout(stdout):
            code = module.main(argv)
        return code, json.loads(stdout.getvalue())

    def save(self, name, data):
        path = self.root / name
        path.write_text(json.dumps(data), encoding="utf-8")
        return str(path)

    def test_all_python_launchers_dispatch_without_gui(self):
        entries = ["hub/run_slapper_qt.py", "gyss/gyss_qt.py", "sybu/sybu_launcher.py",
                   "coldsnap/coldsnap_qt_launcher.py", "shots-fired/main.py",
                   "smack-up-your-backup/suyb_launcher.py", "smackpress/smackpress_qt_launcher.py"]
        for entry in entries:
            with self.subTest(entry=entry):
                output = self.root / (Path(entry).parent.name + ".json")
                result = subprocess.run([sys.executable, str(ROOT / "tools" / entry), "--cli", "--result", str(output), "capabilities"],
                                        capture_output=True, text=True, timeout=20)
                self.assertEqual(result.returncode, 0, result.stderr)
                payload = json.loads(output.read_text(encoding="utf-8"))
                self.assertTrue(payload["ok"])
                self.assertFalse(payload["data"]["paid_ai_fallback"])

    def test_enrichment_dry_run_persist_reuse_and_no_provider(self):
        cli = adapter("gyss")
        image = self.root / "image.bin"; image.write_bytes(b"unique image bytes")
        bundle = {"detailed_description": "Retained description", "tags": ["prairie"],
                  "sensitive_content": {"assessment": "uncertain", "reasons": ["medical"]},
                  "colors": ["#112233"]}
        payload = self.save("findings.json", bundle)
        args = ["enrichment-import", "--site", "https://fixture.invalid", "--image", str(image), "--input", payload]
        with patch("urllib.request.urlopen", side_effect=AssertionError("Network forbidden")):
            code, planned = self.invoke(cli, args)
            self.assertEqual(code, 0); self.assertEqual(planned["data"]["status"], "planned")
            self.assertFalse(snap_cli.local_database("https://fixture.invalid").exists())
            code, saved = self.invoke(cli, args + ["--apply"])
            self.assertEqual(code, 0); self.assertEqual(saved["data"]["status"], "stored-locally")
            code, reused = self.invoke(cli, args + ["--apply"])
            self.assertEqual(reused["data"]["status"], "reused")
            code, rows = self.invoke(cli, ["enrichment-list", "--site", "https://fixture.invalid"])
            row = rows["data"]["items"][0]
            self.assertEqual(row["bundle"], bundle); self.assertEqual(row["accepted"], {})
        Path(payload).write_text('{"tags":["different"]}')
        code, result = self.invoke(cli, args + ["--apply"])
        self.assertNotEqual(code, 0)
        self.assertIn("preserve history", result["error"]["message"])

    def test_result_file_cannot_overwrite_input_or_hardlink(self):
        cli = adapter("hub")
        source = self.save("recipe.json", {"name": "TEST", "adjustments": {"exposure": 0.2}})
        before = Path(source).read_bytes()
        alias = self.root / "alias.json"; os.link(source, alias)
        for result in (source, str(alias)):
            code, payload = self.invoke(cli, ["lewk-validate", "--input", source, "--result", result])
            self.assertNotEqual(code, 0)
            self.assertEqual(Path(source).read_bytes(), before)

    def test_coldsnap_real_draft_revision_conflict(self):
        cli = adapter("coldsnap")
        code, created = self.invoke(cli, ["draft-create", "--site", "fixture.invalid", "--title", "First", "--apply"])
        self.assertEqual(code, 0)
        draft = created["data"]
        payload = self.save("draft.json", {"title": "Second", "blocks": [{"type": "para", "text": "hello"}]})
        args = ["draft-save", "--site", "fixture.invalid", "--id", draft["draft_uuid"], "--input", payload,
                "--expected-revision", str(draft["revision"]), "--apply"]
        code, saved = self.invoke(cli, args); self.assertEqual(code, 0)
        self.assertEqual(saved["data"]["title"], "Second")
        code, conflict = self.invoke(cli, args); self.assertNotEqual(code, 0)
        self.assertIn("conflict", conflict["error"]["message"])

    def test_slapper_create_edit_render_and_saved_layer(self):
        from PIL import Image
        cli = adapter("hub")
        image = self.root / "original.png"; Image.new("RGB", (160, 100), (80, 120, 160)).save(image)
        original = image.read_bytes()
        first, second, third = [str(self.root / name) for name in ("first.slapper", "second.slapper", "third.slapper")]
        with patch("snap_device_auth.require_full"):
            code, result = self.invoke(cli, ["project-create", "--image", str(image), "--output", first])
            self.assertEqual(code, 0, result)
            adjustments = self.save("adjust.json", {"contrast": 14})
            code, result = self.invoke(cli, ["project-edit", "--project", first, "--input", adjustments, "--output", second])
            self.assertEqual(code, 0, result)
            adjustments2 = self.save("adjust2.json", {"exposure": 0.25})
            code, result = self.invoke(cli, ["project-edit", "--project", second, "--input", adjustments2, "--output", third])
            self.assertEqual(code, 0, result)
            code, inspected = self.invoke(cli, ["project-inspect", "--project", third])
            self.assertEqual(inspected["data"]["adjustments"]["contrast"], 14)
            self.assertEqual(inspected["data"]["adjustments"]["exposure"], .25)
            layered = str(self.root / "layered.slapper")
            code, result = self.invoke(cli, ["apply-lewk", "--project", third, "--builtin", "clean-slate", "--output", layered])
            self.assertEqual(code, 0, result)
            preview = str(self.root / "preview.png")
            code, result = self.invoke(cli, ["render", "--project", layered, "--output", preview])
            self.assertEqual(code, 0, result)
            with Image.open(preview) as rendered:
                self.assertEqual(rendered.size, (160, 100))
                self.assertNotEqual(rendered.getpixel((80, 50)), (80, 120, 160))
            self.assertEqual(image.read_bytes(), original)
            code, result = self.invoke(cli, ["render", "--project", layered, "--output", str(image), "--overwrite"])
            self.assertNotEqual(code, 0); self.assertEqual(image.read_bytes(), original)
            code, data = self.invoke(cli, ["project-inspect", "--project", layered])
            self.assertEqual(len(data["data"]["layers"]), 1)

    def test_slapper_authorization_is_not_bypassed(self):
        cli = adapter("hub")
        data = self.save("lewk.json", {"name": "TEST", "adjustments": {"exposure": 0.2}})
        output = self.root / "safe.lewk"
        snap_hq_gate.set_cli_settings(cli_enabled=False)
        code, result = self.invoke(cli, ["lewk-save", "--input", data, "--output", str(output)])
        self.assertEqual(code, 3); self.assertFalse(output.exists())

    def test_gyss_dry_run_and_partial_failure_exit(self):
        cli = adapter("gyss")
        payload = self.save("updates.json", {"updates": [{"id": 12, "expected_modified_at": "2026-10-26 12:00:00", "hashtags": "#prairie"}]})
        sys.path.insert(0, str(ROOT / "tools/gyss"))
        self.seed_stepup_trust()
        with patch("snap_cli.profile", return_value={"site_url": "https://fixture.invalid", "api_key": "test"}), patch("gyss_api.API.batch", return_value={"applied": 0, "conflicts": [{"id": 12}], "failed": []}) as batch:
            code, result = self.invoke(cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", payload])
            self.assertEqual(code, 0); batch.assert_not_called()
            code, result = self.invoke(cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", payload, "--apply"])
            self.assertNotEqual(code, 0); self.assertEqual(result["error"]["details"]["conflicts"][0]["id"], 12)

    def test_local_images_pagination_and_no_false_empty_page(self):
        import snap_library
        for index in range(5):
            snap_library.upsert_asset("fixture.invalid", {"asset_id": str(index), "orig_name": f"{index}.png"})
        cli = adapter("gyss")
        code, first = self.invoke(cli, ["images", "--site", "fixture.invalid", "--limit", "3"])
        self.assertEqual(len(first["data"]["items"]), 3)
        code, second = self.invoke(cli, ["images", "--site", "fixture.invalid", "--limit", "3", "--after", first["data"]["next_after"]])
        self.assertEqual(len(second["data"]["items"]), 2); self.assertIsNone(second["data"]["next_after"])

    def test_coldsnap_actual_desktop_batch_round_trip(self):
        sys.path.insert(0, str(ROOT / "tools/coldsnap"))
        cli = adapter("coldsnap")
        root = str(self.root / "sessions")
        code, batch = self.invoke(cli, ["batch-create", "--root", root, "--name", "CLI batch", "--mode", "smacktalk", "--apply"])
        self.assertEqual(code, 0, batch)
        batch_id = batch["data"]["session_id"]
        payload = self.save("post.json", {"title": "A draft", "caption": "<p>From the assistant.</p>"})
        code, created = self.invoke(cli, ["batch-draft-create", "--root", root, "--batch", batch_id, "--input", payload, "--apply"])
        self.assertEqual(code, 0, created)
        import sumna_offline as O
        session = O.SessionStore(root).load(batch_id)
        actual = session.list_drafts()[0]
        self.assertEqual(actual.title, "A draft"); self.assertEqual(actual.caption, "<p>From the assistant.</p>")
        code, inspected = self.invoke(cli, ["batch-inspect", "--root", root, "--batch", batch_id])
        draft = inspected["data"]["drafts"][0]
        payload2 = self.save("post2.json", {"title": "Edited"})
        args = ["batch-draft-update", "--root", root, "--batch", batch_id, "--id", draft["draft_id"],
                "--expected-sha256", draft["cli_sha256"], "--input", payload2, "--apply"]
        self.assertEqual(self.invoke(cli, args)[0], 0)
        self.assertNotEqual(self.invoke(cli, args)[0], 0)

    def test_sybu_actual_recovery_queue_round_trip(self):
        sys.path.insert(0, str(ROOT / "tools/sybu"))
        cli = adapter("sybu")
        folder = self.root / "photos"; folder.mkdir(); (folder / "photo.jpg").write_bytes(b"fixture")
        state = self.root / "recovery"; state.mkdir()
        payload = self.save("metadata.json", {"items": [{"file": "photo.jpg", "title": "Prairie", "tags": "#prairie", "alt": "A field"}]})
        args = ["queue-import", "--folder", str(folder), "--recovery-dir", str(state), "--input", payload]
        self.assertEqual(self.invoke(cli, args)[0], 0); self.assertEqual(list(state.iterdir()), [])
        self.assertEqual(self.invoke(cli, args + ["--apply"])[0], 0)
        import recovery
        from manifest_parser import ManifestEntry
        store = recovery.RecoveryStore(str(folder)); store.path = str(next(state.iterdir())); store.load()
        entry = ManifestEntry(file="photo.jpg")
        self.assertEqual(store.restore_into([entry]), 1)
        self.assertEqual(entry.title, "Prairie"); self.assertEqual(entry.alt, "A field")

    def test_non_finite_numbers_and_unknown_usage_preserve_inputs(self):
        path = self.root / "infinite.json"; path.write_text('{"x":1e999}')
        with self.assertRaises(ValueError):
            snap_cli.object_json(path)
        cli = adapter("hub"); before = path.read_bytes()
        with contextlib.redirect_stderr(io.StringIO()):
            code, result = self.invoke(cli, ["lewk-validate", "--input", str(path), "--bad-option", "--result", str(path)])
        self.assertNotEqual(code, 0); self.assertEqual(path.read_bytes(), before)

    def test_shots_fired_result_file_serializes_calendar_dates(self):
        from datetime import datetime
        sys.path.insert(0, str(ROOT / "tools/shots-fired"))
        import schedule_client
        cli = adapter("shots-fired")
        post = schedule_client.ScheduledPost(12, "Scheduled", datetime(2026, 11, 1, 10), "Fixture", "https://fixture.invalid")
        reply = schedule_client.ListResult(schedule_client.ApiStatus.OK, [post])
        result = self.root / "calendar-result.json"
        with patch("snap_cli.profile", return_value={"name": "Fixture", "site_url": "https://fixture.invalid", "api_key": "test"}), patch.object(schedule_client, "list_scheduled", return_value=reply):
            code = cli.main(["scheduled", "--site", "https://fixture.invalid", "--result", str(result)])
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(result.read_text(encoding="utf-8"))["data"]["items"][0]["img_date"], "2026-11-01T10:00:00")

    def test_suyb_does_not_backup_without_apply(self):
        import types
        cli = adapter("smack-up-your-backup")
        backend = types.ModuleType("headless")
        backend.run_backup_all = __import__('unittest.mock', fromlist=['Mock']).Mock(return_value=0)
        with patch.dict(sys.modules, {"headless": backend}):
            self.assertEqual(self.invoke(cli, ["backup-all"])[0], 0)
            backend.run_backup_all.assert_not_called()
            self.assertEqual(self.invoke(cli, ["backup-all", "--apply"])[0], 0)
            backend.run_backup_all.assert_called_once_with(silent=True)

    def test_smackpress_tracking_uses_selected_database(self):
        sys.path.insert(0, str(ROOT / "tools/smackpress"))
        from smackpress import config
        database = self.root / "smackpress.db"
        with sqlite3.connect(database) as conn:
            conn.executescript(config._SCHEMA)
            conn.execute("INSERT INTO posts(wp_id,wp_title) VALUES(12,'Fixture')")
        cli = adapter("smackpress")
        code, result = self.invoke(cli, ["tracked", "--db", str(database)])
        self.assertEqual(code, 0); self.assertEqual(result["data"]["items"][0]["wp_id"], 12)
        with patch.object(config, "DB_PATH", database), patch.object(config, "DB_DIR", self.root):
            self.assertEqual(self.invoke(cli, ["set-note", "--db", str(database), "--id", "12", "--note", "Reviewed", "--apply"])[0], 0)
        with sqlite3.connect(database) as conn:
            self.assertEqual(conn.execute("SELECT notes FROM posts WHERE wp_id=12").fetchone()[0], "Reviewed")

    def test_cli_refused_when_hq_switch_is_off_by_default(self):
        """1. Refused when the SNAP HQ switch is off (including fresh default state)."""
        cfg_file = Path(snap_hq_gate._cli_config_path())
        if cfg_file.exists():
            cfg_file.unlink()
        self.assertFalse(snap_hq_gate.cli_enabled())

        gyss_cli = adapter("gyss")
        coldsnap_cli = adapter("coldsnap")
        shots_cli = adapter("shots-fired")

        code, res = self.invoke(coldsnap_cli, ["draft-create", "--site", "fixture.invalid", "--title", "Blocked", "--apply"])
        self.assertEqual(code, 3)
        self.assertIn("turned off in SNAP HQ", res["error"]["message"])

        payload = self.save("upd.json", {"updates": [{"id": 1, "expected_modified_at": "2026-11-20 10:00:00", "sort_order": 2}]})
        code, res = self.invoke(gyss_cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", payload, "--apply"])
        self.assertEqual(code, 3)
        self.assertIn("turned off in SNAP HQ", res["error"]["message"])

        code, res = self.invoke(shots_cli, ["reschedule", "--site", "https://fixture.invalid", "--id", "5", "--date", "2026-12-01 09:00:00", "--apply"])
        self.assertEqual(code, 3)

        sys.path.insert(0, str(ROOT / "tools/smackpress"))
        import repair_existing
        with self.assertRaises(PermissionError):
            repair_existing.main(["--state-dir", str(self.root), "--destination", "https://fixture.invalid", "--apply"])

    def test_live_site_writes_refused_with_no_stepup(self):
        """2. Refused with no step-up while local-only commands need only the HQ switch."""
        sys.path.insert(0, str(ROOT / "tools/gyss"))
        sys.path.insert(0, str(ROOT / "tools/shots-fired"))
        sys.path.insert(0, str(ROOT / "tools/smackpress"))
        import schedule_client
        import repair_existing

        # Local-only command succeeds with only the HQ switch ON and no step-up:
        coldsnap_cli = adapter("coldsnap")
        code, local_res = self.invoke(coldsnap_cli, ["draft-create", "--site", "fixture.invalid", "--title", "Local OK", "--apply"])
        self.assertEqual(code, 0, local_res)

        gyss_cli = adapter("gyss")
        shots_cli = adapter("shots-fired")
        payload = self.save("upd.json", {"updates": [{"id": 1, "expected_modified_at": "2026-11-20 10:00:00", "sort_order": 2}]})

        with patch("snap_cli.profile", return_value={"name": "Fix", "site_url": "https://fixture.invalid", "api_key": "test"}), \
             patch("gyss_api.API.batch") as mock_batch, \
             patch("gyss_api.API.call") as mock_call, \
             patch.object(schedule_client, "reschedule") as mock_resched:
            code, res = self.invoke(gyss_cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", payload, "--apply"])
            self.assertEqual(code, 3)
            self.assertIn("Step-up", res["error"]["message"])
            mock_batch.assert_not_called()

            code, res = self.invoke(gyss_cli, ["enrichment-sync", "--site", "https://fixture.invalid", "--apply"])
            self.assertEqual(code, 3)
            self.assertIn("Step-up", res["error"]["message"])
            mock_call.assert_not_called()

            code, res = self.invoke(shots_cli, ["reschedule", "--site", "https://fixture.invalid", "--id", "5", "--date", "2026-12-01 09:00:00", "--apply"])
            self.assertEqual(code, 3)
            self.assertIn("Step-up", res["error"]["message"])
            mock_resched.assert_not_called()

        with patch("snap_connections.resolve", return_value={"site_url": "https://fixture.invalid", "api_key": "test"}), \
             patch.object(repair_existing, "SmacktalkPoster") as mock_poster:
            with self.assertRaises(PermissionError):
                repair_existing.main(["--state-dir", str(self.root), "--destination", "https://fixture.invalid", "--apply"])
            mock_poster.assert_not_called()

    def test_post_and_sort_accepted_within_30_day_trust_and_every_use_policy(self):
        """3. Accepted within 30-day trust for post/sort/reschedule/sync; refused under every_use without fresh TOTP."""
        sys.path.insert(0, str(ROOT / "tools/gyss"))
        sys.path.insert(0, str(ROOT / "tools/shots-fired"))
        sys.path.insert(0, str(ROOT / "tools/smackpress"))
        import schedule_client
        import repair_existing

        self.seed_stepup_trust(device_id="device-alpha")
        gyss_cli = adapter("gyss")
        shots_cli = adapter("shots-fired")
        sort_payload = self.save("sort.json", {"updates": [{"id": 1, "expected_modified_at": "2026-11-20 10:00:00", "sort_order": 3}]})

        with patch("snap_cli.profile", return_value={"name": "Fix", "site_url": "https://fixture.invalid", "api_key": "test"}), \
             patch("gyss_api.API.batch", return_value={"ok": True, "applied": 1, "conflicts": [], "failed": []}) as mock_batch, \
             patch.object(schedule_client, "reschedule", return_value=schedule_client.RescheduleResult(schedule_client.ApiStatus.OK, "rescheduled")) as mock_resched:
            code, res = self.invoke(gyss_cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", sort_payload, "--apply"])
            self.assertEqual(code, 0, res)
            mock_batch.assert_called_once()

            code, res = self.invoke(gyss_cli, ["enrichment-sync", "--site", "https://fixture.invalid", "--apply"])
            self.assertEqual(code, 0, res)

            code, res = self.invoke(shots_cli, ["reschedule", "--site", "https://fixture.invalid", "--id", "5", "--date", "2026-12-01 09:00:00", "--apply"])
            self.assertEqual(code, 0, res)
            mock_resched.assert_called_once()

        with patch("snap_connections.resolve", return_value={"site_url": "https://fixture.invalid", "api_key": "test"}), \
             patch.object(repair_existing, "_all_source", return_value=[]), \
             patch.object(repair_existing.db, "get_all_posts", return_value=[]), \
             patch.object(repair_existing, "SmacktalkPoster") as mock_poster:
            mock_poster.return_value.list_posts.return_value = []
            mock_poster.return_value.list_pages.return_value = []
            rc = repair_existing.main(["--state-dir", str(self.root), "--destination", "https://fixture.invalid", "--apply"])
            self.assertEqual(rc, 0)

        # If owner switches SNAP HQ policy to every_use, cached 30-day trust is not enough:
        snap_hq_gate.set_cli_settings(cli_enabled=True, stepup_policy=snap_hq_gate.POLICY_EVERY_USE)
        with patch("snap_cli.profile", return_value={"name": "Fix", "site_url": "https://fixture.invalid", "api_key": "test"}), \
             patch("gyss_api.API.batch") as mock_batch:
            code, res = self.invoke(gyss_cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", sort_payload, "--apply"])
            self.assertEqual(code, 3)
            self.assertIn("every live-site write", res["error"]["message"])
            mock_batch.assert_not_called()

    def test_delete_always_refused_without_fresh_totp_even_inside_30_day_trust(self):
        """4. Delete refused without fresh TOTP every time, even with active 30-day device trust."""
        self.seed_stepup_trust(device_id="device-alpha")

        with self.assertRaises(PermissionError) as ctx:
            snap_session_gate.require_stepup_for_write(
                "https://fixture.invalid", "gyss/authorize", "test-key",
                action="delete",
            )
        self.assertIn("Deleting always requires a fresh password and 2FA TOTP code", str(ctx.exception))

        calls = []
        def fake_requester(base_url, route, api_key, u, p, t):
            calls.append((u, p, t))
            return snap_stepup.AuthResult(ok=True, message="Authorized", username=u)

        state = snap_session_gate.require_stepup_for_write(
            "https://fixture.invalid", "gyss/authorize", "test-key",
            action="delete",
            username="sean", password="secret", totp_code="654321",
            requester=fake_requester,
        )
        self.assertEqual(calls, [("sean", "secret", "654321")])
        self.assertEqual(state.username, "sean")

    def test_new_or_different_device_forces_fresh_totp(self):
        """5. A new or different device forces fresh TOTP even within the 30-day window."""
        sys.path.insert(0, str(ROOT / "tools/gyss"))
        self.seed_stepup_trust(device_id="device-alpha")
        gyss_cli = adapter("gyss")
        sort_payload = self.save("sort.json", {"updates": [{"id": 1, "expected_modified_at": "2026-11-20 10:00:00", "sort_order": 4}]})

        with patch.dict(os.environ, {"SNAPSMACK_DEVICE_ID": "device-beta"}), \
             patch("snap_cli.profile", return_value={"name": "Fix", "site_url": "https://fixture.invalid", "api_key": "test"}), \
             patch("gyss_api.API.batch", return_value={"ok": True, "applied": 1, "conflicts": [], "failed": []}) as mock_batch:
            code, res = self.invoke(gyss_cli, ["metadata-update", "--site", "https://fixture.invalid", "--input", sort_payload, "--apply"])
            self.assertEqual(code, 3)
            self.assertIn("New or different device", res["error"]["message"])
            mock_batch.assert_not_called()

            with patch("snap_stepup.request_authorization", return_value=snap_stepup.AuthResult(ok=True, message="OK", username="sean")) as mock_auth:
                code, res = self.invoke(gyss_cli, [
                    "metadata-update", "--site", "https://fixture.invalid", "--input", sort_payload, "--apply",
                    "--stepup-user", "sean", "--stepup-password", "pw", "--stepup-totp", "999888",
                ])
                self.assertEqual(code, 0, res)
                mock_auth.assert_called_once()
                mock_batch.assert_called_once()
                self.assertEqual(snap_session_gate.load().device_id, "device-beta")


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
