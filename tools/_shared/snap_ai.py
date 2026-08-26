"""snap_ai — SnapSmack's one shared language-model client.

Every SnapSmack tool that talks to an AI model uses this, so all five providers
are supported *everywhere*: Claude (Anthropic), ChatGPT (OpenAI), Gemini
(Google), Kimi (Moonshot), and Deepseek. Offering non-North-American models
(Kimi, Deepseek, Gemini) alongside Claude/OpenAI is a reach requirement — a
Claude/OpenAI-only feature quietly excludes users outside North America who will
not use NA-only AI tools.

Text and vision in one call. `call(...)` sends a system prompt plus a multi-turn
message list; pass `images` to attach pictures to the final user turn for the
providers that accept them.

Dependency-free on the wire: stdlib `urllib` only, never `requests`, so this
module can be bundled into the locked-down SNAP SLAPPER editor build. The shared
credential vault is imported lazily inside `resolve_key`, so a build that
excludes the vault still works (it just falls back to env vars or a caller's
local config); the vault is never *forced* into that build.

No network happens on import; every call is explicit and unit-testable by
patching `_post_json`.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import base64
import io
import json
import os
import urllib.error
import urllib.request


HTTP_TIMEOUT = 90
DEFAULT_TEMPERATURE = 0.4
MAX_OUTPUT_TOKENS = 1024
IMAGE_MAX_PX = 1024
IMAGE_QUALITY = 85


class ProviderError(Exception):
    """A provider call failed in a way worth showing the user."""


class OfflineError(ProviderError):
    """The provider could not be reached at all (no network / DNS / refused)."""


class MissingKeyError(ProviderError):
    """No API key is configured for the chosen provider."""


# canonical id -> UI label, default model, request family, whether it accepts
# images. Model ids favour "latest" aliases where a provider offers one, because
# pinned ids rot (the old gemini-1.5-flash now 404s on the live API).
PROVIDERS = {
    "claude":   {"label": "Claude (Anthropic)", "model": "claude-haiku-4-5-20251001",
                 "family": "anthropic", "vision": True},
    "openai":   {"label": "ChatGPT (OpenAI)", "model": "gpt-4o",
                 "family": "openai", "vision": True},
    "gemini":   {"label": "Gemini (Google)", "model": "gemini-flash-latest",
                 "family": "gemini", "vision": True},
    "kimi":     {"label": "Kimi (Moonshot)", "model": "kimi-latest",
                 "family": "openai", "vision": True},
    "deepseek": {"label": "Deepseek", "model": "deepseek-chat",
                 "family": "openai", "vision": False},
}

_ORDER = ("claude", "openai", "gemini", "kimi", "deepseek")

_ALIASES = {
    "anthropic": "claude", "claude": "claude",
    "chatgpt": "openai", "gpt": "openai", "openai": "openai",
    "google": "gemini", "gemini": "gemini",
    "moonshot": "kimi", "kimi": "kimi",
    "deepseek": "deepseek",
}

_OPENAI_ENDPOINTS = {
    "openai": "https://api.openai.com/v1/chat/completions",
    "kimi": "https://api.moonshot.ai/v1/chat/completions",
    "deepseek": "https://api.deepseek.com/v1/chat/completions",
}

# Where each provider's key lives in the shared Hub vault, and the environment
# variables checked first (SnapSmack-specific, then the provider's conventional).
VAULT_KEYS = {
    "claude": "anthropic_api_key", "openai": "openai_api_key",
    "gemini": "gemini_api_key", "kimi": "kimi_api_key", "deepseek": "deepseek_api_key",
}
ENV_KEYS = {
    "claude":   ("SNAP_ANTHROPIC_API_KEY", "ANTHROPIC_API_KEY", "CLAUDE_API_KEY"),
    "openai":   ("SNAP_OPENAI_API_KEY", "OPENAI_API_KEY"),
    "gemini":   ("SNAP_GEMINI_API_KEY", "GEMINI_API_KEY", "GOOGLE_API_KEY"),
    "kimi":     ("SNAP_KIMI_API_KEY", "MOONSHOT_API_KEY", "KIMI_API_KEY"),
    "deepseek": ("SNAP_DEEPSEEK_API_KEY", "DEEPSEEK_API_KEY"),
}


def normalize_provider(provider):
    """Resolve an alias to a canonical provider id, or raise ProviderError."""
    key = (provider or "").strip().lower()
    if key in _ALIASES:
        return _ALIASES[key]
    raise ProviderError("Unknown provider: " + str(provider))


def provider_choices():
    """(id, label) pairs for a UI picker, in a stable order."""
    return [(pid, PROVIDERS[pid]["label"]) for pid in _ORDER]


def default_model(provider):
    return PROVIDERS[normalize_provider(provider)]["model"]


def supports_vision(provider):
    return PROVIDERS[normalize_provider(provider)]["vision"]


def resolve_key(provider, local=None):
    """Return the API key for `provider`: environment variable first, then the
    shared Hub vault (imported lazily so an excluded-vault build still works),
    then a caller-supplied `local` mapping of {provider_id: key}. '' if unset."""
    pid = normalize_provider(provider)
    for var in ENV_KEYS.get(pid, ()):
        value = os.environ.get(var)
        if value and value.strip():
            return value.strip()
    try:
        import snap_creds
        if hasattr(snap_creds, "init"):
            try:
                snap_creds.init()
            except Exception:
                pass
        value = snap_creds.get(VAULT_KEYS[pid], "")
        if value and value.strip():
            return value.strip()
    except Exception:
        pass
    if local:
        value = local.get(pid) or ""
        if value:
            return value
    return ""


# --- images ----------------------------------------------------------------
def encode_image(source, max_px=IMAGE_MAX_PX, quality=IMAGE_QUALITY):
    """Downscale an image to a base64 JPEG part: {"data","mime"}.

    `source` is a file path or raw bytes. Kept small so uploads stay cheap.
    Raises ProviderError if Pillow is unavailable or the image cannot be read.
    """
    try:
        from PIL import Image
    except Exception as exc:  # pragma: no cover - Pillow always present in tools
        raise ProviderError("Image support needs Pillow.") from exc
    try:
        handle = io.BytesIO(source) if isinstance(source, (bytes, bytearray)) else source
        image = Image.open(handle).convert("RGB")
        image.thumbnail((max_px, max_px))
        buffer = io.BytesIO()
        image.save(buffer, "JPEG", quality=quality)
    except Exception as exc:
        raise ProviderError("Could not read that image.") from exc
    return {"data": base64.b64encode(buffer.getvalue()).decode("ascii"),
            "mime": "image/jpeg"}


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


def _last_user_index(turns):
    for index in range(len(turns) - 1, -1, -1):
        if turns[index].get("role") == "user":
            return index
    return len(turns) - 1


def _openai_family(family_id, model, api_key, system, turns, temperature, images):
    messages = [{"role": "system", "content": system}]
    target = _last_user_index(turns)
    for index, turn in enumerate(turns):
        if images and index == target:
            content = [{"type": "text", "text": turn["content"]}]
            for image in images:
                content.append({"type": "image_url", "image_url": {
                    "url": "data:%s;base64,%s" % (image["mime"], image["data"])}})
            messages.append({"role": turn["role"], "content": content})
        else:
            messages.append({"role": turn["role"], "content": turn["content"]})
    body = {"model": model, "messages": messages,
            "temperature": temperature, "max_tokens": MAX_OUTPUT_TOKENS}
    result = _post_json(_OPENAI_ENDPOINTS[family_id],
                        {"Content-Type": "application/json",
                         "Authorization": "Bearer " + api_key}, body)
    try:
        return result["choices"][0]["message"]["content"].strip()
    except (KeyError, IndexError, AttributeError) as exc:
        raise ProviderError("Unexpected reply from " + family_id + ".") from exc


def _anthropic(model, api_key, system, turns, temperature, images):
    target = _last_user_index(turns)
    messages = []
    for index, turn in enumerate(turns):
        if images and index == target:
            content = [{"type": "text", "text": turn["content"]}]
            for image in images:
                content.append({"type": "image", "source": {
                    "type": "base64", "media_type": image["mime"], "data": image["data"]}})
            messages.append({"role": turn["role"], "content": content})
        else:
            messages.append({"role": turn["role"], "content": turn["content"]})
    body = {"model": model, "max_tokens": MAX_OUTPUT_TOKENS, "temperature": temperature,
            "system": system, "messages": messages}
    result = _post_json("https://api.anthropic.com/v1/messages",
                        {"Content-Type": "application/json", "x-api-key": api_key,
                         "anthropic-version": "2023-06-01"}, body)
    try:
        return result["content"][0]["text"].strip()
    except (KeyError, IndexError, AttributeError) as exc:
        raise ProviderError("Unexpected reply from Claude.") from exc


def _gemini(model, api_key, system, turns, temperature, images):
    target = _last_user_index(turns)
    contents = []
    for index, turn in enumerate(turns):
        parts = [{"text": turn["content"]}]
        if images and index == target:
            for image in images:
                parts.append({"inline_data": {"mime_type": image["mime"],
                                               "data": image["data"]}})
        contents.append({"role": "model" if turn["role"] == "assistant" else "user",
                         "parts": parts})
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


def call(provider, api_key, system, turns, model="", temperature=DEFAULT_TEMPERATURE,
         images=None):
    """Send `system` + `turns` (a [{role,content}] list) to `provider`.

    `images` is an optional list of {"data": base64, "mime": "image/..."} parts
    (use `encode_image`) attached to the final user turn. Returns the model's raw
    text reply. Raises MissingKeyError / OfflineError / ProviderError, all with a
    plain-language message safe to show the user.
    """
    pid = normalize_provider(provider)
    if not api_key:
        raise MissingKeyError("No API key is set for " + PROVIDERS[pid]["label"] + ".")
    if images and not PROVIDERS[pid]["vision"]:
        raise ProviderError(PROVIDERS[pid]["label"] + " does not accept images. "
                            "Choose a different model for picture-based requests.")
    model = model or PROVIDERS[pid]["model"]
    family = PROVIDERS[pid]["family"]
    if family == "anthropic":
        return _anthropic(model, api_key, system, turns, temperature, images)
    if family == "gemini":
        return _gemini(model, api_key, system, turns, temperature, images)
    return _openai_family(pid, model, api_key, system, turns, temperature, images)


# ===== SNAPSMACK EOF =====
