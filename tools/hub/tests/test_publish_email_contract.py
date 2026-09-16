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
    from PIL import Image
    import editor_engine
    from slapper_qt import publishing_contract
    from slapper_qt.editor_window import _PublishPrepareJob

    source = tmp_path / "source.png"
    Image.new("RGB", (40, 30), (10, 20, 30)).save(source)
    document = editor_engine.EditorDocument(source)
    ui_thread = threading.get_ident()
    called = []

    def fake_prepare(*_args, **_kwargs):
        called.append(threading.get_ident())
        return str(tmp_path / "web.jpg"), str(tmp_path / "web.json"), {}

    monkeypatch.setattr(publishing_contract, "prepare", fake_prepare)
    job = _PublishPrepareJob(1, document, {}, "", str(tmp_path), "local")
    worker = threading.Thread(target=job.run)
    worker.start(); worker.join()
    assert called == [worker.ident]
    assert called[0] != ui_thread


# ===== SNAPSMACK EOF =====
