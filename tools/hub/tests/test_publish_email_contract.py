import os
import sys
import threading

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from slapper_qt.smackthemup_dialog import email_share_url, safe_public_url


def test_email_uses_user_mail_service_without_recipient_or_tracking():
    value = email_share_url("A title & more", "https://photos.example/p/one?x=1")
    assert value.startswith("mailto:?subject=")
    assert "to=" not in value.lower()
    assert "utm_" not in value.lower()
    assert "photos.example" in value


def test_server_result_link_allows_only_public_web_schemes():
    assert safe_public_url("https://photos.example/p/1")
    assert safe_public_url("http://photos.example/p/1")
    assert safe_public_url("file:///C:/Windows/System32/calc.exe") == ""
    assert safe_public_url("custom-handler:payload") == ""


def test_export_worker_runs_render_and_write_off_ui_thread(tmp_path, monkeypatch):
    from PIL import Image
    import editor_engine
    from slapper_qt.editor_window import _ExportJob

    source = tmp_path / "source.png"
    target = tmp_path / "output.jpg"
    Image.new("RGB", (40, 30), (10, 20, 30)).save(source)
    document = editor_engine.EditorDocument(source)
    ui_thread = threading.get_ident()
    called = []

    def fake_export(path, **_kwargs):
        called.append(threading.get_ident())
        Image.new("RGB", (40, 30)).save(path)

    monkeypatch.setattr(document, "export", fake_export)
    job = _ExportJob(1, document, str(target), 90, "", False)
    worker = threading.Thread(target=job.run)
    worker.start(); worker.join()
    assert target.is_file()
    assert called == [worker.ident]
    assert called[0] != ui_thread


def test_publish_preparation_worker_runs_off_ui_thread(tmp_path, monkeypatch):
    """Preparing a derivative must never happen on the thread drawing the UI.

    This used to patch publishing_contract.prepare and assert the job called
    it. Commit 53efe7b4 moved preparation out of the thread and into a
    separate process -- a stronger answer to the same question, since RAW work
    can no longer starve Qt even inside one interpreter -- so prepare() is not
    called here at all any more and the patched stub recorded nothing. The
    contract under test is unchanged; what carries it is now the subprocess.
    """
    import json
    from PIL import Image
    import editor_engine
    from slapper_qt import editor_window
    from slapper_qt.editor_window import _PublishPrepareJob

    source = tmp_path / "source.png"
    Image.new("RGB", (40, 30), (10, 20, 30)).save(source)
    document = editor_engine.EditorDocument(source)
    ui_thread = threading.get_ident()
    called = []
    target = tmp_path / "web.jpg"
    manifest_path = tmp_path / "web.json"

    def fake_run(command, **_kwargs):
        called.append(threading.get_ident())
        assert "--blog-copy-worker" in command, command
        with open(command[-1], encoding="utf-8") as handle:
            job_value = json.load(handle)
        with open(job_value["result_path"], "w", encoding="utf-8") as handle:
            json.dump({"ok": True, "target": str(target),
                       "manifest_path": str(manifest_path),
                       "manifest": {}}, handle)
        return type("Done", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    monkeypatch.setattr(editor_window.subprocess, "run", fake_run)
    failures = []
    job = _PublishPrepareJob(1, document, {}, "", str(tmp_path), "local")
    job.signals.failed.connect(lambda _token, message: failures.append(message))
    worker = threading.Thread(target=job.run)
    worker.start(); worker.join()
    assert failures == []
    assert called == [worker.ident]
    assert called[0] != ui_thread


# ===== SNAPSMACK EOF =====
