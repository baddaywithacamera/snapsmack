"""PROX VOX — every call is written down, and nothing is ever dropped. (P1)

Sean's ruling: logs go off-host, append-only, to the radio-station PC, and
"if the radio box is down, buffer locally and flush later, never drop".

P1 SHIPS BEFORE THE OFF-HOST STORE EXISTS. Codex is building that share now.
So in P1 every record lands in the local buffer and stays there. That is not
the finished state and this module does not pretend otherwise: unflushed()
reports exactly how many records have never left this machine, and the P1
sign-off says "local only" out loud rather than letting a green test imply a
working off-host trail.

NEVER DROPPING IS THE WHOLE JOB. A log that quietly discards records under
pressure is worse than no log, because it reads as complete. So:
  - a record is appended before the call it describes is made, not after, so
    a crash mid-call leaves evidence that it was attempted;
  - a failed flush leaves the buffer untouched;
  - the buffer is only ever truncated for records the store has confirmed.
"""

import json
import os
import tempfile
import time


class AuditBuffer:
    """An append-only local file of one JSON record per line."""

    def __init__(self, path: str):
        self.path = path
        parent = os.path.dirname(os.path.abspath(path))
        if parent:
            os.makedirs(parent, exist_ok=True)

    # ── writing ──────────────────────────────────────────────────────────
    def record(self, action: str, detail: dict = None) -> dict:
        """Append one record and return it.

        Opened in append mode and flushed to the OS on every write. This is a
        handful of bytes per API call, so buffering them for speed would trade
        the only property that matters for a saving nobody asked for.
        """
        entry = {
            "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "action": action,
            "detail": detail or {},
        }
        line = json.dumps(entry, sort_keys=True, separators=(",", ":"))
        with open(self.path, "a", encoding="utf-8", newline="\n") as fh:
            fh.write(line + "\n")
            fh.flush()
            os.fsync(fh.fileno())
        return entry

    # ── reading ──────────────────────────────────────────────────────────
    def records(self) -> list:
        if not os.path.exists(self.path):
            return []
        out = []
        with open(self.path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                try:
                    out.append(json.loads(line))
                except ValueError:
                    # A half-written final line from a crash is kept as a raw
                    # record rather than discarded. Losing it would be the one
                    # thing this file exists to prevent.
                    out.append({"at": "", "action": "unparsable", "detail": {"raw": line}})
        return out

    def unflushed(self) -> int:
        return len(self.records())

    # ── flushing ─────────────────────────────────────────────────────────
    def flush_to(self, sink) -> int:
        """Hand every buffered record to `sink`, and truncate only on success.

        `sink` takes the list of records and returns True when it has durably
        stored all of them. Anything else -- False, an exception, a partial --
        leaves the buffer exactly as it was. A store that is down therefore
        costs disk, never evidence.
        """
        pending = self.records()
        if not pending:
            return 0
        try:
            ok = sink(pending)
        except Exception:
            return 0
        if ok is not True:
            return 0

        # Written to a temp file and moved into place, so an interruption
        # during truncation cannot leave a half-emptied buffer.
        fd, tmp = tempfile.mkstemp(dir=os.path.dirname(os.path.abspath(self.path)))
        os.close(fd)
        os.replace(tmp, self.path)
        return len(pending)

# ===== SNAPSMACK EOF =====
