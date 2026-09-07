"""COLD SNAP reads the destination CMS publishing policy fail-closed."""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(
    os.path.abspath(__file__)))), "_shared"))

import sumna_post as P


class _Response:
    def raise_for_status(self):
        return None

    def json(self):
        return {"publishing_policy": {
            "download_link_required": True,
            "download_default_mode": "all_posts",
        }}


if __name__ == "__main__":
    conn = P.SumnaConnection("https://example.test", "key")
    conn.session.get = lambda *args, **kwargs: _Response()
    policy = conn.publishing_policy()
    assert policy["download_link_required"] is True
    assert policy["download_default_mode"] == "all_posts"
    print("OK — CMS publishing policy read")
