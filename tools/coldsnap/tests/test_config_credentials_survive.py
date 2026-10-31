"""A stored credential survives a configuration write it had nothing to do with.

SS-1084. Toggling the BIGGIE editor face in COLD SNAP destroyed the stored site
password, API key and SMACKPRESS key. Demonstrated on a scratch config before
any of this was written:

    BEFORE  password / api_key / smackpress_key   enc1:gAAAAABq5Suj...
    AFTER   password / api_key / smackpress_key   (EMPTY)

AND NOTHING FAILED. That is the part worth keeping in mind while reading these
tests. The suspected mechanism was "sealing throws and the credential is
written empty", which is real but secondary. The actual route is quieter:

    _open_secret() returns '' for a secret it cannot decrypt -- no exception,
    no flag -- so load() hands back '' for all three credentials whenever the
    vault is unreachable. save() then seals what it was given, and sealing ''
    produces '' by design. The empties go straight over the sealed values.

So a read that cannot tell "there is no secret" from "I could not open the
secret" is the defect, and the write faithfully doing as it is told is the
mechanism. Both ends are covered below.

THE VAULT BEING UNREACHABLE IS NOT EXOTIC: _shared_creds() returns None
whenever importing the shared credential store fails for any reason -- a tool
started from a different working directory, a partial install -- and the vault
can also be locked for the session.

EVERY TEST HERE USES A SCRATCH SNAPSMACK_HOME. Nothing touches the real
configuration, which holds live credentials for a running fleet.

Run:  .python-build/python.exe -m pytest tools/coldsnap/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import configparser
import os
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)
SHARED = os.path.join(os.path.dirname(BASE), "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import pytest  # noqa: E402

SECRETS = ("password", "api_key", "smackpress_key")
STORED = {
    "password": "a-real-password",
    "api_key": "KEY-abc123-sybu",
    "smackpress_key": "KEY-def456-smackpress",
}


@pytest.fixture(autouse=True)
def _never_the_real_install(tmp_path, monkeypatch):
    """EVERY test in this file gets a scratch SNAPSMACK_HOME, whether it asks
    or not.

    This is here because one test in this file did NOT ask. It called a helper
    that imported config with no scratch home, ran against the real
    configuration on this machine, and overwrote the owner's COLD SNAP
    connection with its own test values. The tests that destroy credentials to
    prove credentials are safe are exactly the ones that must not be able to
    reach a live install, and relying on each author to remember the fixture is
    how that happened.

    autouse means a new test cannot get it wrong by omission.
    """
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "scratch-home"))
    for mod in ("snap_home", "config"):
        sys.modules.pop(mod, None)
    yield
    for mod in ("snap_home", "config"):
        sys.modules.pop(mod, None)


@pytest.fixture()
def cfg(tmp_path, monkeypatch):
    """A scratch home with a configuration holding all three credentials."""
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    for mod in ("snap_home", "config"):
        sys.modules.pop(mod, None)
    import config as cfg_module

    cfg_module.save(dict(STORED, url="https://example.test", username="sean",
                         remember=True))
    return cfg_module


def raw_auth(cfg_module):
    """What is actually on disk, unopened."""
    parser = configparser.ConfigParser()
    parser.read(cfg_module._config_path())
    return {k: parser.get("auth", k, fallback="") for k in SECRETS}


def break_the_vault(cfg_module, monkeypatch):
    """Exactly how the code experiences an unreachable vault."""
    monkeypatch.setattr(cfg_module, "_shared_creds", lambda: None)


# -- the fixture itself has to be worth something -------------------------

def test_the_credentials_start_out_sealed_on_disk(cfg):
    """If this fails every test below is measuring nothing."""
    on_disk = raw_auth(cfg)
    for key in SECRETS:
        assert on_disk[key], "%s was not stored at all" % key
        assert on_disk[key] != STORED[key], "%s was stored in the clear" % key


def test_they_read_back_when_the_vault_works(cfg):
    data = cfg.load()
    for key in SECRETS:
        assert data[key] == STORED[key]


# -- CONDITION 1: a failed read is distinguishable from an absent secret --

def test_load_says_which_secrets_it_could_not_open(cfg, monkeypatch):
    """The whole defect in one assertion. Without this a caller cannot tell
    'no key stored' from 'I could not read the key', and every caller that
    writes the dict back destroys what it could not read."""
    break_the_vault(cfg, monkeypatch)
    data = cfg.load()
    unreadable = data.get("secrets_unreadable")
    assert unreadable, "load() reported nothing unreadable while returning empties"
    for key in SECRETS:
        assert key in unreadable, "%s came back empty and was not reported" % key


def test_an_absent_secret_is_not_reported_as_unreadable(tmp_path, monkeypatch):
    """A fresh install has no credentials and nothing is wrong with it."""
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "fresh"))
    for mod in ("snap_home", "config"):
        sys.modules.pop(mod, None)
    import config as cfg_module
    data = cfg_module.load()
    assert data.get("secrets_unreadable") in (None, [], ())


# -- CONDITION 2: a write must not erase what a read could not see --------

def test_a_view_toggle_does_not_destroy_the_credentials(cfg, monkeypatch):
    """THE ORIGINAL FAULT, as a test. The body_editor face toggle did exactly
    this: load, set one boolean, save."""
    before = raw_auth(cfg)
    break_the_vault(cfg, monkeypatch)
    data = cfg.load() or {}
    data["biggie_enabled"] = True
    cfg.save(data)
    after = raw_auth(cfg)
    for key in SECRETS:
        assert after[key] == before[key], (
            "%s was overwritten by a write that never meant to touch it" % key)


def test_an_empty_value_never_replaces_a_stored_one(cfg, monkeypatch):
    """Even with a working vault. An empty credential arriving from anywhere is
    not a reason to erase a sealed one -- the caller has to ask."""
    before = raw_auth(cfg)
    data = cfg.load()
    data["api_key"] = ""
    data["smackpress_key"] = ""
    cfg.save(data)
    after = raw_auth(cfg)
    assert after["api_key"] == before["api_key"]
    assert after["smackpress_key"] == before["smackpress_key"]


def test_a_deliberate_change_still_lands(cfg):
    """The guard must not make the credentials unwritable."""
    data = cfg.load()
    data["api_key"] = "KEY-replaced-on-purpose"
    cfg.save(data)
    assert cfg.load()["api_key"] == "KEY-replaced-on-purpose"


def test_forgetting_the_password_still_works(cfg):
    """Unchecking "remember" is an explicit instruction to stop storing it, and
    it has to keep working or the guard has broken a real control."""
    data = cfg.load()
    data["remember"] = False
    cfg.save(data)
    assert raw_auth(cfg)["password"] == ""


def test_a_sealing_failure_does_not_erase_either(cfg, monkeypatch):
    """The route originally suspected: the read works, the WRITE cannot seal."""
    before = raw_auth(cfg)
    data = cfg.load()

    def boom(_value):
        raise RuntimeError("vault locked")
    monkeypatch.setattr(cfg, "_seal_secret", boom)
    data["last_image_folder"] = "D:/photos"
    cfg.save(data)

    after = raw_auth(cfg)
    for key in SECRETS:
        assert after[key] == before[key], (
            "%s was erased when sealing failed" % key)


def test_the_unsealable_write_is_reported_not_swallowed(cfg, monkeypatch):
    """CONDITION 5. A credential write that could not complete must say so."""
    data = cfg.load()

    def boom(_value):
        raise RuntimeError("vault locked")
    monkeypatch.setattr(cfg, "_seal_secret", boom)
    data["api_key"] = "KEY-new-value"
    cfg.save(data)
    assert data.get("vault_warning"), "a failed credential write reported nothing"


# -- the ordinary settings a write is usually for still get written -------

def test_the_rest_of_the_configuration_still_saves(cfg, monkeypatch):
    """The guard protects credentials. It must not stop anything else being
    written, or every caller quietly stops working."""
    break_the_vault(cfg, monkeypatch)
    data = cfg.load()
    data["last_image_folder"] = "D:/photos"
    data["default_category"] = "textures"
    cfg.save(data)
    again = cfg.load()
    assert again["last_image_folder"] == "D:/photos"
    assert again["default_category"] == "textures"


def test_the_url_and_username_still_save_with_the_vault_broken(cfg, monkeypatch):
    break_the_vault(cfg, monkeypatch)
    data = cfg.load()
    data["url"] = "https://craptasti.ca"
    cfg.save(data)
    assert cfg.load()["url"] == "https://craptasti.ca"

def test_a_caller_that_omits_remember_does_not_lose_the_password(cfg):
    """A DEFAULT THAT DELETES is the same class of fault as SS-1084 itself.

    No current caller omits 'remember' -- all three pass a dict from load(),
    which always sets it -- so this is not reachable today. It is pinned
    because the guard's whole value is reasoning about the VALUE rather than
    about who called, and a caller building a dict by hand is exactly the case
    nobody enumerates in advance.

    TAKES THE `cfg` FIXTURE, like every other test here, and the first version
    of it did not. It called a module-level helper that imported config with no
    scratch SNAPSMACK_HOME, so it ran against the REAL configuration on this
    machine and overwrote it with these test values. Any test in this file that
    does not take `cfg` is writing to a live install.
    """
    before = raw_auth(cfg)
    cfg.save({"url": "https://example.test", "api_key": "KEY-abc123-sybu"})
    assert raw_auth(cfg)["password"] == before["password"]


def test_an_explicit_remember_false_still_forgets_it(cfg):
    """Clearing has to stay possible, or the guard has broken a real control."""
    data = cfg.load()
    data["remember"] = False
    cfg.save(data)
    assert raw_auth(cfg)["password"] == ""


# -- CONDITION 3: the view toggle cannot reach a credential at all --------

def test_the_body_editor_does_not_touch_the_application_config():
    """The toggle that destroyed the credentials now writes ONE QSettings key.

    This asserts the stronger property rather than the behaviour: that file can
    no longer reach the configuration module, so it cannot rewrite a credential
    even by accident. A behavioural test would pass while the import sat there
    waiting for the next person to use it.
    """
    path = os.path.join(BASE, "coldsnap_qt", "body_editor.py")
    source = open(path, encoding="utf-8").read()
    code = chr(10).join(
        line for line in source.splitlines()
        if not line.lstrip().startswith("#")
    )
    assert "import config" not in code, (
        "body_editor imports the application config again. Remembering which "
        "editor face was last used is view state; it goes in QSettings, and "
        "rewriting the whole config for it is what destroyed the credentials."
    )
    assert "cfg_module" not in code
    assert "QSettings" in code, "the face is no longer remembered anywhere"


def test_no_silent_swallow_around_the_face_write():
    """CONDITION 5, at the place it mattered. The old code wrapped the config
    rewrite in `except Exception: pass`, so even the failure path said nothing.

    COMMENTS ARE STRIPPED FIRST. The first version of this test searched the
    raw source and failed -- because the comment ABOVE the new code quotes the
    old `except Exception: pass` while explaining what it cost. A check that
    matches prose is not a check, and this file has caught that mistake in
    other people's tests often enough to deserve catching it in its own.
    """
    path = os.path.join(BASE, "coldsnap_qt", "body_editor.py")
    source = open(path, encoding="utf-8").read()
    code = chr(10).join(
        line for line in source.splitlines()
        if not line.lstrip().startswith("#")
    )
    assert "body_editor/biggie_enabled" in code, "the face is not written to QSettings"
    assert "except Exception:" not in code, (
        "a bare swallow has reappeared in body_editor. The face write is one "
        "QSettings call and needs no exception handling; a swallow here is how "
        "the credential loss stayed invisible."
    )

# ===== SNAPSMACK EOF =====
