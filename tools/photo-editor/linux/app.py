"""PIXEL TICKLER Linux/Blink host. Original SnapSmack code, SPL 2.1."""

import json
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
    global _input_path
    params = dict(params or {})
    if method == "hello":
        return {"contract_version": 1, "host": "blink-linux"}
    if method == "choose_image":
        selected = snap_blink.pick_path("file", "Open JPEG, PNG, WebP, or TIFF")
        if not selected:
            return None
        _input_path = os.path.realpath(selected)
        params.update({"input_path": _input_path, "operations": []})
        return _core("render_preview", params, [os.path.dirname(_input_path)])
    if not _input_path:
        raise RuntimeError("Open an image first")
    params["input_path"] = _input_path
    if method == "render_preview":
        return _core(method, params, [os.path.dirname(_input_path)])
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
