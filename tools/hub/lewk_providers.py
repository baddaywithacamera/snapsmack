"""LEWK AGAIN language-model providers.

A thin, dependency-free (stdlib `urllib` only) client for the five providers
SnapSmack supports everywhere: Claude (Anthropic), ChatGPT (OpenAI), Gemini
(Google), Kimi (Moonshot), and Deepseek. Ported from the proven request shapes
in tools/smackpress/smackpress/ai_client.py, generalised to take explicit
arguments and a multi-turn message list so LEWK AGAIN can refine a look
conversationally.

Deliberately uses `urllib`, never `requests` — the SNAP SLAPPER build hard-
excludes `requests`, and this module must survive that exclusion so the editor
exe can talk to a model without carrying the fleet network stack.

No network happens on import; every call is explicit and unit-testable by
patching `_post_json`.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import json
import urllib.error
import urllib.request


HTTP_TIMEOUT = 90
DEFAULT_TEMPERATURE = 0.4
MAX_OUTPUT_TOKENS = 1024


class ProviderError(Exception):
    """A provider call failed in a way worth showing the photographer."""


class OfflineError(ProviderError):
    """The provider could not be reached at all (no network / DNS / refused)."""


class MissingKeyError(ProviderError):
    """No API key is configured for the chosen provider."""


# provider id -> UI label, default model, canonical family. Aliases map onto the
# canonical id so "claude"/"anthropic" and "chatgpt"/"openai" both resolve.
PROVIDERS = {
    "claude":   {"label": "Claude (Anthropic)", "model": "claude-haiku-4-5-20251001", "family": "anthropic"},
    "openai":   {"label": "ChatGPT (OpenAI)",   "model": "gpt-4o",        "family": "openai"},
    "gemini":   {"label": "Gemini (Google)",    "model": "gemini-flash-latest", "family": "gemini"},
    "kimi":     {"label": "Kimi (Moonshot)",    "model": "kimi-latest",   "family": "openai"},
    "deepseek": {"label": "Deepseek",           "model": "deepseek-chat", "family": "openai"},
}

_ALIASES = {
    "anthropic": "claude", "claude": "claude",
    "chatgpt": "openai", "gpt": "openai", "openai": "openai",
    "google": "gemini", "gemini": "gemini",
    "moonshot": "kimi", "kimi": "kimi",
    "deepseek": "deepseek",
}

# OpenAI-compatible chat-completions endpoints (openai, kimi, deepseek).
_OPENAI_ENDPOINTS = {
    "openai": "https://api.openai.com/v1/chat/completions",
    "kimi": "https://api.moonshot.ai/v1/chat/completions",
    "deepseek": "https://api.deepseek.com/v1/chat/completions",
}


def normalize_provider(provider):
    """Resolve an alias to a canonical provider id, or raise ProviderError."""
    key = (provider or "").strip().lower()
    if key in _ALIASES:
        return _ALIASES[key]
    raise ProviderError("Unknown provider: " + str(provider))


def provider_choices():
    """(id, label) pairs for a UI picker, in a stable order."""
    return [(pid, PROVIDERS[pid]["label"]) for pid in
            ("claude", "openai", "gemini", "kimi", "deepseek")]


def default_model(provider):
    return PROVIDERS[normalize_provider(provider)]["model"]


def _post_json(url, headers, body):
    """POST JSON and return the parsed reply. Raises OfflineError/ProviderError."""
    data = json.dumps(body).encode("utf-8")
    request = urllib.request.Request(url, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(request, timeout=HTTP_TIMEOUT) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        detail = ""
        try:
            detail = exc.read().decode("utf-8", "replace")[:400]
        except Exception:
            pass
        raise ProviderError("The model service returned HTTP %s. %s"
                            % (exc.code, detail)) from exc
    except urllib.error.URLError as exc:
        raise OfflineError("Could not reach the model service: %s. "
                           "Check your internet connection." % (exc.reason,)) from exc
    except (ValueError, TypeError) as exc:
        raise ProviderError("The model service returned an unreadable reply.") from exc


def _openai_family(family_id, model, api_key, system, turns, temperature):
    messages = [{"role": "system", "content": system}]
    messages.extend(turns)
    body = {"model": model, "messages": messages,
            "temperature": temperature, "max_tokens": MAX_OUTPUT_TOKENS}
    result = _post_json(_OPENAI_ENDPOINTS[family_id],
                        {"Content-Type": "application/json",
                         "Authorization": "Bearer " + api_key}, body)
    try:
        return result["choices"][0]["message"]["content"].strip()
    except (KeyError, IndexError, AttributeError) as exc:
        raise ProviderError("Unexpected reply from " + family_id + ".") from exc


def _anthropic(model, api_key, system, turns, temperature):
    body = {"model": model, "max_tokens": MAX_OUTPUT_TOKENS, "temperature": temperature,
            "system": system, "messages": turns}
    result = _post_json("https://api.anthropic.com/v1/messages",
                        {"Content-Type": "application/json", "x-api-key": api_key,
                         "anthropic-version": "2023-06-01"}, body)
    try:
        return result["content"][0]["text"].strip()
    except (KeyError, IndexError, AttributeError) as exc:
        raise ProviderError("Unexpected reply from Claude.") from exc


def _gemini(model, api_key, system, turns, temperature):
    # Gemini uses role "model" for the assistant and carries the system prompt
    # separately as system_instruction.
    contents = [{"role": "model" if t["role"] == "assistant" else "user",
                 "parts": [{"text": t["content"]}]} for t in turns]
    body = {"system_instruction": {"parts": [{"text": system}]},
            "contents": contents,
            "generationConfig": {"temperature": temperature,
                                 "maxOutputTokens": MAX_OUTPUT_TOKENS,
                                 "responseMimeType": "application/json"}}
    url = ("https://generativelanguage.googleapis.com/v1beta/models/%s"
           ":generateContent?key=%s" % (model, api_key))
    result = _post_json(url, {"Content-Type": "application/json"}, body)
    try:
        return result["candidates"][0]["content"]["parts"][0]["text"].strip()
    except (KeyError, IndexError, AttributeError) as exc:
        raise ProviderError("Unexpected reply from Gemini.") from exc


def call(provider, api_key, system, turns, model="", temperature=DEFAULT_TEMPERATURE):
    """Send `system` + `turns` (a [{role,content}] list) to `provider`.

    Returns the model's raw text reply. Raises MissingKeyError when no key is
    set, OfflineError when the service is unreachable, or ProviderError for any
    other failure. `turns` roles are "user"/"assistant".
    """
    pid = normalize_provider(provider)
    if not api_key:
        raise MissingKeyError("No API key is set for " + PROVIDERS[pid]["label"] + ".")
    model = model or PROVIDERS[pid]["model"]
    family = PROVIDERS[pid]["family"]
    if family == "anthropic":
        return _anthropic(model, api_key, system, turns, temperature)
    if family == "gemini":
        return _gemini(model, api_key, system, turns, temperature)
    return _openai_family(pid, model, api_key, system, turns, temperature)


# ===== SNAPSMACK EOF =====
