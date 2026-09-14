"""SNAP HQ's authoritative credential screen must expose safe live tests."""

from pathlib import Path


SOURCE = Path(__file__).resolve().parents[1] / "qt_main.py"


def test_settings_exposes_tests_for_each_ai_provider():
    source = SOURCE.read_text(encoding="utf-8")
    for key in ("gemini_api_key", "claude_api_key", "openai_api_key",
                "kimi_api_key", "deepseek_api_key"):
        assert f'"{key}":' in source
    assert 'QPushButton("TEST")' in source
    assert "No image or text was generated." in source


def test_gemini_test_is_non_generative():
    source = SOURCE.read_text(encoding="utf-8")
    assert "generativelanguage.googleapis.com/v1beta/models" in source
    assert "generateContent" not in source


# ===== SNAPSMACK EOF =====
