"""Credential-free reconciliation reports for GHOSTED jobs."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import html
import json
from pathlib import Path


def write(job_dir, blog, summary, warnings=()):
    root = Path(job_dir)
    root.mkdir(parents=True, exist_ok=True)
    manifest = {
        "schema": 1,
        "source_site_id": blog.source_id,
        "source_title": blog.title,
        "ghost_version": blog.ghost_version,
        "source_counts": blog.counts(),
        "result_counts": dict(summary),
        "warnings": list(warnings),
    }
    manifest_path = root / "ghosted-manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")

    rows = "".join(
        f"<tr><th>{html.escape(str(k))}</th><td>{int(v)}</td></tr>"
        for k, v in sorted(summary.items())
    )
    warning_html = "".join(f"<li>{html.escape(str(w))}</li>" for w in warnings) or "<li>None</li>"
    report_path = root / "ghosted-report.html"
    report_path.write_text(
        """<!doctype html><meta charset="utf-8"><title>GHOSTED report</title>
<style>body{font:16px system-ui;max-width:850px;margin:3rem auto;background:#0d120f;color:#edf3ea}
table{border-collapse:collapse}th,td{padding:.6rem 1rem;border:1px solid #38513f;text-align:left}
h1{color:#73f04b}</style>"""
        + f"<h1>GHOSTED</h1><h2>{html.escape(blog.title)}</h2>"
        + f"<table>{rows}</table><h2>Leftover Ghost Cards &amp; Warnings</h2><ul>{warning_html}</ul>",
        encoding="utf-8",
    )
    return manifest_path, report_path

# ===== SNAPSMACK EOF =====
