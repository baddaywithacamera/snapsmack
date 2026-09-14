"""Regression checks for CRONOMETER's heartbeat probe.

The probe drives due crons (multisite/run-crons) as a side effect of a health
check. The verdict must reflect the state AFTER that run, not before — otherwise a
job the probe just triggered still displays as stale/overdue, a false verdict shown
the instant the operator acted. These tests pin the call ordering and the re-fetch.
"""

import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CRONOMETER = os.path.join(ROOT, "tools", "cronometer")
if CRONOMETER not in sys.path:
    sys.path.insert(0, CRONOMETER)

import heartbeat_client as hb  # noqa: E402


class _FakeResp:
    def __init__(self, payload, status=200):
        self._p = payload
        self.status_code = status

    def json(self):
        return self._p


def _install_fake(hb_payloads, run_crons_status=200):
    """Stub hb.requests.get; return (calls, seq) trackers.

    hb_payloads is a list of heartbeat dicts returned in order per heartbeat GET.
    """
    calls = []
    seq = {"hb": 0}

    def fake_get(url, headers=None, timeout=None):
        calls.append(url)
        if "run-crons" in url:
            return _FakeResp({"ok": True}, run_crons_status)
        if "heartbeat" in url:
            i = min(seq["hb"], len(hb_payloads) - 1)
            seq["hb"] += 1
            return _FakeResp(hb_payloads[i], 200)
        return _FakeResp({"ok": True}, 200)

    hb.requests.get = fake_get
    return calls, seq


def _order(calls):
    return ["run-crons" if "run-crons" in c else "heartbeat" for c in calls]


def test_probe_refetches_heartbeat_after_running_crons():
    hb1 = {"ok": True, "version": "1", "jobs": {"rss_fetch": {"overdue": True}}}
    hb2 = {"ok": True, "version": "1", "jobs": {"rss_fetch": {"overdue": False}}}
    calls, seq = _install_fake([hb1, hb2])

    res = hb.probe({"name": "T", "url": "https://example.test", "api_key": "k"})

    assert _order(calls) == ["heartbeat", "run-crons", "heartbeat"], _order(calls)
    assert seq["hb"] == 2, "must re-fetch the heartbeat after running due crons"
    assert res.online is True


def test_probe_keeps_original_heartbeat_when_run_crons_fails():
    hb1 = {"ok": True, "version": "1", "jobs": {"rss_fetch": {"overdue": True}}}
    calls, seq = _install_fake([hb1], run_crons_status=500)

    res = hb.probe({"name": "T", "url": "https://example.test", "api_key": "k"})

    assert _order(calls) == ["heartbeat", "run-crons"], _order(calls)
    assert seq["hb"] == 1, "must NOT re-fetch when the cron run did not succeed"
    assert res.online is True


# OPAUDIT 014 — the board must judge on the scheduler heartbeat, not last_run.
def _fedi_spec():
    return hb._JOB_BY_KEY["fediverse"]


def _ago(seconds):
    return (hb._now() - __import__("datetime").timedelta(seconds=seconds)).strftime("%Y-%m-%d %H:%M:%S")


def test_fresh_last_run_but_scheduler_stale_is_not_green():
    # A visitor's page load drained the queue a minute ago; cron last fired 5 h ago.
    rich = {"last_run": _ago(60), "status": "ok",
            "sched_last_fire": _ago(5 * 3600), "sched_state": "stale"}
    jh = hb._job_from_rich(_fedi_spec(), rich)
    assert jh.severity in (hb.SEV_STALE, hb.SEV_FAILED), jh
    assert "NOT firing" in jh.detail


def test_scheduler_firing_is_green():
    rich = {"last_run": _ago(60), "status": "ok",
            "sched_last_fire": _ago(120), "sched_state": "firing"}
    jh = hb._job_from_rich(_fedi_spec(), rich)
    assert jh.severity == hb.SEV_OK, jh


def test_not_since_deploy_is_red():
    rich = {"last_run": _ago(60), "status": "ok",
            "sched_last_fire": _ago(3600), "sched_state": "not-since-deploy"}
    jh = hb._job_from_rich(_fedi_spec(), rich)
    assert jh.severity == hb.SEV_FAILED, jh
    assert "DEPLOY" in jh.detail


def test_never_fired_is_not_green():
    rich = {"last_run": _ago(60), "status": "ok", "sched_last_fire": None, "sched_state": "never"}
    jh = hb._job_from_rich(_fedi_spec(), rich)
    assert jh.severity == hb.SEV_STALE, jh


def test_pre_712d_site_still_judged_on_last_run():
    rich = {"last_run": _ago(60), "status": "ok"}
    jh = hb._job_from_rich(_fedi_spec(), rich)
    assert jh.severity == hb.SEV_OK
    assert "pre-712D" in jh.detail


if __name__ == "__main__":
    test_probe_refetches_heartbeat_after_running_crons()
    test_probe_keeps_original_heartbeat_when_run_crons_fails()
    test_fresh_last_run_but_scheduler_stale_is_not_green()
    test_scheduler_firing_is_green()
    test_not_since_deploy_is_red()
    test_never_fired_is_not_green()
    test_pre_712d_site_still_judged_on_last_run()
    print("ok")

# ===== SNAPSMACK EOF =====
