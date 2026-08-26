"""Regression checks for snap_ai — SnapSmack's shared 5-provider AI client.

Never touches the network: the single HTTP helper (`snap_ai._post_json`) is
patched, so every provider's text and vision request shape is exercised offline.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import io
import os
import sys
import unittest
from unittest import mock

from PIL import Image

SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "_shared"))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import snap_ai


def _png_bytes(size=(2000, 20)):
    buffer = io.BytesIO()
    Image.new("RGB", size, (10, 20, 30)).save(buffer, "PNG")
    return buffer.getvalue()


class RegistryTests(unittest.TestCase):
    def test_all_five_present_and_ordered(self):
        ids = [pid for pid, _ in snap_ai.provider_choices()]
        self.assertEqual(ids, ["claude", "openai", "gemini", "kimi", "deepseek"])

    def test_aliases_resolve(self):
        self.assertEqual(snap_ai.normalize_provider("anthropic"), "claude")
        self.assertEqual(snap_ai.normalize_provider("ChatGPT"), "openai")
        self.assertEqual(snap_ai.normalize_provider("google"), "gemini")
        self.assertEqual(snap_ai.normalize_provider("moonshot"), "kimi")

    def test_unknown_raises(self):
        with self.assertRaises(snap_ai.ProviderError):
            snap_ai.normalize_provider("llama")

    def test_vision_capability_flags(self):
        self.assertTrue(snap_ai.supports_vision("claude"))
        self.assertTrue(snap_ai.supports_vision("gemini"))
        self.assertFalse(snap_ai.supports_vision("deepseek"))


class TextCallTests(unittest.TestCase):
    SYSTEM = "sys"
    TURNS = [{"role": "user", "content": "hello"}]

    def _run(self, provider, reply):
        captured = {}

        def fake(url, headers, body):
            captured.update(url=url, headers=headers, body=body)
            return reply

        with mock.patch.object(snap_ai, "_post_json", fake):
            text = snap_ai.call(provider, "KEY", self.SYSTEM, self.TURNS)
        return text, captured

    def test_claude(self):
        text, cap = self._run("claude", {"content": [{"text": "hi"}]})
        self.assertEqual(text, "hi")
        self.assertIn("anthropic.com", cap["url"])
        self.assertEqual(cap["headers"]["x-api-key"], "KEY")
        self.assertEqual(cap["body"]["system"], "sys")

    def test_openai(self):
        text, cap = self._run("openai", {"choices": [{"message": {"content": "yo"}}]})
        self.assertEqual(text, "yo")
        self.assertIn("openai.com", cap["url"])
        self.assertEqual(cap["headers"]["Authorization"], "Bearer KEY")

    def test_gemini(self):
        text, cap = self._run("gemini", {"candidates": [{"content": {"parts": [{"text": "g"}]}}]})
        self.assertEqual(text, "g")
        self.assertIn("generativelanguage.googleapis.com", cap["url"])
        self.assertIn("KEY", cap["url"])

    def test_kimi_and_deepseek(self):
        for prov, host in (("kimi", "moonshot.ai"), ("deepseek", "deepseek.com")):
            text, cap = self._run(prov, {"choices": [{"message": {"content": "ok"}}]})
            self.assertEqual(text, "ok")
            self.assertIn(host, cap["url"])

    def test_missing_key(self):
        with self.assertRaises(snap_ai.MissingKeyError):
            snap_ai.call("claude", "", self.SYSTEM, self.TURNS)


class VisionCallTests(unittest.TestCase):
    SYSTEM = "describe"
    TURNS = [{"role": "user", "content": "what is this?"}]
    IMAGES = [{"data": "QUJD", "mime": "image/jpeg"}]

    def _capture(self, provider, reply):
        captured = {}

        def fake(url, headers, body):
            captured.update(body=body)
            return reply

        with mock.patch.object(snap_ai, "_post_json", fake):
            snap_ai.call(provider, "KEY", self.SYSTEM, self.TURNS, images=self.IMAGES)
        return captured["body"]

    def test_claude_attaches_image_block(self):
        body = self._capture("claude", {"content": [{"text": "a bear"}]})
        content = body["messages"][0]["content"]
        self.assertEqual(content[0]["type"], "text")
        self.assertEqual(content[1]["type"], "image")
        self.assertEqual(content[1]["source"]["data"], "QUJD")

    def test_openai_attaches_image_url(self):
        body = self._capture("openai", {"choices": [{"message": {"content": "x"}}]})
        # messages[0] is the system turn; the user turn carries the image
        user = body["messages"][1]["content"]
        self.assertEqual(user[0]["type"], "text")
        self.assertEqual(user[1]["type"], "image_url")
        self.assertTrue(user[1]["image_url"]["url"].startswith("data:image/jpeg;base64,QUJD"))

    def test_gemini_attaches_inline_data(self):
        body = self._capture("gemini", {"candidates": [{"content": {"parts": [{"text": "x"}]}}]})
        parts = body["contents"][0]["parts"]
        self.assertEqual(parts[0]["text"], "what is this?")
        self.assertEqual(parts[1]["inline_data"]["data"], "QUJD")

    def test_deepseek_rejects_images(self):
        with self.assertRaises(snap_ai.ProviderError):
            snap_ai.call("deepseek", "KEY", self.SYSTEM, self.TURNS, images=self.IMAGES)


class EncodeImageTests(unittest.TestCase):
    def test_downscales_and_returns_jpeg_part(self):
        part = snap_ai.encode_image(_png_bytes((2000, 20)), max_px=256)
        self.assertEqual(part["mime"], "image/jpeg")
        self.assertTrue(part["data"])
        # decode it back and confirm it was scaled to <= 256 on the long edge
        import base64
        image = Image.open(io.BytesIO(base64.b64decode(part["data"])))
        self.assertLessEqual(max(image.size), 256)

    def test_bad_image_raises(self):
        with self.assertRaises(snap_ai.ProviderError):
            snap_ai.encode_image(b"not an image")


class ResolveKeyTests(unittest.TestCase):
    def test_env_var_wins(self):
        with mock.patch.dict(os.environ, {"DEEPSEEK_API_KEY": "envk"}, clear=False):
            self.assertEqual(snap_ai.resolve_key("deepseek"), "envk")

    def test_local_fallback(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            self.assertEqual(snap_ai.resolve_key("openai", local={"openai": "sk-x"}), "sk-x")

    def test_absent_is_empty(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            # kimi has no env/local here; vault import may or may not resolve, so
            # only assert it does not raise and returns a string
            self.assertIsInstance(snap_ai.resolve_key("kimi", local={}), str)


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
