"""SNAP SLAPPER generative-edit records and embedded XMP export packets."""

import base64
import copy
import hashlib
import html
import json
import os
import re
import uuid
from datetime import datetime, timezone

from PIL import Image


SCHEMA_VERSION = "1.0"
AI_CLASSES = {"B", "C", "D"}
SNAP_NAMESPACE = "https://snapsmack.ca/ns/snap-slapper-provenance/1.0/"
_PRIVATE_PATH = re.compile(
    r"(?i)(?:[a-z]:\\(?:[^\s<>'\"]+\\)*[^\s<>'\"]+|/(?:users|home)/[^\s<>'\"]+)"
)
_SECRET = re.compile(
    r"(?i)\b(?:api[_ -]?key|token|password|secret)\s*[:=]\s*[^\s,;]+"
)
_CREATIVE_HEAL = re.compile(
    r"(?i)\b(?:add|create|invent|insert|replace|swap|change)\b.*"
    r"\b(?:person|people|subject|object|sky|background|scene|building|animal|face)\b"
)


def utc_now():
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def image_digest(image):
    value = image.convert("RGBA")
    digest = hashlib.sha256()
    digest.update(f"{value.width}x{value.height}:RGBA\n".encode("ascii"))
    digest.update(value.tobytes())
    return digest.hexdigest()


def mask_facts(mask):
    """Describe the binary selection actually sent, before local feathering."""
    sent = mask.convert("L").point(lambda value: 255 if value >= 128 else 0)
    histogram = sent.histogram()
    count = int(histogram[255])
    bounds = sent.getbbox()
    return count, (list(bounds) if bounds else None), list(sent.size)


def classify_heal_instruction(instruction):
    """Conservatively promote semantic invention in AI Heal to Class C."""
    return "C" if _CREATIVE_HEAL.search(str(instruction or "")) else "B"


def new_ai_operation(*, operation_class, tool_name, purpose, provider, model,
                     instruction, sent_mask, input_image, output_image,
                     app_version, canvas_extension=False,
                     subject_replacement=False, scene_invention=False,
                     whole_image=False, parent_operation_id=None):
    if operation_class not in AI_CLASSES:
        raise ValueError("Generative operations must use provenance class B, C, or D")
    count, bounds, sent_size = mask_facts(sent_mask)
    return {
        "schema_version": SCHEMA_VERSION,
        "operation_id": str(uuid.uuid4()),
        "operation_class": operation_class,
        "tool_name": str(tool_name),
        "purpose": str(purpose),
        "utc_timestamp": utc_now(),
        "snap_slapper_version": str(app_version),
        "ai_provider": str(provider),
        "ai_model": str(model),
        "ai_model_version": "",
        "instruction_verbatim": str(instruction),
        "instruction_summary": (
            f"{tool_name}: {purpose}; provider {provider}; model {model}."),
        "sent_mask_bounds": bounds,
        "sent_mask_size": sent_size,
        "sent_mask_pixel_count": count,
        "whole_image_processing": bool(whole_image),
        "canvas_extension": bool(canvas_extension),
        "subject_replacement": bool(subject_replacement),
        "scene_invention": bool(scene_invention),
        "digital_source_type": (
            "compositeWithTrainedAlgorithmicMedia" if canvas_extension else
            "trainedAlgorithmicMedia" if operation_class in ("C", "D") else
            "compositeWithTrainedAlgorithmicMedia"),
        "input_render_sha256": image_digest(input_image),
        "output_result_sha256": image_digest(output_image),
        "parent_operation_id": parent_operation_id,
    }


def _safe_text(value):
    value = _PRIVATE_PATH.sub("[local path withheld]", str(value))
    return _SECRET.sub("[credential withheld]", value)


def export_operations(layers, canvas_size):
    """Return only generative operations represented in the current pixels."""
    canvas_pixels = max(1, int(canvas_size[0]) * int(canvas_size[1]))
    records = []
    for layer in layers:
        record = layer.get("provenance") if isinstance(layer, dict) else None
        if not isinstance(record, dict) or record.get("operation_class") not in AI_CLASSES:
            continue
        if not layer.get("visible", True) or float(layer.get("opacity", 1.0)) <= 0:
            continue
        # Explicit allowlist: the verbatim instruction and local project data
        # cannot accidentally begin travelling when the project schema grows.
        clean = {key: copy.deepcopy(record.get(key)) for key in (
            "schema_version", "operation_id", "operation_class", "tool_name",
            "purpose", "utc_timestamp", "snap_slapper_version", "ai_provider",
            "ai_model", "ai_model_version", "instruction_summary",
            "sent_mask_bounds", "sent_mask_size", "sent_mask_pixel_count",
            "whole_image_processing", "canvas_extension", "subject_replacement",
            "scene_invention", "digital_source_type", "input_render_sha256", "output_result_sha256",
            "parent_operation_id", "kind") if key in record}
        clean["instruction_summary"] = _safe_text(
            clean.get("instruction_summary") or
            f"{clean.get('tool_name', 'Generative edit')}: "
            f"{clean.get('purpose', 'image edit')}; provider "
            f"{clean.get('ai_provider', 'not recorded')}.")
        count = int(clean.get("sent_mask_pixel_count", 0))
        clean["affected_percentage_of_exported_canvas"] = round(
            min(100.0, count * 100.0 / canvas_pixels), 8)
        # The export record never carries implementation paths or arbitrary layer data.
        records.append(clean)
    return records


def human_summary(records):
    if not records:
        return ""
    labels = {"B": "AI-assisted localized restoration",
              "C": "generative alteration", "D": "whole-image AI restoration"}
    classes = []
    for record in records:
        label = labels[record["operation_class"]]
        if label not in classes:
            classes.append(label)
    affected = sum(float(item.get("affected_percentage_of_exported_canvas", 0))
                   for item in records)
    extension = any(item.get("canvas_extension") for item in records)
    replacement = any(item.get("subject_replacement") for item in records)
    return (f"SNAP SLAPPER derivative with {', '.join(classes)}. "
            f"{len(records)} operation(s); sent masks total {min(100.0, affected):.4g}% "
            f"of the exported canvas. Canvas extension: {str(extension).lower()}; "
            f"subject replacement: {str(replacement).lower()}.")


def embed_xmp(existing_xmp, records):
    """Add the versioned SNAP detail structure without inventing IPTC URIs."""
    if not records:
        return existing_xmp
    if isinstance(existing_xmp, bytes):
        existing = existing_xmp.decode("utf-8", errors="replace")
    else:
        existing = str(existing_xmp or "")
    payload = base64.b64encode(json.dumps(
        records, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")).decode("ascii")
    description = (
        f'<rdf:Description xmlns:snap="{SNAP_NAMESPACE}" '
        f'snap:schemaVersion="{SCHEMA_VERSION}">'
        f'<snap:summary>{html.escape(human_summary(records))}</snap:summary>'
        f'<snap:operations encoding="base64-json">{payload}</snap:operations>'
        f'</rdf:Description>')
    if "</rdf:RDF>" in existing:
        return existing.replace("</rdf:RDF>", description + "</rdf:RDF>", 1).encode("utf-8")
    return (f'<?xpacket begin="\ufeff"?>'
            f'<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            f'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            f'{description}</rdf:RDF></x:xmpmeta><?xpacket end="w"?>').encode("utf-8")


def read_operations(xmp):
    """Small independent-reader surface used by tests and future import tools."""
    text = xmp.decode("utf-8", errors="replace") if isinstance(xmp, bytes) else str(xmp)
    match = re.search(r'<snap:operations[^>]*>([^<]+)</snap:operations>', text)
    if not match:
        return []
    return json.loads(base64.b64decode(match.group(1)).decode("utf-8"))

# ===== SNAPSMACK EOF =====
