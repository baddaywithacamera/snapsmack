"""PROX VOX — P2 off-host append-only audit sink. (SS-1507 P2)

Sean's ruling: logs go off-host, append-only, to \\\\SQFM_MAIN\\proxvox-logs,
and "if the radio box is down, buffer locally and flush later, never drop".

IMPORTANT: off-host flushing defaults to DISABLED (offhost_sink_enabled=False)
until Codex closes the OWNER RIGHTS (S-1-3-4) ACE finding on \\\\SQFM_MAIN\\proxvox-logs
and re-runs the proofs. Until enabled, every record remains in AuditBuffer locally.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import os

from .audit import AuditBuffer

DEFAULT_OFFHOST_SINK_DIR = r"\\SQFM_MAIN\proxvox-logs"
DEFAULT_SINK_FILENAME = "proxvox-audit.jsonl"


class OffHostFileSink:
    """Append-only JSONL sink targeting the off-host SMB share (or test dir)."""

    def __init__(self, sink_dir: str = DEFAULT_OFFHOST_SINK_DIR,
                 filename: str = DEFAULT_SINK_FILENAME, *,
                 enabled: bool = False):
        self.sink_dir = (sink_dir or "").strip()
        self.filename = (filename or DEFAULT_SINK_FILENAME).strip()
        self.enabled = bool(enabled)

    @property
    def target_path(self) -> str:
        if not self.sink_dir:
            return ""
        return os.path.join(self.sink_dir, self.filename)

    def __call__(self, records: list) -> bool:
        """Append all `records` to the off-host file and fsync.

        Returns True only when every record is durably written and synced,
        so AuditBuffer.flush_to() never truncates the local buffer on failure.
        """
        if not self.enabled or not self.sink_dir or not records:
            return False
        if not os.path.isdir(self.sink_dir):
            return False
        target = self.target_path
        try:
            with open(target, "a", encoding="utf-8", newline="\n") as fh:
                for entry in records:
                    line = json.dumps(entry, sort_keys=True, separators=(",", ":"))
                    fh.write(line + "\n")
                fh.flush()
                os.fsync(fh.fileno())
            return True
        except OSError:
            return False


def build_sink(config: dict = None) -> OffHostFileSink:
    cfg = config or {}
    sink_dir = str(cfg.get("offhost_sink_dir") or DEFAULT_OFFHOST_SINK_DIR).strip()
    filename = str(cfg.get("offhost_sink_filename") or DEFAULT_SINK_FILENAME).strip()
    enabled = bool(cfg.get("offhost_sink_enabled", False))
    return OffHostFileSink(sink_dir=sink_dir, filename=filename, enabled=enabled)


def flush_audit(audit: AuditBuffer, config: dict = None) -> dict:
    """Attempt to flush `audit` to the configured P2 sink without dropping on failure."""
    sink = build_sink(config)
    flushed = audit.flush_to(sink) if sink.enabled else 0
    return {
        "flushed": flushed,
        "unflushed": audit.unflushed(),
        "sink_enabled": sink.enabled,
        "sink_dir": sink.sink_dir,
    }

# ===== SNAPSMACK EOF =====
