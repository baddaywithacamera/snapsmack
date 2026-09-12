"""Resume checkpoint regression tests."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import tempfile
import unittest
from pathlib import Path

from blogger_flogger.job_store import JobStore
from blogger_flogger.source_model import SourceEntry


class JobStoreTests(unittest.TestCase):
    def test_completed_item_survives_reopen_and_seed_is_idempotent(self):
        item = SourceEntry("post-1", "post", "T", "B", "", "", "draft", "",
                           source_sha256="a" * 64)
        with tempfile.TemporaryDirectory() as td:
            path = Path(td, "job.sqlite")
            first = JobStore(path); first.seed([item]); first.mark(
                item.source_id, "verified", destination_type="post", destination_id=91)
            first.close()
            second = JobStore(path); second.seed([item])
            self.assertEqual(second.state("post-1")["destination_id"], 91)
            self.assertEqual(second.summary(), {"verified": 1})
            second.close()


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
