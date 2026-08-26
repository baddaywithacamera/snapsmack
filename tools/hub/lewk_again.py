"""LEWK AGAIN — the AI-assisted LEWK builder for SNAP SLAPPER.

Describe a look in plain language ("muted winter documentary colour, protect
skin tones, lift deep shadows, restrained grain") and LEWK AGAIN asks a language
model for a matching LEWK, then hands back an editable, non-destructive LEWK in
SNAP SLAPPER's own recipe format.

Safety is the whole point (SNAP-SLAPPER-SPEC section 9): the model's answer is
NEVER trusted. Every value is forced through `sanitize_adjustments`, which keeps
only the operations the local renderer understands and clamps each one into a
safe range. A generated LEWK can therefore contain nothing but bounded numbers —
no code, no network fetches, no unknown operations. Photographs are never
uploaded: LEWK AGAIN sends only the text description.

This module has no Tk or UI code and makes no network call on import, so it can
be unit-tested headless. The provider HTTP calls live in `providers.py`.

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import base64
import copy
import json
import os
import re
import time

import editor_engine
import lewk_providers
from lewk_providers import MissingKeyError, OfflineError, ProviderError


# --- The allowlist ---------------------------------------------------------
# Every key the renderer (editor_engine.apply_adjustments) actually reads, with
# the inclusive numeric range LEWK AGAIN will clamp a model's answer into. These
# ranges match how the built-in LEWKS use each control; anything outside is
# pulled back to the edge rather than rejected, so a wild answer degrades to a
# strong-but-safe look instead of failing.
_RANGES = {
    "exposure": (-2.0, 2.0),
    "brightness": (-100.0, 100.0),
    "contrast": (-100.0, 100.0),
    "highlights": (-100.0, 100.0),
    "shadows": (-100.0, 100.0),
    "whites": (-100.0, 100.0),
    "blacks": (-100.0, 100.0),
    "temperature": (-100.0, 100.0),
    "tint": (-100.0, 100.0),
    "saturation": (-100.0, 100.0),
    "vibrance": (-100.0, 100.0),
    "clarity": (-100.0, 100.0),
    "texture": (-100.0, 100.0),
    "dehaze": (-100.0, 100.0),
    "sharpen": (0.0, 100.0),
    "level_black": (0.0, 254.0),
    "level_gamma": (0.1, 3.0),
    "level_white": (1.0, 255.0),
    "vignette": (-100.0, 100.0),
    "grain": (0.0, 100.0),
}

# The one boolean control.
_FLAGS = ("black_white",)

# Blend modes editor_engine.blend_images understands, for the LEWK's layer.
BLEND_MODES = (
    "normal", "multiply", "screen", "overlay", "soft_light", "hard_light",
    "darken", "lighten", "difference", "color", "luminosity",
)

# Categories the built-in library uses; a generated LEWK is tagged with the
# closest one so it files alongside the stock packs.
CATEGORIES = (
    "Clean + Corrective", "Film + Print", "Black + White", "Portrait",
    "Landscape + Weather", "Night + Neon", "Experimental",
)

PROVENANCE = "lewk-again"
MAX_CURVE_POINTS = 16
_NAME_MAX = 48
_DESC_MAX = 240


class LewkAgainError(Exception):
    """Base class for every LEWK AGAIN failure surfaced to the UI."""


class ModelResponseError(LewkAgainError):
    """The model returned something that is not a usable LEWK."""


def _clamp(value, low, high):
    return max(low, min(high, value))


def _coerce_number(value):
    """Best-effort number from a model answer; None if it is not numeric."""
    if isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        return float(value)
    if isinstance(value, str):
        match = re.search(r"-?\d+(\.\d+)?", value.replace(",", ""))
        if match:
            try:
                return float(match.group(0))
            except ValueError:
                return None
    return None


def _sanitize_curve(raw):
    """Return a clean list of [x, y] points in 0..255, or None to drop it."""
    if not isinstance(raw, list) or len(raw) < 2:
        return None
    points = []
    for item in raw[:MAX_CURVE_POINTS]:
        if not isinstance(item, (list, tuple)) or len(item) != 2:
            continue
        x = _coerce_number(item[0])
        y = _coerce_number(item[1])
        if x is None or y is None:
            continue
        points.append([int(_clamp(x, 0, 255)), int(_clamp(y, 0, 255))])
    if len(points) < 2:
        return None
    points.sort(key=lambda point: point[0])
    if points[0][0] != 0:
        points.insert(0, [0, 0])
    if points[-1][0] != 255:
        points.append([255, 255])
    return points


def sanitize_adjustments(raw):
    """Reduce an untrusted mapping to a safe, renderer-ready adjustment dict.

    Unknown keys are dropped, numbers are clamped into range, the tone curve is
    validated point by point, and `black_white` is coerced to a real bool. The
    result contains only keys the renderer reads and only values it can apply,
    so it can never carry code or an unsupported operation. Keys left at their
    neutral default are omitted to keep the LEWK readable.
    """
    if not isinstance(raw, dict):
        raise ModelResponseError("the model did not return an adjustments object")
    clean = {}
    for key, (low, high) in _RANGES.items():
        if key not in raw:
            continue
        number = _coerce_number(raw[key])
        if number is None:
            continue
        value = _clamp(number, low, high)
        default = editor_engine.DEFAULT_ADJUSTMENTS.get(key, 0.0)
        if value != default:
            clean[key] = value
    for flag in _FLAGS:
        if flag in raw and bool(raw[flag]):
            clean[flag] = True
    if "curve" in raw:
        curve = _sanitize_curve(raw["curve"])
        if curve and curve != editor_engine.DEFAULT_ADJUSTMENTS["curve"]:
            clean["curve"] = curve
    return clean


def _clean_text(value, limit, fallback):
    if not isinstance(value, str):
        return fallback
    text = " ".join(value.split()).strip()
    return text[:limit] if text else fallback


def _pick_category(value):
    if isinstance(value, str):
        wanted = value.strip().lower()
        for category in CATEGORIES:
            if category.lower() == wanted:
                return category
        for category in CATEGORIES:
            if wanted and wanted in category.lower():
                return category
    return "Experimental"


def build_lewk(proposal, strength=100, now_ns=None):
    """Turn a validated model proposal into an editable LEWK recipe.

    `proposal` is the parsed model answer: {name, category, description,
    adjustments, blend?}. The output matches built_in_lewks.recipe exactly — a
    version-1 recipe with a neutral base and one non-destructive adjustment
    layer — so the editor applies, stacks, inspects, saves, and exports a
    LEWK AGAIN result the same way as any built-in or handmade LEWK.
    """
    if not isinstance(proposal, dict):
        raise ModelResponseError("the model did not return a LEWK object")
    adjustments = sanitize_adjustments(proposal.get("adjustments"))
    if not adjustments:
        raise ModelResponseError(
            "the model proposed no usable adjustments for this description")
    name = _clean_text(proposal.get("name"), _NAME_MAX, "LEWK AGAIN")
    description = _clean_text(proposal.get("description"), _DESC_MAX,
                             "Generated by LEWK AGAIN.")
    category = _pick_category(proposal.get("category"))
    blend = proposal.get("blend")
    blend = blend if blend in BLEND_MODES else "normal"

    amount = _clamp(float(strength) / 100.0, 0.0, 1.0)
    values = copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS)
    values.update(adjustments)
    # Deterministic id when the caller supplies a timestamp (keeps tests stable);
    # otherwise a real monotonic-ish id from the wall clock.
    stamp = now_ns if now_ns is not None else time.time_ns()
    provenance = {"id": PROVENANCE + "-" + str(stamp), "name": name,
                  "category": category, "description": description,
                  "strength": int(amount * 100), "provenance": PROVENANCE}
    layer = {"id": str(stamp), "name": "LEWK · " + name, "type": "adjustment",
             "visible": True, "opacity": amount, "blend": blend,
             "adjustments": values, "mask": "", "styles": {},
             "lewk": copy.deepcopy(provenance)}
    return {"version": editor_engine.PROJECT_VERSION,
            "adjustments": copy.deepcopy(editor_engine.DEFAULT_ADJUSTMENTS),
            "layers": [layer], "lewk": provenance}


# --- Prompt ----------------------------------------------------------------
# The model is told exactly which operations exist and what their numbers mean,
# and is required to answer with one strict JSON object. Anything it invents
# beyond this is discarded by sanitize_adjustments, but a precise prompt means
# the first answer is usually already valid.
_CONTROL_HELP = (
    "exposure (-2..2 stops), brightness, contrast, highlights, shadows, whites, "
    "blacks, temperature (+warm/-cool), tint (+magenta/-green), saturation, "
    "vibrance, clarity, texture (+crisp/-soft), dehaze, sharpen (0..100), "
    "vignette (negative darkens the corners), grain (0..100) — all -100..100 "
    "unless noted; black_white (true/false); level_black (0..254), "
    "level_gamma (0.1..3, 1 is neutral), level_white (1..255); and an optional "
    "curve as up to 16 [input,output] points in 0..255."
)

SYSTEM_PROMPT = (
    "You are LEWK AGAIN, a colour-grading assistant inside the SNAP SLAPPER "
    "photo editor. Convert the photographer's plain-language request into ONE "
    "reusable look ('LEWK'). You may ONLY use these controls, and only these: "
    + _CONTROL_HELP +
    " Never invent controls, scripts, filenames, or code. Keep values tasteful "
    "and restrained — most sliders belong under 40, exposure under 0.4 — so the "
    "look reads at full strength but does not destroy the photograph. "
    "Respond with a single JSON object and nothing else, shaped exactly like: "
    '{"name": "SHORT PUNCHY NAME", "category": "one of '
    + " | ".join(CATEGORIES) +
    '", "description": "one plain sentence describing the look", '
    '"adjustments": {"<control>": <number>, ...}}. Include only the controls '
    "that matter for this look; omit the rest."
)


def build_messages(description, refinement=None, previous=None):
    """Build the chat turns: the request, plus any refine-this history.

    `previous` is the JSON of the last proposal and `refinement` the user's
    follow-up ("warmer, less grain"), so the conversation can iterate on a LEWK
    without starting over.
    """
    request = _clean_text(description, 2000, "")
    if not request:
        raise LewkAgainError("Describe the look you want before generating.")
    turns = [{"role": "user", "content":
              "Create a LEWK for this request:\n\n" + request}]
    if previous is not None and refinement:
        turns.append({"role": "assistant",
                      "content": json.dumps(previous, ensure_ascii=False)})
        turns.append({"role": "user", "content":
                      "Revise that LEWK: " + _clean_text(refinement, 2000, "")})
    return turns


def parse_model_json(text):
    """Extract the first JSON object from a model's reply.

    Models sometimes wrap JSON in prose or a ```json fence; pull out the object
    rather than demanding a pristine body.
    """
    if not isinstance(text, str) or not text.strip():
        raise ModelResponseError("the model returned an empty reply")
    fenced = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.DOTALL)
    candidate = fenced.group(1) if fenced else None
    if candidate is None:
        start = text.find("{")
        end = text.rfind("}")
        if start == -1 or end <= start:
            raise ModelResponseError("the model reply contained no JSON object")
        candidate = text[start:end + 1]
    try:
        parsed = json.loads(candidate)
    except (ValueError, TypeError) as exc:
        raise ModelResponseError("the model reply was not valid JSON") from exc
    if not isinstance(parsed, dict):
        raise ModelResponseError("the model reply was not a JSON object")
    return parsed


# --- API keys and local config --------------------------------------------
# Keys are resolved in three steps, most explicit first, so LEWK AGAIN works
# both inside the Hub (shared vault) and as a fenced standalone editor (env or
# its own small config), without ever forcing the excluded credential vault
# into the editor build — the vault is only read if it happens to be present.
_ENV_KEYS = {
    "claude":   ("SNAP_ANTHROPIC_API_KEY", "ANTHROPIC_API_KEY", "CLAUDE_API_KEY"),
    "openai":   ("SNAP_OPENAI_API_KEY", "OPENAI_API_KEY"),
    "gemini":   ("SNAP_GEMINI_API_KEY", "GEMINI_API_KEY", "GOOGLE_API_KEY"),
    "kimi":     ("SNAP_KIMI_API_KEY", "MOONSHOT_API_KEY", "KIMI_API_KEY"),
    "deepseek": ("SNAP_DEEPSEEK_API_KEY", "DEEPSEEK_API_KEY"),
}
_VAULT_KEYS = {
    "claude": "anthropic_api_key", "openai": "openai_api_key",
    "gemini": "gemini_api_key", "kimi": "kimi_api_key", "deepseek": "deepseek_api_key",
}
_CONFIG_NAME = "lewk_again.json"


def config_path():
    """Where LEWK AGAIN's own settings live. Uses the shared home config dir
    when snap_home is available, else a file beside this module."""
    try:
        import snap_home
        for getter in ("config_dir", "settings_dir", "home_dir", "base_dir"):
            func = getattr(snap_home, getter, None)
            if callable(func):
                return os.path.join(func(), _CONFIG_NAME)
    except Exception:
        pass
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), _CONFIG_NAME)


def load_config():
    """Read LEWK AGAIN's local settings, or a safe empty default."""
    try:
        with open(config_path(), "r", encoding="utf-8") as handle:
            data = json.load(handle)
        if isinstance(data, dict):
            data.setdefault("provider", "claude")
            data.setdefault("keys", {})
            data.setdefault("models", {})
            return data
    except (OSError, ValueError):
        pass
    return {"provider": "claude", "keys": {}, "models": {}}


def _decode_local_key(value):
    if not isinstance(value, str) or not value:
        return ""
    if value.startswith("b64:"):
        try:
            return base64.b64decode(value[4:]).decode("utf-8")
        except Exception:
            return ""
    return value


def _encode_local_key(value):
    return "b64:" + base64.b64encode((value or "").encode("utf-8")).decode("ascii")


def save_config(config):
    """Persist LEWK AGAIN settings, obfuscating locally stored keys (base64 —
    not encryption; prefer env vars or the shared vault for real secrecy)."""
    payload = {"provider": config.get("provider", "claude"),
               "models": config.get("models", {}) or {},
               "keys": {}}
    for pid, raw in (config.get("keys", {}) or {}).items():
        if not raw:
            continue
        payload["keys"][pid] = raw if raw.startswith("b64:") else _encode_local_key(raw)
    path = config_path()
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
    except OSError:
        pass
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)
    return path


def resolve_key(provider, config=None):
    """Return the API key for `provider`: env var, then shared vault, then the
    local config. Empty string when nothing is set."""
    pid = lewk_providers.normalize_provider(provider)
    for var in _ENV_KEYS.get(pid, ()):
        value = os.environ.get(var)
        if value and value.strip():
            return value.strip()
    try:  # shared vault — only if it survived the build (Hub / dev)
        import snap_creds
        if hasattr(snap_creds, "init"):
            try:
                snap_creds.init()
            except Exception:
                pass
        value = snap_creds.get(_VAULT_KEYS[pid], "")
        if value and value.strip():
            return value.strip()
    except Exception:
        pass
    cfg = config if config is not None else load_config()
    return _decode_local_key((cfg.get("keys", {}) or {}).get(pid, ""))


def key_available(provider, config=None):
    return bool(resolve_key(provider, config))


# --- Entitlement gate (seam) -----------------------------------------------
def entitlement_state():
    """SNAP SLAPPER's entitlement state, which LEWK AGAIN follows (it never asks
    for its own key). The 90-day CMS-key system in desktop-tool-entitlements.md
    is not built yet; in the closed beta this reports 'beta' (unlocked). When a
    real entitlement module ships it is read here, so the gate is already wired
    and no call site changes."""
    try:
        import snap_entitlement  # future module; absent in the closed beta
        return snap_entitlement.state()
    except Exception:
        return {"status": "beta", "unlocked": True,
                "message": "Closed beta — LEWK AGAIN is unlocked."}


def require_unlocked():
    """Raise LewkAgainError if LEWK AGAIN is not currently permitted to run."""
    state = entitlement_state()
    if not state.get("unlocked", False):
        raise LewkAgainError(state.get("message")
                             or "LEWK AGAIN is locked. Authenticate SNAP SLAPPER first.")
    return state


# --- The one call the UI makes ---------------------------------------------
def generate(description, provider, refinement=None, previous=None, strength=100,
             api_key=None, model="", config=None):
    """Build a LEWK from a plain-language description.

    Returns (recipe, proposal): `recipe` is ready for document.stack_layers(
    recipe["layers"]) and preview; `proposal` is the validated model answer to
    show the photographer and to feed back in as `previous` when refining.

    Raises MissingKeyError, OfflineError, ProviderError, or ModelResponseError —
    all carry a plain-language message safe to show directly.
    """
    require_unlocked()
    pid = lewk_providers.normalize_provider(provider)
    cfg = config if config is not None else load_config()
    key = api_key or resolve_key(pid, cfg)
    if not key:
        raise MissingKeyError(
            "No API key is set for " + lewk_providers.PROVIDERS[pid]["label"]
            + ". Add one in LEWK AGAIN settings, or set an environment variable.")
    chosen_model = model or (cfg.get("models", {}) or {}).get(pid) \
        or lewk_providers.default_model(pid)
    turns = build_messages(description, refinement, previous)
    text = lewk_providers.call(pid, key, SYSTEM_PROMPT, turns, model=chosen_model)
    proposal = parse_model_json(text)
    recipe = build_lewk(proposal, strength=strength)
    return recipe, proposal


# ===== SNAPSMACK EOF =====
