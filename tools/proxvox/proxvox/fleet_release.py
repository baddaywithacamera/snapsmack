"""Resume the approved fleet rollout through the current PROX VOX host session.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

import time

from .broker import Refused

VERSION = "0.7.1009D"
POLL_SECONDS = 5
MAX_MISSED_POLLS = 3


def run_fleet_release(client, sites, update_status):
    """Run one site at a time, reattaching to previously started work."""
    for index, site in enumerate(sites, 1):
        update_status(f"Checking {site} ({index} of {len(sites)})…")
        start_attempted = False
        start_error = None
        missed_polls = 0
        while client.is_authorized():
            try:
                result = client.signed_release_status(site, VERSION)
            except Refused as error:
                missed_polls += 1
                if missed_polls >= MAX_MISSED_POLLS:
                    raise RuntimeError(f"{site} status unavailable. Recheck with this session. {error}") from error
                update_status(f"Reconnecting to check {site}…")
                time.sleep(POLL_SECONDS)
                continue
            missed_polls = 0
            if result["state"] == "pending":
                if start_attempted:
                    raise RuntimeError(f"{site} did not start. {start_error or 'Use Resume to check again.'}")
                start_attempted = True
                update_status(f"Updating {site} ({index} of {len(sites)})…")
                try:
                    result = client.start_signed_release(site, VERSION)
                except Refused as error:
                    # The receipt may have been lost after launch. Only poll.
                    start_error = error
                    continue
            state = result["state"]
            if state == "verified":
                receipt = result["receipt"]
                if receipt.get("site") != site or receipt.get("version") != VERSION or receipt.get("http") != 200:
                    raise RuntimeError(f"{site} could not be verified.")
                break
            if state not in {"running", "busy"}:
                raise RuntimeError(f"{site} stopped. {result.get('error', state)}")
            time.sleep(POLL_SECONDS)
        else:
            raise RuntimeError(f"PROX VOX authorization expired while checking {site}.")
    return len(sites)


# ===== SNAPSMACK EOF =====
