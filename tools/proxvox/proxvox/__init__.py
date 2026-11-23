"""PROX VOX — a controlled door into Sean's own server. (SS-1507)

P1 is read-only (ReadOnlyBroker).
P2 off-host sink lives in OffHostFileSink / flush_audit (disabled until OWNER RIGHTS closes).
P3 / P5 live-authorised writes and manual revoke live in WriteClient.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

from .broker import ReadOnlyBroker, Refused
from .sink import OffHostFileSink, build_sink, flush_audit
from .write_client import WriteClient

__all__ = [
    "OffHostFileSink",
    "ReadOnlyBroker",
    "Refused",
    "WriteClient",
    "build_sink",
    "flush_audit",
]

# ===== SNAPSMACK EOF =====
