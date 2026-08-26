"""Regression checks for LEWK AGAIN — the AI-assisted LEWK builder.

These never touch the network: the provider layer's single HTTP helper
(`lewk_providers._post_json`) is patched, so every provider path is exercised
offline and deterministically.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import os
import sys
import unittest
from unittest import mock

from PIL import Image

HUB = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "hub"))
if HUB not in sys.path:
    sys.path.insert(0, HUB)

import editor_engine
import lewk_again
import lewk_providers


class SanitizeTests(unittest.TestCase):
    def test_unknown_keys_and_code_are_dropped(self):
        raw = {"contrast": 20, "os.system": "rm -rf /", "script": "print(1)",
               "__import__": "os", "nonsense": {"nested": 1}}
        clean = lewk_again.sanitize_adjustments(raw)
        self.assertEqual(set(clean), {"contrast"})
        self.assertEqual(clean["contrast"], 20.0)

    def test_values_are_clamped_into_range(self):
        clean = lewk_again.sanitize_adjustments(
            {"exposure": 99, "contrast": -500, "grain": 250, "sharpen": -10})
        self.assertEqual(clean["exposure"], 2.0)
        self.assertEqual(clean["contrast"], -100.0)
        self.assertEqual(clean["grain"], 100.0)
        self.assertNotIn("sharpen", clean)  # clamped to 0 == neutral, so omitted

    def test_neutral_values_are_omitted(self):
        clean = lewk_again.sanitize_adjustments({"contrast": 0, "vibrance": 12})
        self.assertNotIn("contrast", clean)
        self.assertEqual(clean["vibrance"], 12.0)

    def test_black_white_is_coerced_to_bool(self):
        self.assertTrue(lewk_again.sanitize_adjustments({"black_white": "yes"})["black_white"])
        self.assertNotIn("black_white", lewk_again.sanitize_adjustments({"black_white": ""}))

    def test_numbers_in_strings_are_recovered(self):
        clean = lewk_again.sanitize_adjustments({"temperature": "-22 (cool)"})
        self.assertEqual(clean["temperature"], -22.0)

    def test_curve_is_validated_and_bounded(self):
        clean = lewk_again.sanitize_adjustments(
            {"curve": [[0, 999], [128, 130], [999, -5]]})
        curve = clean["curve"]
        self.assertTrue(all(0 <= x <= 255 and 0 <= y <= 255 for x, y in curve))
        self.assertEqual(curve[0][0], 0)
        self.assertEqual(curve[-1][0], 255)

    def test_bad_curve_is_dropped(self):
        self.assertNotIn("curve", lewk_again.sanitize_adjustments({"curve": "S-curve"}))
        self.assertNotIn("curve", lewk_again.sanitize_adjustments({"curve": [[1]]}))

    def test_non_dict_raises(self):
        with self.assertRaises(lewk_again.ModelResponseError):
            lewk_again.sanitize_adjustments("contrast=20")


class BuildLewkTests(unittest.TestCase):
    def test_valid_proposal_becomes_a_recipe(self):
        proposal = {"name": "Frost", "category": "Landscape + Weather",
                    "description": "cold air", "adjustments": {"temperature": -22, "clarity": 10}}
        recipe = lewk_again.build_lewk(proposal, strength=70, now_ns=42)
        self.assertEqual(recipe["version"], editor_engine.PROJECT_VERSION)
        self.assertEqual(len(recipe["layers"]), 1)
        layer = recipe["layers"][0]
        self.assertEqual(layer["type"], "adjustment")
        self.assertEqual(layer["opacity"], 0.7)
        self.assertEqual(layer["lewk"]["provenance"], "lewk-again")
        self.assertEqual(recipe["adjustments"], editor_engine.DEFAULT_ADJUSTMENTS)

    def test_strength_is_bounded(self):
        p = {"adjustments": {"contrast": 10}}
        self.assertEqual(lewk_again.build_lewk(p, strength=500)["layers"][0]["opacity"], 1.0)
        self.assertEqual(lewk_again.build_lewk(p, strength=-5)["layers"][0]["opacity"], 0.0)

    def test_empty_adjustments_rejected(self):
        with self.assertRaises(lewk_again.ModelResponseError):
            lewk_again.build_lewk({"adjustments": {"unknown": 1}})

    def test_blend_mode_is_validated(self):
        good = lewk_again.build_lewk({"adjustments": {"contrast": 5}, "blend": "screen"})
        self.assertEqual(good["layers"][0]["blend"], "screen")
        bad = lewk_again.build_lewk({"adjustments": {"contrast": 5}, "blend": "explode"})
        self.assertEqual(bad["layers"][0]["blend"], "normal")


class ParseTests(unittest.TestCase):
    def test_fenced_json(self):
        self.assertEqual(lewk_again.parse_model_json('```json\n{"a":1}\n```'), {"a": 1})

    def test_prose_wrapped_json(self):
        self.assertEqual(
            lewk_again.parse_model_json('Sure! {"adjustments": {"contrast": 9}} enjoy'),
            {"adjustments": {"contrast": 9}})

    def test_empty_and_invalid_raise(self):
        for bad in ("", "no json here", "{not valid}", "[1,2,3]"):
            with self.assertRaises(lewk_again.ModelResponseError):
                lewk_again.parse_model_json(bad)


class RenderIntegrationTests(unittest.TestCase):
    """A generated LEWK must apply and render like any other, non-destructively."""

    def _document(self):
        return editor_engine.EditorDocument("no-such-file.jpg")

    def test_generated_lewk_applies_and_preserves_base(self):
        document = self._document()
        document.adjustments["exposure"] = 0.3
        recipe = lewk_again.build_lewk(
            {"adjustments": {"contrast": 18, "grain": 12, "black_white": True}}, strength=90)
        document.stack_layers(recipe["layers"])
        # Base edit survives; exactly one LEWK layer added (my non-destructive fix).
        self.assertEqual(document.adjustments["exposure"], 0.3)
        self.assertEqual(len(document.layers), 1)

    def test_generated_adjustments_render(self):
        source = Image.new("RGB", (64, 48), (120, 130, 150))
        recipe = lewk_again.build_lewk({"adjustments": {"contrast": 20, "vignette": -15}})
        out = editor_engine.apply_adjustments(source, recipe["layers"][0]["adjustments"])
        self.assertEqual(out.size, source.size)


class ProviderTests(unittest.TestCase):
    SYSTEM = "sys"
    TURNS = [{"role": "user", "content": "make it warm"}]

    def _run(self, provider, fake_reply):
        captured = {}

        def fake_post(url, headers, body):
            captured["url"] = url
            captured["headers"] = headers
            captured["body"] = body
            return fake_reply

        with mock.patch.object(lewk_providers, "_post_json", fake_post):
            text = lewk_providers.call(provider, "KEY123", self.SYSTEM, self.TURNS)
        return text, captured

    def test_claude_request_and_parse(self):
        text, cap = self._run("claude", {"content": [{"text": "hi"}]})
        self.assertEqual(text, "hi")
        self.assertIn("anthropic.com", cap["url"])
        self.assertEqual(cap["headers"]["x-api-key"], "KEY123")
        self.assertEqual(cap["body"]["system"], "sys")

    def test_openai_request_and_parse(self):
        text, cap = self._run("chatgpt", {"choices": [{"message": {"content": "yo"}}]})
        self.assertEqual(text, "yo")
        self.assertIn("openai.com", cap["url"])
        self.assertEqual(cap["headers"]["Authorization"], "Bearer KEY123")
        self.assertEqual(cap["body"]["messages"][0]["role"], "system")

    def test_gemini_request_and_parse(self):
        text, cap = self._run(
            "gemini", {"candidates": [{"content": {"parts": [{"text": "gz"}]}}]})
        self.assertEqual(text, "gz")
        self.assertIn("generativelanguage.googleapis.com", cap["url"])
        self.assertIn("KEY123", cap["url"])  # gemini carries the key in the URL

    def test_kimi_and_deepseek_use_openai_shape(self):
        for prov, host in (("kimi", "moonshot.ai"), ("deepseek", "deepseek.com")):
            text, cap = self._run(prov, {"choices": [{"message": {"content": "ok"}}]})
            self.assertEqual(text, "ok")
            self.assertIn(host, cap["url"])

    def test_missing_key_raises(self):
        with self.assertRaises(lewk_providers.MissingKeyError):
            lewk_providers.call("claude", "", self.SYSTEM, self.TURNS)

    def test_unknown_provider_raises(self):
        with self.assertRaises(lewk_providers.ProviderError):
            lewk_providers.normalize_provider("bard")

    def test_all_five_have_choices(self):
        ids = {pid for pid, _ in lewk_providers.provider_choices()}
        self.assertEqual(ids, {"claude", "openai", "gemini", "kimi", "deepseek"})


class KeyResolutionTests(unittest.TestCase):
    def test_env_var_wins(self):
        with mock.patch.dict(os.environ, {"ANTHROPIC_API_KEY": "envkey"}, clear=False):
            self.assertEqual(lewk_again.resolve_key("claude", {"keys": {}}), "envkey")

    def test_local_config_fallback_roundtrip(self):
        cfg = {"provider": "openai", "keys": {"openai": "sk-local"}, "models": {}}
        with mock.patch.dict(os.environ, {}, clear=True):
            # snap_creds import will fail in this test env -> falls through to local
            self.assertEqual(lewk_again.resolve_key("openai", cfg), "sk-local")

    def test_encode_decode_key(self):
        enc = lewk_again._encode_local_key("secret")
        self.assertTrue(enc.startswith("b64:"))
        self.assertEqual(lewk_again._decode_local_key(enc), "secret")


class GenerateTests(unittest.TestCase):
    def test_generate_end_to_end(self):
        reply = {"content": [{"text": '{"name":"Warm","category":"Portrait",'
                              '"description":"cosy","adjustments":{"temperature":15,"contrast":8}}'}]}
        with mock.patch.object(lewk_providers, "_post_json", return_value=reply):
            recipe, proposal = lewk_again.generate(
                "cosy warm portrait", "claude", api_key="KEY", config={"keys": {}, "models": {}})
        self.assertEqual(proposal["name"], "Warm")
        self.assertEqual(len(recipe["layers"]), 1)
        self.assertAlmostEqual(recipe["layers"][0]["adjustments"]["temperature"], 15.0)

    def test_generate_requires_a_key(self):
        with mock.patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(lewk_providers.MissingKeyError):
                lewk_again.generate("x", "claude", config={"keys": {}, "models": {}})

    def test_refinement_builds_multi_turn(self):
        previous = {"adjustments": {"contrast": 10}}
        turns = lewk_again.build_messages("base look", refinement="warmer", previous=previous)
        self.assertEqual(len(turns), 3)
        self.assertEqual(turns[1]["role"], "assistant")
        self.assertIn("warmer", turns[2]["content"])


class EntitlementTests(unittest.TestCase):
    def test_beta_is_unlocked(self):
        state = lewk_again.entitlement_state()
        self.assertTrue(state["unlocked"])
        self.assertEqual(lewk_again.require_unlocked()["status"], "beta")


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
