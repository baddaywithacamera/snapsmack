"""Isolated renderer for SNAP SLAPPER blog-copy preparation.

The editor launches this module in a separate process so a large RAW render
cannot monopolize Python's runtime and make the Qt window appear hung.
"""

import json
import os
import sys

import editor_engine
import photo_manager
from slapper_qt import publishing_contract


def main(argv=None):
    argv = list(sys.argv if argv is None else argv)
    if len(argv) != 2:
        return 2
    job_path = os.path.abspath(argv[1])
    with open(job_path, "r", encoding="utf-8") as handle:
        job = json.load(handle, parse_constant=photo_manager.reject_json_constant)
    project_path = os.path.abspath(job["project_path"])
    result_path = os.path.abspath(job["result_path"])
    try:
        document = editor_engine.EditorDocument.load_project(
            project_path, trust_external_source=True)
        target, manifest_path, manifest = publishing_contract.prepare(
            document, job["profile"],
            copyright_text=job.get("copyright_text", ""),
            destination_override=job.get("destination", ""),
            filename_stem=job.get("filename_stem", ""))
        photo_manager.atomic_json(result_path, {
            "ok": True,
            "target": target,
            "manifest_path": manifest_path,
            "manifest": manifest,
        })
        return 0
    except Exception as error:  # noqa: BLE001 - transported to the parent UI
        photo_manager.atomic_json(result_path, {"ok": False, "error": str(error)})
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
