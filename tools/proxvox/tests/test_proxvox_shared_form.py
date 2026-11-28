"""The shared PROX VOX form runs two operations with one authorization.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

from pathlib import Path
import runpy
import subprocess
import sys
from types import SimpleNamespace
from unittest.mock import Mock
import uuid

import pytest


class Widget:
    def __init__(self, *args, **options):
        self.options = options
        self.disabled = options.get("state") == "disabled"

    def __getattr__(self, _name):
        return lambda *args, **options: None

    def state(self, flags):
        self.disabled = flags == ["disabled"]

    def instate(self, _flags):
        return self.disabled

    def press(self):
        assert not self.disabled
        self.options["command"]()


class Variable:
    def __init__(self, value=""):
        self.value = value

    def get(self):
        return self.value

    def set(self, value):
        self.value = value


class SynchronousThread:
    def __init__(self, *, target, daemon):
        self.target = target

    def start(self):
        self.target()


@pytest.mark.parametrize("scenario", ["revoke", "expire", "invalid_manifest"])
def test_shared_form_preflights_and_reuses_session(monkeypatch, scenario):
    valid_manifest = scenario != "invalid_manifest"
    source = Path(__file__).resolve().parents[1]
    monkeypatch.syspath_prepend(str(source))
    run_form = runpy.run_path(str(source / "authorize_database_tools.pyw"))["run_form"]
    buttons, variables = {}, []

    def button(*args, **options):
        widget = Widget(*args, **options)
        buttons[options["text"]] = widget
        return widget

    def string_var(value=""):
        variable = Variable(value)
        variables.append(variable)
        return variable

    class Window(Widget):
        def after(self, _delay, callback):
            self.poll = callback

        def mainloop(self):
            variables[0].set("fixture")
            variables[1].set("000000")
            buttons["Authorize"].press()
            self.poll()
            assert variables[0].get() == variables[1].get() == ""
            buttons["Restore updater on 23 sites"].press()
            self.poll()
            assert buttons["Authorize"].disabled
            assert buttons["Restore updater on 23 sites"].disabled
            buttons["Preview The Artist"].press()
            self.poll()
            signals.selections.return_value = iter(["publish"])
            self.poll()
            publish.assert_not_called()
            if valid_manifest:
                buttons["Publish SnapSmack.ca"].press()
                self.poll()
            else:
                assert "no verified publication" in variables[2].get()
            assert buttons["Publish SnapSmack.ca"].disabled
            if scenario == "expire":
                client.seconds_remaining.return_value = 0
            else:
                buttons["End session"].press()
            self.poll()
            assert buttons["Preview The Artist"].disabled
            assert not buttons["Authorize"].disabled
            if scenario == "expire":
                assert variables[3].get() == "The PROX VOX session expired."

    signals = SimpleNamespace(selections=Mock(return_value=()))
    client, reader, writer = Mock(), Mock(), Mock()
    client.seconds_remaining.return_value = 18000
    writer.scope.return_value = {"owner_users": ["proxvox@pve"]}
    plan = Mock(return_value=[("fixture",)])
    if not valid_manifest:
        plan.side_effect = RuntimeError("fixture manifest is stale")
    preview, publish = Mock(), Mock(return_value=1)
    repair_flow = Mock()
    repair_flow.install.return_value = ["site.example"]
    repair_flow.package.return_value = b"helper"
    repair_flow.preflight.return_value = [("fixture",)]
    repair_flow.restore.return_value = 1
    monkeypatch.setenv("PROXVOX_PUBLICATION_SHA256", "fixture")
    for name, replacement in {
        "tk": SimpleNamespace(Tk=Window, StringVar=string_var),
        "ttk": SimpleNamespace(Frame=Widget, Label=Widget, Entry=Widget, Button=button),
        "threading": SimpleNamespace(Thread=SynchronousThread),
        "AuditBuffer": Mock(),
        "RootReadBroker": Mock(return_value=reader),
        "ScopedFileWriter": Mock(return_value=writer),
        "WriteClient": Mock(return_value=client),
        "machine_guid": Mock(return_value="fixture"),
        "prepare": Mock(return_value=[]),
        "preview_artist": preview,
        "publication_plan": plan,
        "publish_pages": publish,
        "fleet_writer_package": Mock(return_value=b"writer"),
        "fleet_sites": repair_flow.install,
        "fleet_file_repair": repair_flow,
    }.items():
        monkeypatch.setitem(run_form.__globals__, name, replacement)
    run_form(signals)

    client.authorize.assert_called_once_with("proxvox@pve", "fixture", "000000", timeout=25)
    assert [item[0] for item in repair_flow.mock_calls] == ["install", "package", "preflight", "restore"]
    assert repair_flow.install.call_args.args == (reader, writer, client)
    assert repair_flow.restore.call_args.args[:4] == (client, reader, writer, [("fixture",)])
    preview.assert_called_once_with(client)
    if valid_manifest:
        assert publish.call_args.args[:4] == (reader, writer, client, [("fixture",)])
        assert plan.call_count == 2
    else:
        publish.assert_not_called()
        plan.assert_called_once_with("fixture", reader)
    if scenario == "expire":
        client.revoke.assert_not_called()
    else:
        client.revoke.assert_called_once()


def test_duplicate_launcher_reopens_the_shared_window_without_another_login(monkeypatch):
    source = Path(__file__).resolve().parents[1]
    monkeypatch.syspath_prepend(str(source))
    main = runpy.run_path(str(source / "authorize_database_tools.pyw"))["main"]
    signals = Mock(already_open=True)
    activate, run_form = Mock(), Mock()
    monkeypatch.setitem(main.__globals__, "LauncherSignals", lambda: signals)
    monkeypatch.setitem(main.__globals__, "run_form", run_form)
    monkeypatch.setitem(main.__globals__, "activate_existing_form", activate)
    main(["--select", "publish"])
    signals.select.assert_called_once_with("publish")
    signals.close.assert_called_once()
    activate.assert_called_once()
    run_form.assert_not_called()


@pytest.mark.skipif(sys.platform != "win32", reason="Windows launcher IPC")
def test_windows_launchers_signal_the_existing_process(monkeypatch):
    source = Path(__file__).resolve().parents[1]
    monkeypatch.syspath_prepend(str(source))
    pytest.importorskip("win32event")
    import win32security
    from proxvox.launcher_signals import LauncherSignals

    instance = "test-" + uuid.uuid4().hex
    monkeypatch.setattr(win32security, "ConvertSidToStringSid", lambda _: instance)
    primary = LauncherSignals()
    try:
        assert not primary.already_open
        result = subprocess.run(
            [sys.executable, "-c",
             "from proxvox.launcher_signals import LauncherSignals\n"
             "import win32security\n"
             f"win32security.ConvertSidToStringSid = lambda _: {instance!r}\n"
             "signals = LauncherSignals()\n"
             "assert signals.already_open\n"
             "signals.select('restore-updater')\n"
             "signals.close()"],
            cwd=source, capture_output=True, text=True,
            creationflags=subprocess.CREATE_NO_WINDOW, timeout=10,
        )
        assert result.returncode == 0, result.stderr
        assert list(primary.selections()) == ["restore-updater"]
        assert list(primary.selections()) == []
    finally:
        primary.close()


# ===== SNAPSMACK EOF =====
