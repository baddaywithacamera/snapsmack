"""LOG FLOGGER tests.

The two that matter most are the ones that stop this tool doing harm:

  * cleanup refuses to run without a summary on disk
  * an anonymous report does not leak the site, the owner or a visitor

Both are checked by building a deliberately dirty case and watching the code
refuse it, rather than by asserting a happy path.

Run:  python -m pytest tools/log-flogger/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
from datetime import datetime, timedelta

import pytest

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

from logflogger import analyze, gather, probes, report as reportmod, summarize


def _log(root, name, body="", when=None):
    path = os.path.join(root, name)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(body)
    if when:
        ts = when.timestamp()
        os.utime(path, (ts, ts))
    return path


# ── gather / sort ────────────────────────────────────────────────────────────

def test_sorts_by_app_and_kind_from_the_existing_filename_convention(tmp_path):
    root = str(tmp_path)
    _log(root, "coldsnap_debug_2026-09-02-08-49.log", "hello")
    _log(root, "sybu_audit_2026-09-10-20-27.log", "hello")
    _log(root, "snap_slapper_run_2026-10-14-11-00.log", "hello")

    h = gather.gather(roots=[root])
    assert set(h.apps) == {"coldsnap", "sybu", "snap_slapper"}
    assert h.apps["sybu"][0].kind == "audit"
    assert h.apps["coldsnap"][0].when == datetime(2026, 9, 2, 8, 49)


def test_a_stray_log_still_gets_an_app(tmp_path):
    root = str(tmp_path)
    _log(root, "unzucker.log", "x")
    h = gather.gather(roots=[root])
    assert h.files[0].app == "flkr-fckr"


def test_an_unrecognised_log_is_gathered_not_dropped(tmp_path):
    # An unreadable log is still evidence. Dropping it is the silence this
    # whole tool exists to catch.
    root = str(tmp_path)
    _log(root, "mystery-thing.log", "x")
    h = gather.gather(roots=[root])
    assert len(h.files) == 1
    assert h.files[0].kind == "unknown"


def test_reading_a_huge_log_keeps_the_tail_not_the_head(tmp_path):
    root = str(tmp_path)
    body = "\n".join(f"line {i}" for i in range(50_000))
    p = _log(root, "big_debug_2026-10-01-00-00.log", body)
    lf = gather.classify(p)
    text = lf.read_text(max_bytes=2_000)
    assert "line 49999" in text          # the end, which is where a loop shows
    assert "line 0\n" not in text


# ── the silence finder, which is the point of the tool ───────────────────────

def test_finds_an_app_that_stopped(tmp_path):
    root = str(tmp_path)
    start = datetime(2026, 9, 1, 9, 0)
    for i in range(8):                   # ran daily, then stopped
        when = start + timedelta(days=i)
        _log(root, f"cronometer_debug_{when:%Y-%m-%d-%H-%M}.log", "tick", when)

    h = gather.gather(roots=[root])
    found = analyze.find_silences(h, quiet_days=14, now=datetime(2026, 10, 17))
    assert len(found) == 1
    assert found[0].kind == "silence"
    assert "cronometer" in found[0].headline


def test_an_app_still_running_is_not_reported_as_silent(tmp_path):
    root = str(tmp_path)
    now = datetime(2026, 10, 17)
    for i in range(8):
        when = now - timedelta(days=i)
        _log(root, f"coldsnap_debug_{when:%Y-%m-%d-%H-%M}.log", "tick", when)
    h = gather.gather(roots=[root])
    assert analyze.find_silences(h, now=now) == []


def test_too_little_history_is_not_called_a_silence(tmp_path):
    # Two runs is not a habit, and calling it one would train Sean to ignore this.
    root = str(tmp_path)
    _log(root, "rare_debug_2026-01-01-09-00.log", "x")
    _log(root, "rare_debug_2026-01-02-09-00.log", "x")
    h = gather.gather(roots=[root])
    assert analyze.find_silences(h, now=datetime(2026, 10, 17)) == []


def test_identical_errors_collapse_to_one_finding_with_a_count(tmp_path):
    root = str(tmp_path)
    body = "\n".join(
        f"2026-10-01 10:00:0{i % 10} ERROR widget failed for id {i}" for i in range(40)
    )
    _log(root, "app_debug_2026-10-01-10-00.log", body)
    h = gather.gather(roots=[root])
    found = analyze.find_errors(h)
    assert len(found) == 1
    assert found[0].count == 40


def test_data_at_risk_outranks_an_ordinary_error(tmp_path):
    root = str(tmp_path)
    _log(root, "app_debug_2026-10-01-10-00.log",
         "ERROR checksum mismatch on image 44 - file may be corrupt")
    h = gather.gather(roots=[root])
    assert analyze.find_errors(h)[0].band == analyze.LOSING_WORK


# ── probes: the sharpening question ──────────────────────────────────────────

GENERIC_LOG = (
    '1.2.3.4 - - [01/Oct/2026:10:00:00] "GET /wp-login.php HTTP/1.1" 403 0\n'
    '1.2.3.4 - - [01/Oct/2026:10:00:01] "GET /.env HTTP/1.1" 403 0\n'
)
SHARP_LOG = (
    '9.9.9.9 - - [02/Oct/2026:03:00:00] "GET /smack-api-keys.php HTTP/1.1" 403 0\n'
    '9.9.9.9 - - [02/Oct/2026:03:00:01] "GET /snap-in.php HTTP/1.1" 200 120\n'
)


def test_generic_scanner_noise_is_not_sharpening():
    rep = probes.scan_access_log(GENERIC_LOG)
    assert rep.sharpening is False
    assert rep.generic_hits == 2
    assert "quiet baseline" in rep.verdict


def test_a_snapsmack_specific_probe_is_sharpening():
    rep = probes.scan_access_log(SHARP_LOG)
    assert rep.sharpening is True
    assert rep.sensitive_hits >= 1          # smack-api-keys and snap-in both count
    assert "SHARPENING" in rep.verdict


def test_probe_sources_are_counted_never_recorded():
    rep = probes.scan_access_log(SHARP_LOG)
    assert rep.distinct_sources == 1
    assert "9.9.9.9" not in repr(rep.sources)   # hashed, not stored


# ── summarize + clean: the destructive half ──────────────────────────────────

def _summarised(tmp_path, when):
    root = str(tmp_path / "logs")
    _log(root, f"app_debug_{when:%Y-%m-%d-%H-%M}.log", "ERROR something broke", when)
    h = gather.gather(roots=[root])
    s = summarize.summarise(h, analyze.analyse(h), now=datetime(2026, 10, 17))
    return root, h, s


def test_cleanup_refuses_without_a_summary_on_disk(tmp_path):
    old = datetime(2026, 1, 1, 9, 0)
    _root, h, s = _summarised(tmp_path, old)
    plan = summarize.plan_clean(h, s, keep_days=30, now=datetime(2026, 10, 17))
    assert plan.remove                                  # it WOULD delete
    with pytest.raises(RuntimeError, match="no summary"):
        summarize.apply_clean(plan, "")                 # but refuses to


def test_a_log_the_summary_never_covered_is_never_deleted(tmp_path):
    old = datetime(2026, 1, 1, 9, 0)
    root, h, s = _summarised(tmp_path, old)
    _log(root, f"late_debug_{old:%Y-%m-%d-%H-%M}.log", "x", old)   # arrived after
    h2 = gather.gather(roots=[root])
    plan = summarize.plan_clean(h2, s, keep_days=30, now=datetime(2026, 10, 17))
    kept = " ".join(plan.kept.values())
    assert "nothing would remember it" in kept
    assert all("late_debug" not in f.name for f in plan.remove)


def test_a_pinned_log_survives_however_old(tmp_path):
    old = datetime(2026, 1, 1, 9, 0)
    _root, h, s = _summarised(tmp_path, old)
    name = h.files[0].name
    plan = summarize.plan_clean(h, s, keep_days=30, pinned={name},
                                now=datetime(2026, 10, 17))
    assert plan.remove == []
    assert "pinned" in " ".join(plan.kept.values())


def test_recent_logs_are_kept(tmp_path):
    recent = datetime(2026, 10, 15, 9, 0)
    _root, h, s = _summarised(tmp_path, recent)
    plan = summarize.plan_clean(h, s, keep_days=30, now=datetime(2026, 10, 17))
    assert plan.remove == []


def test_apply_clean_actually_removes_and_keeps_the_summary(tmp_path):
    old = datetime(2026, 1, 1, 9, 0)
    _root, h, s = _summarised(tmp_path, old)
    spath = summarize.write_summary(s, str(tmp_path / "summary.json"))
    plan = summarize.plan_clean(h, s, keep_days=30, now=datetime(2026, 10, 17))
    result = summarize.apply_clean(plan, spath)
    assert result["removed"]
    assert not os.path.exists(h.files[0].path)
    assert os.path.exists(spath)           # the knowledge outlived the data


# ── the report: anonymous by default, and proved so ──────────────────────────

def _dirty_summary():
    s = summarize.Summary(created="2026-10-17T10:00:00")
    s.findings = [{
        "band": analyze.DOOR_OPEN,
        "app": "snap_slapper",
        "headline": "403 Forbidden for https://allinthewrist.photoblogs.fyi/api.php?key=deadbeefcafe1234567890ab",
        "detail": "from 203.0.113.9, user sean@example.com, file C:\\\\snapsmack\\\\logs\\\\x.log",
        "count": 3,
        "evidence": ["raw line naming allinthewrist.photoblogs.fyi and 203.0.113.9"],
        "kind": "error",
    }]
    return s


def test_anonymous_is_the_default():
    rep = reportmod.build(_dirty_summary())
    assert rep.anonymous is True


def test_an_anonymous_report_leaks_nothing():
    rep = reportmod.build(_dirty_summary(), site="allinthewrist.photoblogs.fyi",
                          contact="sean@example.com", anonymous=True)
    assert reportmod.leaks(rep) == []
    body = reportmod.preview(rep)
    for secret in ("allinthewrist", "203.0.113.9", "sean@example.com",
                   "deadbeefcafe", "C:\\\\snapsmack"):
        assert secret not in body


def test_anonymous_withholds_raw_log_lines_entirely():
    # Evidence is the most useful and most dangerous field. The shape travels,
    # the lines do not.
    rep = reportmod.build(_dirty_summary(), anonymous=True)
    assert rep.findings[0]["evidence"] == []
    assert rep.findings[0]["evidence_withheld"] == 1


def test_identifying_yourself_is_the_deliberate_extra_step():
    rep = reportmod.build(_dirty_summary(), anonymous=False,
                          site="allinthewrist.photoblogs.fyi", contact="sean@example.com")
    assert rep.as_dict()["identified"]["site"] == "allinthewrist.photoblogs.fyi"
    assert "IDENTIFIED REPORT" in reportmod.preview(rep)


def test_the_leak_check_actually_catches_a_leak():
    # A scrubber that is never watched failing is trusted for no reason.
    rep = reportmod.build(_dirty_summary(), anonymous=True)
    rep.note = "contact me at 198.51.100.7"      # slipped in after scrubbing
    assert any("IP address" in x for x in reportmod.leaks(rep))


def test_two_reports_from_one_install_share_a_fingerprint():
    # 'three installs' vs 'one install three times' is the whole difference
    # between a person looking and a scanner kit.
    a = reportmod.build(_dirty_summary(), site="x.ca", cms_version="0.7.901D")
    b = reportmod.build(_dirty_summary(), site="x.ca", cms_version="0.7.901D")
    c = reportmod.build(_dirty_summary(), site="y.ca", cms_version="0.7.901D")
    assert a.fingerprint() == b.fingerprint()
    assert a.fingerprint() != c.fingerprint()

# ===== SNAPSMACK EOF =====
