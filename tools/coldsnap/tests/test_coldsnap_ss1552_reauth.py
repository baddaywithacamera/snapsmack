"""SS-1552: expired COLD SNAP authorization has a plain recovery path.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
from types import SimpleNamespace

from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

from coldsnap_qt.connect_panel import ConnectPanel, verify_site_mode  # noqa: E402
from coldsnap_qt.drafts_panel import BatchRail  # noqa: E402
from coldsnap_qt.mode_take import TakeMode  # noqa: E402
import sumna_offline as O  # noqa: E402
from sumna_post import AuthorizationExpiredError, SmacktalkPoster  # noqa: E402


def _app():
    return QApplication.instance() or QApplication([])


class _Response:
    status_code = 401

    def raise_for_status(self):
        raise AssertionError("authorization must be classified before requests does")


def test_long_form_401_has_plain_error_before_generic_http_error():
    class Session:
        headers = {}

        def get(self, *_args, **_kwargs):
            return _Response()

    poster = SmacktalkPoster("https://example.test", "expired", session=Session())
    try:
        poster.list_posts()
    except AuthorizationExpiredError as exc:
        assert exc.credential_kind == "long-form"
        assert "expired or was rejected" in str(exc)
        assert "Nothing was uploaded" in str(exc)
    else:
        raise AssertionError("expired authorization was accepted")


def test_mode_probe_rejection_uses_reauthentication_prompt(monkeypatch):
    _app()
    called = []

    class Connection:
        def probe_site_mode(self, timeout=6):
            return "", True, "API key rejected for mode check"

    monkeypatch.setattr(
        "coldsnap_qt.connect_panel.prompt_reauthentication",
        lambda parent, detail, long_form=False: called.append((detail, long_form)),
    )
    assert not verify_site_mode(None, Connection(), "photoblog", "SOLO")
    assert called == [("API key rejected for mode check", False)]


def test_reauthentication_opens_and_focuses_the_correct_saved_key(monkeypatch):
    _app()
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    panel = ConnectPanel()
    panel.show()
    _app().processEvents()
    panel.press_edit.setText("existing-long-form-key")

    panel.open_reauthentication(long_form=True)
    _app().processEvents()

    assert panel.details_btn.isChecked()
    assert panel.press_edit.hasFocus()
    assert panel.press_edit.selectedText() == "existing-long-form-key"
    assert "replace the saved long-form key" in panel.status_lbl.text()
    panel.close()


def test_open_published_routes_expired_long_form_key_to_prompt(monkeypatch):
    _app()
    mode = TakeMode(lambda: {"url": "https://example.test"})
    called = []

    class Poster:
        def list_posts(self):
            raise AuthorizationExpiredError("long-form")

    class ImmediateThread:
        def __init__(self, *, target, name, daemon):
            self.target = target

        def start(self):
            self.target()

    monkeypatch.setattr(mode, "_poster_and_url", lambda: (Poster(), "https://example.test"))
    monkeypatch.setattr("coldsnap_qt.mode_take.threading.Thread", ImmediateThread)
    monkeypatch.setattr(
        "coldsnap_qt.connect_panel.prompt_reauthentication",
        lambda parent, detail, long_form=False: called.append((detail, long_form)),
    )
    mode._open_published()
    _app().processEvents()

    assert called and called[0][1] is True
    assert "expired or was rejected" in called[0][0]
    mode.close()


def test_send_preflight_prompts_before_upload_when_authorization_expired(monkeypatch):
    _app()
    called = []

    class Poster:
        def publishing_policy(self):
            raise AuthorizationExpiredError("long-form")

    class Session:
        def list_drafts(self):
            return [SimpleNamespace(status=O.ST_READY)]

    rail = BatchRail("smacktalk", "post", lambda: (Poster(), "https://example.test"))
    rail.session = Session()
    monkeypatch.setattr(
        "coldsnap_qt.connect_panel.prompt_reauthentication",
        lambda parent, detail, long_form=False: called.append((detail, long_form)),
    )

    rail._send()

    assert called and called[0][1] is True
    assert "Nothing was uploaded" in called[0][0]
    assert not rail._sending
    rail.close()


# ===== SNAPSMACK EOF =====
