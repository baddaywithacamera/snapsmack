"""Provider-neutral, text-only LEWK generation with a strict safe schema."""

import copy
import json
import re
import urllib.error
import urllib.parse
import urllib.request

import editor_engine
import slapper_filters


def library_dir():
    import os
    import snap_home
    path = os.path.join(snap_home.shared_library(), "lewks")
    os.makedirs(path, exist_ok=True)
    return path


def saved_lewks():
    """Load only recipes that still pass today's strict schema."""
    import os
    result = []
    try:
        names = sorted(os.listdir(library_dir()))
    except OSError:
        return result
    for filename in names:
        if os.path.splitext(filename)[1].lower() not in {".lewk", ".json"}:
            continue
        try:
            with open(os.path.join(library_dir(), filename), encoding="utf-8") as handle:
                saved = json.load(handle)
            safe = validate_response(json.dumps({
                "name": saved.get("name"), "description": saved.get("description"),
                "explanation": saved.get("explanation", []),
                "adjustments": next((layer.get("adjustments", {}) for layer in saved.get("layers", [])
                                     if layer.get("type") == "adjustment"), {}),
                "filters": [{"type": layer.get("filter_type"), "name": layer.get("name"),
                             "settings": layer.get("settings", {})}
                            for layer in saved.get("layers", []) if layer.get("type") == "filter"],
            }), saved.get("provider", ""), saved.get("model", ""), saved.get("prompt", ""))
            safe.update({"id": "custom:" + filename, "category": "MY LEWKS",
                         "custom_recipe": True})
            safe["adjustments"] = safe["layers"][0]["adjustments"]
            result.append(safe)
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            continue
    return result


PROVIDERS = {
    "GEMINI": ("gemini_api_key", "gemini-2.5-flash"),
    "KIMI": ("kimi_api_key", "moonshot-v1-8k"),
    "DEEPSEEK": ("deepseek_api_key", "deepseek-chat"),
    "CLAUDE": ("claude_api_key", "claude-sonnet-4-20250514"),
    "OPENAI": ("openai_api_key", "gpt-5-mini"),
    "LOCAL": ("", ""),
}

_ENDPOINTS = {
    "KIMI": "https://api.moonshot.ai/v1/chat/completions",
    "DEEPSEEK": "https://api.deepseek.com/chat/completions",
    "OPENAI": "https://api.openai.com/v1/chat/completions",
}

_SYSTEM = """You design editable photographic looks for SNAP SLAPPER.
Return JSON only. Never return markdown, prose outside JSON, paths, URLs, scripts,
or executable content. Use this exact shape:
{"name":"SHORT NAME","description":"one sentence","adjustments":{},
 "filters":[{"type":"orton|film_grain|light_leak|pastel|gaussian_blur|motion_blur|radial_blur","name":"label","settings":{}}],
 "explanation":["plain-language change and why"]}
Only include adjustments that differ from neutral. Be restrained and photographic.
Allowed adjustment names are: %s
""" % ", ".join(editor_engine.DEFAULT_ADJUSTMENTS)


def _json_bytes(value):
    return json.dumps(value, ensure_ascii=False).encode("utf-8")


def _post(url, headers, payload, timeout=60):
    request = urllib.request.Request(url, data=_json_bytes(payload), headers=headers,
                                     method="POST")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            data = response.read(1024 * 1024 + 1)
    except urllib.error.HTTPError as error:
        detail = error.read(4096).decode("utf-8", "replace")
        raise RuntimeError(f"Provider rejected the request (HTTP {error.code}): {detail}") from error
    if len(data) > 1024 * 1024:
        raise ValueError("Provider response was unexpectedly large")
    return json.loads(data.decode("utf-8"))


def request_lewk(provider, api_key, model, prompt, previous=None, endpoint=""):
    provider = provider.upper()
    if provider not in PROVIDERS:
        raise ValueError("Unknown AI provider")
    if provider != "LOCAL" and not api_key:
        raise ValueError(f"Add a {provider} API key in THE HUB first")
    if not model.strip():
        raise ValueError("Choose a model")
    user = "Create a LEWK for this request:\n" + prompt.strip()
    if previous:
        user += "\n\nRefine this existing safe recipe:\n" + json.dumps(previous)
    messages = [{"role": "system", "content": _SYSTEM},
                {"role": "user", "content": user}]

    if provider == "GEMINI":
        url = ("https://generativelanguage.googleapis.com/v1beta/models/" +
               urllib.parse.quote(model.strip(), safe="") + ":generateContent")
        data = _post(url, {"Content-Type": "application/json", "x-goog-api-key": api_key}, {
            "system_instruction": {"parts": [{"text": _SYSTEM}]},
            "contents": [{"role": "user", "parts": [{"text": user}]}],
            "generationConfig": {"responseMimeType": "application/json"},
        })
        text = data["candidates"][0]["content"]["parts"][0]["text"]
    elif provider == "CLAUDE":
        data = _post("https://api.anthropic.com/v1/messages", {
            "Content-Type": "application/json", "x-api-key": api_key,
            "anthropic-version": "2023-06-01",
        }, {"model": model.strip(), "max_tokens": 3000, "system": _SYSTEM,
            "messages": [{"role": "user", "content": user}]})
        text = "".join(item.get("text", "") for item in data.get("content", [])
                       if item.get("type") == "text")
    else:
        url = endpoint.strip() if provider == "LOCAL" else _ENDPOINTS[provider]
        if not url:
            raise ValueError("Enter the local OpenAI-compatible endpoint")
        headers = {"Content-Type": "application/json"}
        if api_key:
            headers["Authorization"] = "Bearer " + api_key
        data = _post(url, headers, {"model": model.strip(), "messages": messages,
                                    "temperature": 0.35,
                                    "response_format": {"type": "json_object"}})
        text = data["choices"][0]["message"]["content"]
    return validate_response(text, provider, model.strip(), prompt)


def _bounded_number(value, default, low=-100.0, high=100.0):
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ValueError("Adjustment values must be numbers")
    return max(low, min(high, float(value)))


def _safe_adjustments(values):
    if not isinstance(values, dict):
        raise ValueError("adjustments must be an object")
    safe = copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS)
    for key, value in values.items():
        if key not in safe:
            raise ValueError(f"Unsupported adjustment: {key}")
        default = safe[key]
        if isinstance(default, bool):
            if not isinstance(value, bool):
                raise ValueError(f"{key} must be true or false")
            safe[key] = value
        elif isinstance(default, (int, float)):
            low, high = (-3.0, 3.0) if key == "exposure" else (-100.0, 100.0)
            if key in {"level_black", "level_white"}: low, high = 0.0, 255.0
            if key == "level_gamma": low, high = 0.1, 3.0
            if key in {"vignette_feather", "glow_x", "glow_y", "glow_size"}: low, high = 0.0, 100.0
            safe[key] = _bounded_number(value, default, low, high)
        elif isinstance(default, list):
            if not isinstance(value, list) or len(value) > 32:
                raise ValueError(f"{key} must be a short list")
            if key.startswith("curve"):
                if (len(value) < 2 or any(not isinstance(point, list) or len(point) != 2 or
                                           any(isinstance(v, bool) or not isinstance(v, (int, float))
                                               for v in point) for point in value)):
                    raise ValueError(f"{key} must contain numeric x/y points")
                safe[key] = [[max(0, min(255, float(v))) for v in point]
                             for point in value]
            else:
                if len(value) not in {3, 4} or any(isinstance(v, bool) or
                    not isinstance(v, (int, float)) for v in value):
                    raise ValueError(f"{key} must be an RGB or RGBA colour")
                safe[key] = [max(0, min(255, float(v))) for v in value]
        elif isinstance(default, str):
            if not isinstance(value, str) or len(value) > 40:
                raise ValueError(f"{key} must be short text")
            safe[key] = value
    return safe


def validate_response(text, provider="", model="", prompt=""):
    if not isinstance(text, str) or len(text) > 1024 * 1024:
        raise ValueError("AI response is not usable")
    cleaned = text.strip()
    fenced = re.fullmatch(r"```(?:json)?\s*(.*?)\s*```", cleaned, re.S | re.I)
    if fenced:
        cleaned = fenced.group(1)
    value = json.loads(cleaned)
    if not isinstance(value, dict):
        raise ValueError("AI response must be one JSON object")
    name = str(value.get("name", "LEWK AGAIN")).strip()[:80] or "LEWK AGAIN"
    description = str(value.get("description", "")).strip()[:500]
    explanation = value.get("explanation", [])
    if not isinstance(explanation, list):
        explanation = []
    explanation = [str(item)[:500] for item in explanation[:20]]
    layers = [{"name": name, "type": "adjustment", "visible": True,
               "opacity": 1.0, "blend": "normal", "mask": "",
               "adjustments": _safe_adjustments(value.get("adjustments", {})),
               "styles": {}, "lewk_again": {"provider": provider, "model": model}}]
    filters = value.get("filters", [])
    if not isinstance(filters, list) or len(filters) > 8:
        raise ValueError("filters must be a list of no more than eight items")
    for item in filters:
        if not isinstance(item, dict) or item.get("type") not in slapper_filters.FILTER_DEFAULTS:
            raise ValueError("AI requested an unsupported filter")
        kind = item["type"]
        defaults = slapper_filters.defaults(kind)
        settings = item.get("settings", {})
        if not isinstance(settings, dict) or any(key not in defaults for key in settings):
            raise ValueError(f"Unsupported {kind} setting")
        for key, value in settings.items():
            default = defaults[key]
            if isinstance(default, bool):
                if not isinstance(value, bool):
                    raise ValueError(f"{kind}.{key} must be true or false")
            elif isinstance(default, (int, float)):
                if isinstance(value, bool) or not isinstance(value, (int, float)):
                    raise ValueError(f"{kind}.{key} must be numeric")
                value = max(-10000, min(10000, float(value)))
            elif isinstance(default, str):
                if not isinstance(value, str) or len(value) > 40:
                    raise ValueError(f"{kind}.{key} must be short text")
            elif isinstance(default, list):
                if (not isinstance(value, list) or len(value) != len(default) or
                        any(isinstance(v, bool) or not isinstance(v, (int, float)) for v in value)):
                    raise ValueError(f"{kind}.{key} must be a colour")
                value = [max(0, min(255, float(v))) for v in value]
            defaults[key] = value
        layers.append({"name": str(item.get("name") or slapper_filters.FILTER_NAMES[kind])[:80],
                       "type": "filter", "filter_type": kind, "filter_version": 1,
                       "settings": defaults, "visible": True, "opacity": 1.0,
                       "blend": "normal", "mask": "", "mask_enabled": True,
                       "styles": {}})
    return {"version": 1, "name": name, "description": description,
            "explanation": explanation, "prompt": str(prompt)[:4000],
            "provider": provider, "model": model, "layers": layers}

# ===== SNAPSMACK EOF =====
