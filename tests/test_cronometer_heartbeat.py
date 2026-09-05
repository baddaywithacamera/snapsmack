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


if __name__ == "__main__":
    test_probe_refetches_heartbeat_after_running_crons()
    test_probe_keeps_original_heartbeat_when_run_crons_fails()
    print("ok")

# ===== SNAPSMACK EOF =====
