"""
SmackPress — ai_client.py
Pluggable AI enhancement.  Supports Gemini, OpenAI, and Anthropic.
Provider is selected from config; if none, the rewrite step is skipped.

# ===== SNAPSMACK EOF =====
"""

from __future__ import annotations
import json
import urllib.request
import urllib.error
from typing import Any

import config


class AIError(Exception):
    pass


def available() -> bool:
    return config.get("ai_provider") != "none"


def rewrite(original_content: str, title: str = "") -> str:
    """
    Send the original WP post content to the configured AI provider and return
    the rewritten SMACKTALK-ready content.  Raises AIError on failure.
    """
    provider = config.get("ai_provider")
    if provider == "none":
        return original_content

    system_prompt = config.get("ai_system_prompt")
    user_message  = (
        f"Post title: {title}\n\n"
        f"Original content:\n{original_content}\n\n"
        "Rewrite this for the SMACKTALK blog. Output plain text paragraphs "
        "separated by blank lines. No markdown, no headers, no bullet points."
    )

    if provider == "gemini":
        return _gemini(system_prompt, user_message)
    elif provider == "openai":
        return _openai(system_prompt, user_message)
    elif provider == "anthropic":
        return _anthropic(system_prompt, user_message)
    else:
        raise AIError(f"Unknown AI provider: {provider}")


# --------------------------------------------------------------------------
# Provider implementations
# --------------------------------------------------------------------------

def _post_json(url: str, headers: dict, body: dict) -> Any:
    data = json.dumps(body).encode()
    req  = urllib.request.Request(url, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        msg = e.read().decode()
        raise AIError(f"HTTP {e.code}: {msg}")
    except urllib.error.URLError as e:
        raise AIError(f"Connection error: {e.reason}")


def _gemini(system_prompt: str, user_message: str) -> str:
    model   = config.get("ai_model") or "gemini-1.5-flash"
    api_key = config.get("ai_api_key")
    url     = (
        f"https://generativelanguage.googleapis.com/v1beta/models/{model}"
        f":generateContent?key={api_key}"
    )
    body = {
        "system_instruction": {"parts": [{"text": system_prompt}]},
        "contents": [{"parts": [{"text": user_message}]}],
        "generationConfig": {"temperature": 0.7, "maxOutputTokens": 4096},
    }
    result = _post_json(url, {"Content-Type": "application/json"}, body)
    try:
        return result["candidates"][0]["content"]["parts"][0]["text"].strip()
    except (KeyError, IndexError) as e:
        raise AIError(f"Unexpected Gemini response: {result}") from e


def _openai(system_prompt: str, user_message: str) -> str:
    model   = config.get("ai_model") or "gpt-4o"
    api_key = config.get("ai_api_key")
    body    = {
        "model": model,
        "messages": [
            {"role": "system",  "content": system_prompt},
            {"role": "user",    "content": user_message},
        ],
        "temperature": 0.7,
        "max_tokens": 4096,
    }
    result = _post_json(
        "https://api.openai.com/v1/chat/completions",
        {"Content-Type": "application/json",
         "Authorization": f"Bearer {api_key}"},
        body,
    )
    try:
        return result["choices"][0]["message"]["content"].strip()
    except (KeyError, IndexError) as e:
        raise AIError(f"Unexpected OpenAI response: {result}") from e


def _anthropic(system_prompt: str, user_message: str) -> str:
    model   = config.get("ai_model") or "claude-haiku-4-5-20251001"
    api_key = config.get("ai_api_key")
    body    = {
        "model": model,
        "max_tokens": 4096,
        "system": system_prompt,
        "messages": [{"role": "user", "content": user_message}],
    }
    result = _post_json(
        "https://api.anthropic.com/v1/messages",
        {
            "Content-Type":      "application/json",
            "x-api-key":         api_key,
            "anthropic-version": "2023-06-01",
        },
        body,
    )
    try:
        return result["content"][0]["text"].strip()
    except (KeyError, IndexError) as e:
        raise AIError(f"Unexpected Anthropic response: {result}") from e

# ===== SNAPSMACK EOF =====
