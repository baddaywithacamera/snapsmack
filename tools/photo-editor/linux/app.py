"""PIXEL TICKLER Linux/Blink host. Original SnapSmack code, SPL 2.1."""

import json
import base64
import os
import subprocess
import sys

HERE = os.path.abspath(os.path.dirname(__file__))
TOOLS = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, os.path.join(TOOLS, "_shared"))
import snap_blink

WEB = os.path.abspath(os.path.join(HERE, "..", "web"))
CORE = os.environ.get("SNAPSMACK_PHOTO_CORE") or os.path.abspath(
    os.path.join(HERE, "..", "core", "target", "release", "snapsmack-photo-core"))
app = snap_blink.App(tool="pixel-tickler", title="PIXEL TICKLER", web_dir=WEB)
_input_path = None
_render_generation = 0
_project = None


def _fingerprint(path):
    canonical = os.path.realpath(path)
    stat = os.stat(canonical)
    value = 0xcbf29ce484222325
    with open(canonical, "rb") as handle:
        while True:
            chunk = handle.read(64 * 1024)
            if not chunk:
                break
            for byte in chunk:
                value ^= byte
                value = (value * 0x100000001b3) & 0xffffffffffffffff
    return {"path": canonical, "bytes": stat.st_size,
            "modified_unix_ms": int(stat.st_mtime_ns / 1_000_000),
            "content_fnv1a64": format(value, "016x")}


def _source_matches(document, path):
    actual, expected = _fingerprint(path), document["source"]
    return actual["bytes"] == expected["bytes"] and actual["content_fnv1a64"] == expected["content_fnv1a64"]


def _write_json_atomic(document, path):
    temporary, backup = path + ".tmp", path + ".bak"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump(document, handle, indent=2)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    if os.path.exists(path):
        os.replace(path, backup)
    os.replace(temporary, path)


def _autosave_path():
    root = os.environ.get("SNAPSMACK_HOME") or os.path.expanduser("~/snapsmack")
    return os.path.join(os.path.abspath(root), "config_files", "pixel-tickler", "autosave.json")


def _autosave(operations):
    path = _autosave_path()
    os.makedirs(os.path.dirname(path), exist_ok=True)
    temporary, backup = path + ".tmp", path + ".bak"
    with open(temporary, "w", encoding="utf-8") as handle:
        json.dump({"contract_version": 1, "input_path": _input_path, "operations": operations}, handle, indent=2)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    if os.path.exists(path):
        os.replace(path, backup)
    os.replace(temporary, path)


def _core(method, params, roots):
    command = [CORE]
    for root in sorted(set(os.path.realpath(item) for item in roots)):
        command.extend(["--allowed-root", root])
    requests = [
        {"id": 1, "method": "hello", "params": {"contract_versions": [1]}},
        {"id": 2, "method": method, "params": params},
        {"id": 3, "method": "shutdown", "params": {}},
    ]
    wire = "".join(json.dumps(item) + "\n" for item in requests)
    completed = subprocess.run(command, input=wire, text=True, capture_output=True, timeout=180)
    if completed.returncode:
        raise RuntimeError(completed.stderr.strip() or "PIXEL TICKLER engine failed")
    for line in completed.stdout.splitlines():
        message = json.loads(line)
        if message.get("id") == 2:
            if not message.get("ok"):
                error = message.get("error") or {}
                raise RuntimeError(error.get("message") or "PIXEL TICKLER refused the request")
            return message.get("result")
    raise RuntimeError("PIXEL TICKLER engine returned no result")


@app.api
def editor_call(method, params):
    global _input_path, _render_generation, _project
    params = dict(params or {})
    if method == "hello":
        return {"contract_version": 1, "host": "blink-linux"}
    if method == "choose_image":
        selected = snap_blink.pick_path("file", "Open JPEG, PNG, WebP, or TIFF")
        if not selected:
            return None
        _input_path = os.path.realpath(selected)
        _project = None
        params.update({"input_path": _input_path, "operations": []})
        result = _core("render_preview", params, [os.path.dirname(_input_path)])
        _autosave([])
        return result
    if method == "choose_overlay":
        selected = snap_blink.pick_path("file", "Open overlay image")
        if not selected:
            return None
        selected = os.path.realpath(selected)
        if os.path.getsize(selected) > 8 * 1024 * 1024:
            raise RuntimeError("Overlay files are limited to 8 MB so projects stay portable")
        extension = os.path.splitext(selected)[1].lower()
        mime = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
                ".webp": "image/webp", ".tif": "image/tiff", ".tiff": "image/tiff"}.get(extension)
        if not mime:
            raise RuntimeError("Choose a JPEG, PNG, WebP, or TIFF overlay")
        inspected = _core("inspect_image", {"input_path": selected}, [os.path.dirname(selected)])
        with open(selected, "rb") as handle:
            encoded = base64.b64encode(handle.read()).decode("ascii")
        return {"name": os.path.basename(selected), "width": inspected["image"]["width"],
                "height": inspected["image"]["height"], "data_uri": "data:" + mime + ";base64," + encoded}
    if method == "open_project":
        selected = snap_blink.pick_path("file", "Open PIXEL TICKLER project")
        if not selected:
            return None
        with open(selected, "r", encoding="utf-8") as handle:
            document = json.load(handle)
        if document.get("format") != "snapsmack-pixel-tickler" or document.get("version") != 1:
            raise RuntimeError("Unsupported PIXEL TICKLER project version")
        operations = document.get("operations") or []
        if len(operations) > 4096:
            raise RuntimeError("Project contains too many operations")
        source = document.get("source") or {}
        _input_path = source.get("path")
        if not _input_path or not os.path.isfile(_input_path):
            relocated = snap_blink.pick_path("file", "Locate the original image")
            if not relocated:
                _input_path = None
                return None
            if not _source_matches(document, relocated):
                _input_path = None
                raise RuntimeError("That file is not the original image used by this project")
            _input_path = os.path.realpath(relocated)
            document["source"]["path"] = _input_path
        elif not _source_matches(document, _input_path):
            _input_path = None
            raise RuntimeError("The original image has changed since this project was saved")
        params.update({"input_path": _input_path, "operations": operations})
        result = _core("render_preview", params, [os.path.dirname(_input_path)])
        result.update({"operations": operations, "project_name": os.path.basename(selected)})
        _project = document
        return result
    if method == "save_project":
        if not _input_path:
            raise RuntimeError("Open an image first")
        selected = snap_blink.pick_path("save", "Save PIXEL TICKLER project", os.path.dirname(_input_path))
        if not selected:
            return None
        if not selected.lower().endswith(".spt"):
            selected += ".spt"
        document = dict(_project or {})
        document.update({"format": "snapsmack-pixel-tickler", "version": 1,
                         "source": _fingerprint(_input_path),
                         "operations": params.get("operations") or []})
        _write_json_atomic(document, os.path.realpath(selected))
        _project = document
        return {"project_path": os.path.realpath(selected)}
    if method == "recover":
        path = _autosave_path()
        if not os.path.isfile(path):
            return None
        with open(path, "r", encoding="utf-8") as handle:
            saved = json.load(handle)
        _input_path = os.path.realpath(saved["input_path"])
        params.update({"input_path": _input_path, "operations": saved.get("operations") or []})
        result = _core("render_preview", params, [os.path.dirname(_input_path)])
        result["operations"] = params["operations"]
        return result
    if not _input_path:
        raise RuntimeError("Open an image first")
    params["input_path"] = _input_path
    if method == "render_preview":
        generation = int(params.get("generation") or 0)
        _render_generation = max(_render_generation, generation)
        result = _core(method, params, [os.path.dirname(_input_path)])
        if generation >= _render_generation:
            _autosave(params.get("operations") or [])
        return result
    if method == "export_copy":
        output = snap_blink.pick_path("save", "Save edited copy", os.path.dirname(_input_path))
        if not output:
            return None
        params["output_path"] = os.path.realpath(output)
        return _core("process_export", params, [os.path.dirname(_input_path), os.path.dirname(output)])
    raise RuntimeError("Unsupported editor command: " + str(method))


if __name__ == "__main__":
    if not os.path.isfile(CORE):
        raise SystemExit("PIXEL TICKLER engine is not built. Run linux/run.sh first.")
    app.run()

# ===== SNAPSMACK EOF =====
