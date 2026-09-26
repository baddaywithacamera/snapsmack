"""B2Client.list_files must follow nextFileName past 10,000 names.

It made one b2_list_file_names call, so a bucket over 10,000 files was cut
short without a word: Manage showed part of the list and Cloud Sync treated
the rest of the destination as missing.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import cloud_client


class _Resp:
    def __init__(self, payload):
        self._payload = payload

    def raise_for_status(self):
        pass

    def json(self):
        return self._payload


def test_list_files_reads_every_page(monkeypatch):
    pages = {
        None: {"files": [{"fileName": f"a{i:05d}.jpg", "fileId": f"id{i}", "contentLength": 1,
                          "contentSha1": "x"} for i in range(10000)],
               "nextFileName": "b00000.jpg"},
        "b00000.jpg": {"files": [{"fileName": f"b{i:05d}.jpg", "fileId": f"idb{i}", "contentLength": 1,
                                  "contentSha1": "x"} for i in range(2500)],
                       "nextFileName": None},
    }
    calls = []

    def fake_post(url, headers=None, json=None, timeout=None):
        calls.append(json.get("startFileName"))
        return _Resp(pages[json.get("startFileName")])

    import requests
    monkeypatch.setattr(requests, "post", fake_post)
    client = cloud_client.B2Client("kid", "key", "bucket")
    monkeypatch.setattr(client, "_auth", lambda: {"apiUrl": "https://b2.test", "authorizationToken": "t"})
    monkeypatch.setattr(client, "_bucket_id", lambda: "bid")

    files = client.list_files()
    assert len(files) == 12500
    assert calls == [None, "b00000.jpg"]

# ===== SNAPSMACK EOF =====
