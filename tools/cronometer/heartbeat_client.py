"""
CRONOMETER — heartbeat_client.py

Talks to a single fleet site's multisite heartbeat and turns the reply into a
per-cron-job health verdict, for the three scheduled crons EVERY SnapSmack site
runs: fediverse delivery, RSS blogroll fetch, and version/update check. (Backups
are not a cron — the SUYB desktop tool does those; SMACKBACK rides version-check;
directory-feeds runs only on the photoblogs.fyi host — so none are per-site cron
jobs and none are monitored here.) This is the honest-degradation layer:

  * it reads the `jobs` block the heartbeat ships (core/multisite-api.php, GET
    multisite/heartbeat — keyed {fediverse, rss_fetch, version_check}, each
    {last_run, status}; shape in docs/cronometer-spec.md), and
  * for a site too old to ship that block, derives what it honestly can from
    today's fields (fediverse ENABLED/DISABLED from fediverse_enabled) and marks
    everything else UNKNOWN / "not reported" rather than inventing a green light.

Surfacing the silent failure is the whole point, so UNKNOWN is treated as a caution
the operator must see — never quietly folded into "all good".

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import datetime
import json
import os
import sys
from dataclasses import dataclass, field
from typing import List, Optional
from urllib.parse import urlsplit, urlunsplit

import requests


# ── Severity ladder (worst-first for a site rollup) ─────────────────────────
SEV_FAILED  = 'failed'    # red    — job ran and failed, or is wildly overdue
SEV_STALE   = 'stale'     # amber  — overdue past its grace window
SEV_UNKNOWN = 'unknown'   # grey   — site doesn't report this job yet (caution)
SEV_OK      = 'ok'        # green  — ran within its expected window
SEV_NA      = 'na'        # dim    — job not applicable here (feature disabled)
SEV_OFFLINE = 'offline'   # red    — the heartbeat itself did not answer
SEV_PARKED  = 'parked'    # dim    — site is in maintenance mode (deliberately idle)

# Ordering used to roll a site's many jobs up to one overall dot. Higher wins.
# PARKED sits below everything: a maintenance site is set at the SITE level in
# overall(), never rolled up from jobs, so it can never mask a real red.
_SEV_RANK = {
    SEV_PARKED:  -1,
    SEV_NA:      0,
    SEV_OK:      1,
    SEV_UNKNOWN: 2,
    SEV_STALE:   3,
    SEV_FAILED:  4,
    SEV_OFFLINE: 5,
}


def worst(sevs) -> str:
    """The most-alarming severity in a collection (for the per-site rollup dot)."""
    out = SEV_NA
    for s in sevs:
        if _SEV_RANK.get(s, 0) > _SEV_RANK.get(out, 0):
            out = s
    return out


# ── Job catalogue ───────────────────────────────────────────────────────────
# key, label, and the cadence (seconds) the cron is expected to run at. Grace
# windows: OK while age <= 2x interval; STALE up to the red cutoff; FAILED beyond.
# Cadences come straight from each cron's documented RECOMMENDED schedule.
@dataclass
class JobSpec:
    key: str
    label: str
    interval_sec: int          # expected run cadence
    red_after_sec: int         # age past which "stale" becomes "failed"


# ONLY the scheduled crons that EVERY SnapSmack site actually runs. Backups are
# not a cron (SUYB, a desktop tool, does those), SMACKBACK just rides the
# version-check cron, and cron-directory-feeds runs on the photoblogs.fyi host
# only — so none are per-site cron jobs and were removed to stop the false
# red/grey rows. (Backup freshness is still in the heartbeat as last_backup_at
# if a separate, clearly non-cron indicator is wanted later.)
JOB_SPECS = [
    JobSpec('fediverse',     'Fediverse delivery',  10 * 60,        6 * 3600),   # cron-fediverse: every 10 min
    JobSpec('rss_fetch',     'RSS blogroll fetch',  60 * 60,        3 * 24 * 3600),  # cron-rss-fetch: ~hourly
    JobSpec('version_check', 'Version / update check', 6 * 3600,    2 * 24 * 3600),  # cron-version-check: every 6h
]
_JOB_BY_KEY = {j.key: j for j in JOB_SPECS}


@dataclass
class JobHealth:
    key: str
    label: str
    severity: str
    last_run: Optional[datetime.datetime]   # None = never / not reported
    detail: str                             # short human note
    reported: bool                          # did the site actually report this job?

    def age_text(self) -> str:
        if self.severity == SEV_NA:
            return 'n/a'
        if not self.last_run:
            return 'not reported' if not self.reported else 'never'
        return _humanize_age(self.last_run)


@dataclass
class SiteHealth:
    name: str
    url: str
    online: bool
    error: str = ''                         # populated when online is False
    version: str = ''
    jobs: List[JobHealth] = field(default_factory=list)
    checked_at: Optional[datetime.datetime] = None
    # Fleet context the heartbeat ships — used to classify a site so a
    # deliberately-idle or empty blog isn't screamed at like a broken active one.
    maintenance: bool = False               # snap_settings.maintenance_mode
    site_mode: str = ''                     # photoblog | carousel | smacktalk
    post_count: int = 0
    followers: int = 0                       # fediverse followers (delivery audience)

    def overall(self) -> str:
        if not self.online:
            return SEV_OFFLINE
        # Maintenance mode is a deliberate state, not a failure: a parked site has
        # no content and runs no meaningful crons, so it must never colour the
        # fleet red. Surfaced as its own dim PARKED verdict instead.
        if self.maintenance:
            return SEV_PARKED
        return worst(j.severity for j in self.jobs) if self.jobs else SEV_UNKNOWN


# ── Time helpers ────────────────────────────────────────────────────────────

def _now() -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc)


def _parse_ts(val) -> Optional[datetime.datetime]:
    """Parse an ISO-8601 or 'YYYY-MM-DD HH:MM:SS' timestamp to an aware UTC
    datetime. Returns None on anything unparseable (so callers show 'unknown')."""
    if not val or not isinstance(val, str):
        return None
    s = val.strip()
    if not s:
        return None
    # date('c') on the server yields e.g. 2026-08-14T09:30:00+00:00.
    try:
        dt = datetime.datetime.fromisoformat(s.replace('Z', '+00:00'))
    except ValueError:
        for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%d'):
            try:
                dt = datetime.datetime.strptime(s, fmt)
                break
            except ValueError:
                dt = None
        if dt is None:
            return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=datetime.timezone.utc)
    return dt.astimezone(datetime.timezone.utc)


def _humanize_age(when: datetime.datetime) -> str:
    secs = int((_now() - when).total_seconds())
    if secs < 0:
        secs = 0
    if secs < 90:
        return f'{secs}s ago'
    mins = secs // 60
    if mins < 90:
        return f'{mins}m ago'
    hrs = mins // 60
    if hrs < 48:
        return f'{hrs}h ago'
    days = hrs // 24
    return f'{days}d ago'


def _severity_for(spec: JobSpec, last_run: Optional[datetime.datetime],
                  explicit_status: str = '') -> str:
    """Map (last-run age, optional explicit status) to a severity for one job.
    explicit_status wins when it is a hard signal ('failed'/'breach'/'ok')."""
    st = (explicit_status or '').strip().lower()
    if st in ('failed', 'error', 'breach'):
        return SEV_FAILED
    if last_run is None:
        # A site that says ok/clean but gives no timestamp still counts as ok.
        return SEV_OK if st in ('ok', 'clean', 'success') else SEV_UNKNOWN
    age = (_now() - last_run).total_seconds()
    if age > spec.red_after_sec:
        return SEV_FAILED
    if age > 2 * spec.interval_sec:
        return SEV_STALE
    return SEV_OK


# ── Transport guard (fail-closed) ───────────────────────────────────────────
# Never send a Bearer key over plaintext http:// (except localhost). Reuse the
# shared guard when present; otherwise a local fail-closed copy — same posture
# SYBU falls back to.
def _insecure_reason(base_url: str) -> str:
    try:
        _sd = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        from snap_stepup import insecure_transport_reason
        return insecure_transport_reason(base_url)
    except Exception:
        u = str(base_url).strip().lower()
        if u.startswith('https://'):
            return ''
        for host in ('http://localhost', 'http://127.', 'http://[::1]'):
            if u.startswith(host):
                return ''
        return ('Site URL is not https:// — refusing to send the API key in the '
                'clear.')


def _api_url(site_url: str, route: str) -> str:
    """Build {site}/api.php?route=<route>, preserving scheme+host and dropping any
    path/query the profile carried."""
    parts = urlsplit(site_url if '://' in site_url else 'https://' + site_url)
    scheme = parts.scheme or 'https'
    netloc = parts.netloc or parts.path   # bare-host inputs land in .path
    return urlunsplit((scheme, netloc, '/api.php', 'route=' + route, ''))


def _heartbeat_url(site_url: str) -> str:
    return _api_url(site_url, 'multisite/heartbeat')


# ── The one public call ─────────────────────────────────────────────────────

def probe(site: dict, timeout: int = 12) -> SiteHealth:
    """Poll one site's heartbeat and return its SiteHealth. Never raises — every
    failure mode (no key, insecure URL, timeout, bad JSON, HTTP error) becomes an
    offline SiteHealth carrying a readable reason, because a dead probe IS the
    signal this tool exists to surface."""
    name = site.get('name') or site.get('url') or '(unnamed)'
    url  = (site.get('url') or '').strip()
    key  = (site.get('api_key') or '').strip()
    sh = SiteHealth(name=name, url=url, online=False, checked_at=_now())

    if not url:
        sh.error = 'no site URL in profile'
        return sh
    if not key:
        sh.error = 'no API key saved for this site'
        return sh
    reason = _insecure_reason(url)
    if reason:
        sh.error = reason
        return sh

    try:
        resp = requests.get(
            _heartbeat_url(url),
            headers={'Authorization': f'Bearer {key}',
                     'Accept': 'application/json'},
            timeout=timeout,
        )
    except requests.exceptions.Timeout:
        sh.error = f'timed out after {timeout}s'
        return sh
    except requests.exceptions.RequestException as e:
        sh.error = f'connection failed: {e.__class__.__name__}'
        return sh

    if resp.status_code == 401 or resp.status_code == 403:
        sh.error = f'auth rejected (HTTP {resp.status_code}) — key invalid?'
        return sh
    if resp.status_code >= 400:
        sh.error = f'HTTP {resp.status_code}'
        return sh
    try:
        data = resp.json()
    except (json.JSONDecodeError, ValueError):
        sh.error = 'reply was not JSON (wrong URL, or a login/HTML page?)'
        return sh
    if not isinstance(data, dict) or not data.get('ok', True):
        sh.error = str((data or {}).get('error') or 'heartbeat returned not-ok')
        return sh

    sh.online  = True

    # Fleet cron driver: nudge this site's due crons to run even when it gets no
    # visitor traffic. Best-effort and idempotent — the server tick self-throttles,
    # so a hit that isn't due does nothing. Never affects the health verdict.
    try:
        requests.get(
            _api_url(url, 'multisite/run-crons'),
            headers={'Authorization': f'Bearer {key}', 'Accept': 'application/json'},
            timeout=8,
        )
    except Exception:
        pass

    sh.version     = str(data.get('version') or '')
    sh.maintenance = str(data.get('maintenance_mode') or '0') in ('1', 'true', 'True')
    sh.site_mode   = str(data.get('site_mode') or '')
    sh.post_count  = int(data.get('post_count') or 0)
    sh.followers   = int(data.get('smackverse_followers') or 0)
    sh.jobs        = _jobs_from_heartbeat(data)
    return sh


def _jobs_from_heartbeat(data: dict) -> List[JobHealth]:
    """Turn a heartbeat payload into one JobHealth per catalogued job. Prefers a
    future `jobs` block; otherwise derives honestly from today's fields."""
    jobs_block = data.get('jobs') if isinstance(data.get('jobs'), dict) else {}
    fedi_on = str(data.get('fediverse_enabled') or data.get('smackverse_enabled') or '0') in ('1', 'true', 'True')
    followers = int(data.get('smackverse_followers') or 0)
    out: List[JobHealth] = []
    for spec in JOB_SPECS:
        rich = jobs_block.get(spec.key) if isinstance(jobs_block.get(spec.key), dict) else None
        if rich is not None:
            jh = _job_from_rich(spec, rich)
        else:
            jh = _job_derived(spec, data)
        # Fediverse delivery is only meaningful when there is an audience. With
        # federation ON but ZERO followers, the delivery cron has nothing to send,
        # so its last-run freshness says nothing about health — report a calm N/A
        # ("nothing to deliver") so quiet blogs stop lighting the board red/grey.
        # A site WITH followers is still judged on freshness, so a genuinely broken
        # delivery on an active, followed blog still shows red.
        if spec.key == 'fediverse' and fedi_on and followers == 0:
            jh = JobHealth(spec.key, spec.label, SEV_NA, jh.last_run,
                           'federation on, but no followers yet — nothing to deliver',
                           reported=jh.reported)
        out.append(jh)
    return out


def _job_from_rich(spec: JobSpec, rich: dict) -> JobHealth:
    """Consume the MUST-ADD `jobs.<key>` shape: {last_run, status, detail}."""
    last = _parse_ts(rich.get('last_run'))
    status = str(rich.get('status') or '')
    sev = _severity_for(spec, last, status)
    detail = str(rich.get('detail') or '') or (status or 'reported')
    return JobHealth(spec.key, spec.label, sev, last, detail, reported=True)


def _job_derived(spec: JobSpec, data: dict) -> JobHealth:
    """Best honest verdict from TODAY's heartbeat, per job. Where the data simply
    isn't there, return UNKNOWN/'not reported' — never a fabricated green."""
    if spec.key == 'fediverse':
        # Heartbeat reports fediverse_enabled + totals, but NOT the cron's last
        # run — so we can honestly say enabled/disabled, not fresh/stale.
        enabled = str(data.get('fediverse_enabled') or data.get('smackverse_enabled') or '0') in ('1', 'true', 'True')
        if not enabled:
            return JobHealth(spec.key, spec.label, SEV_NA, None,
                             'federation disabled', reported=True)
        return JobHealth(spec.key, spec.label, SEV_UNKNOWN, None,
                         'enabled, but last-run not reported', reported=False)

    # rss_fetch and version_check: no field in today's heartbeat.
    return JobHealth(spec.key, spec.label, SEV_UNKNOWN, None,
                     'not reported by this heartbeat', reported=False)
# ===== SNAPSMACK EOF =====
