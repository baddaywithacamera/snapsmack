"""The shared PROX VOX form runs two operations with one authorization.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

from pathlib import Path
import runpy
from types import SimpleNamespace
from unittest.mock import Mock

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
    main = runpy.run_path(str(source / "authorize_database_tools.pyw"))["main"]
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
            if not valid_manifest:
                assert buttons["Authorize"].disabled
                assert "publication files could not be verified" in variables[2].get()
                return
            variables[0].set("fixture")
            variables[1].set("000000")
            buttons["Authorize"].press()
            self.poll()
            assert variables[0].get() == variables[1].get() == ""
            buttons["Preview The Artist"].press()
            self.poll()
            buttons["Publish SnapSmack.ca"].press()
            self.poll()
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

    kernel = SimpleNamespace(CreateMutexW=Mock(return_value=1), CloseHandle=Mock())
    client, reader, writer = Mock(), Mock(), Mock()
    client.seconds_remaining.return_value = 18000
    writer.scope.return_value = {"owner_users": ["proxvox@pve"]}
    plan = Mock(return_value=[("fixture",)])
    if not valid_manifest:
        plan.side_effect = RuntimeError("fixture manifest is stale")
    preview, publish = Mock(), Mock(return_value=1)
    monkeypatch.setenv("PROXVOX_PUBLICATION_SHA256", "fixture")
    for name, replacement in {
        "ctypes": SimpleNamespace(WinDLL=lambda *args, **kw: kernel, get_last_error=lambda: 0),
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
    }.items():
        monkeypatch.setitem(main.__globals__, name, replacement)
    main()

    if valid_manifest:
        client.authorize.assert_called_once_with("proxvox@pve", "fixture", "000000", timeout=25)
        preview.assert_called_once_with(client)
        assert publish.call_args.args[:4] == (reader, writer, client, [("fixture",)])
        assert plan.call_count == 2
        if scenario == "expire":
            client.revoke.assert_not_called()
        else:
            client.revoke.assert_called_once()
    else:
        client.authorize.assert_not_called()
        preview.assert_not_called()
        publish.assert_not_called()
        plan.assert_called_once_with("fixture", reader)


def test_duplicate_launcher_reopens_the_shared_window_without_another_login(monkeypatch):
    source = Path(__file__).resolve().parents[1]
    monkeypatch.syspath_prepend(str(source))
    main = runpy.run_path(str(source / "authorize_database_tools.pyw"))["main"]
    kernel = SimpleNamespace(CreateMutexW=Mock(return_value=1), CloseHandle=Mock())
    activate, create_window = Mock(), Mock()
    monkeypatch.setitem(main.__globals__, "ctypes", SimpleNamespace(
        WinDLL=lambda *args, **kwargs: kernel, get_last_error=lambda: 183))
    monkeypatch.setitem(main.__globals__, "tk", SimpleNamespace(Tk=create_window))
    monkeypatch.setitem(main.__globals__, "activate_existing_form", activate)
    main()
    kernel.CloseHandle.assert_called_once_with(1)
    activate.assert_called_once()
    create_window.assert_not_called()


# ===== SNAPSMACK EOF =====
