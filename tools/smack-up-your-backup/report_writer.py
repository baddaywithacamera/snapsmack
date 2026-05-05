"""
Smack Up Your Backup — report_writer.py
Export audit reports as .txt or styled .html.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
from typing import Optional

from audit_engine import (
    AuditReport,
    HEALTHY, MISSING_FROM_SERVER, ORPHANED_ON_SERVER,
    ORPHANED_IN_DB, NOT_IN_DB, SIZE_MISMATCH, WRONG_LOCATION,
)


def write_txt(report: AuditReport, path: str) -> None:
    lines = []
    _txt_header(report, lines)
    _txt_summary(report, lines)
    _txt_section(report, lines, MISSING_FROM_SERVER, "MISSING FROM SERVER",
                 "These files are in the manifest but not found on the server. Upload them.")
    _txt_section(report, lines, WRONG_LOCATION, "WRONG LOCATION",
                 "These files exist on the server but at the wrong path.")
    _txt_section(report, lines, SIZE_MISMATCH, "SIZE MISMATCH",
                 "These files exist but their size doesn't match the manifest. Re-upload.")
    _txt_section(report, lines, NOT_IN_DB, "NOT IN DATABASE",
                 "These image files exist on the server but have no database record.")
    _txt_section(report, lines, ORPHANED_IN_DB, "ORPHANED IN DATABASE",
                 "Database rows pointing to files not in the manifest.")
    if report.orphan_server:
        lines += ["", f"═ ORPHANED ON SERVER ({len(report.orphan_server)}) ═",
                  "These files are on the server but not in the manifest.",
                  "They may be post-export uploads or leftover junk.", ""]
        for p in report.orphan_server:
            lines.append(f"  {p}")
    _txt_healthy(report, lines)
    with open(path, "w", encoding="utf-8") as f:
        f.write("\n".join(lines))


def _txt_header(report: AuditReport, lines: list) -> None:
    lines += [
        "=" * 72,
        "  SMACK UP YOUR BACKUP — AUDIT REPORT",
        f"  Site:  {report.site_name}  ({report.site_url})",
        f"  Date:  {report.audit_date}",
        "=" * 72,
        "",
    ]


def _txt_summary(report: AuditReport, lines: list) -> None:
    total = sum(report.summary.values())
    lines += ["SUMMARY", "─" * 40]
    for cat, label in [
        (HEALTHY,             "Healthy"),
        (MISSING_FROM_SERVER, "Missing from server"),
        (WRONG_LOCATION,      "Wrong location"),
        (SIZE_MISMATCH,       "Size mismatch"),
        (NOT_IN_DB,           "Not in database"),
        (ORPHANED_IN_DB,      "Orphaned in database"),
        (ORPHANED_ON_SERVER,  "Orphaned on server"),
    ]:
        n   = report.summary.get(cat, 0)
        pct = f"{100 * n // max(total, 1)}%"
        lines.append(f"  {label:<26} {n:>5}  ({pct})")
    lines += ["", f"  Total manifest entries: {total}", ""]


def _txt_section(report: AuditReport, lines: list, cat: str, title: str, advice: str) -> None:
    entries = report.by_category(cat)
    if not entries:
        return
    lines += ["", f"═ {title} ({len(entries)}) ═", advice, ""]
    for e in entries:
        line = f"  {e.restores_to}"
        if e.note:
            line += f"  →  {e.note}"
        lines.append(line)


def _txt_healthy(report: AuditReport, lines: list) -> None:
    n = report.summary.get(HEALTHY, 0)
    lines += ["", f"═ HEALTHY ({n}) ═",
              "These files are present on the server at the correct path and size.", ""]
    entries = report.by_category(HEALTHY)
    for e in entries[:50]:
        lines.append(f"  ✓  {e.restores_to}")
    if len(entries) > 50:
        lines.append(f"  … and {len(entries) - 50} more.")


# ---------------------------------------------------------------------------
# HTML report
# ---------------------------------------------------------------------------

def write_html(report: AuditReport, path: str) -> None:
    sections = ""
    sections += _html_problems(report, MISSING_FROM_SERVER, "Missing from Server",
        "#c0392b", "In manifest but not found on FTP. These need to be uploaded.")
    sections += _html_problems(report, WRONG_LOCATION, "Wrong Location",
        "#e67e22", "Found on FTP but at a different path than the manifest expects.")
    sections += _html_problems(report, SIZE_MISMATCH, "Size Mismatch",
        "#e67e22", "On FTP but wrong size — possibly corrupt or truncated. Re-upload.")
    sections += _html_problems(report, NOT_IN_DB, "Not in Database",
        "#8e44ad", "On FTP and in manifest but no database record. Broken if it's an image.")
    sections += _html_problems(report, ORPHANED_IN_DB, "Orphaned in Database",
        "#2980b9", "Database row pointing to a file not in the manifest.")
    if report.orphan_server:
        sections += _html_orphan_server(report)
    sections += _html_healthy(report)

    html = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Audit — {report.site_name}</title>
<style>
  body{{font-family:Arial,sans-serif;background:#141414;color:#ddd;margin:0;padding:24px;}}
  h1{{color:#39FF14;font-size:1.6em;margin-bottom:4px;}}
  .meta{{color:#888;font-size:.85em;margin-bottom:24px;}}
  .summary{{background:#1e1e1e;border-radius:6px;padding:16px;margin-bottom:24px;display:flex;flex-wrap:wrap;gap:12px;}}
  .chip{{border-radius:4px;padding:6px 14px;font-size:.9em;font-weight:bold;}}
  .section{{margin-bottom:20px;}}
  .section-header{{display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none;padding:10px 14px;border-radius:6px;background:#1e1e1e;}}
  .section-header h2{{margin:0;font-size:1em;}}
  .badge{{border-radius:12px;padding:2px 10px;font-size:.8em;font-weight:bold;color:#fff;}}
  .rows{{background:#1a1a1a;border-radius:0 0 6px 6px;overflow:hidden;}}
  .row{{padding:8px 14px;font-size:.83em;font-family:Consolas,monospace;border-bottom:1px solid #252525;}}
  .row:last-child{{border-bottom:none;}}
  .note{{color:#888;margin-left:12px;}}
  details>summary{{list-style:none;}} details>summary::-webkit-details-marker{{display:none;}}
</style>
</head>
<body>
<h1>Smack Up Your Backup — Audit Report</h1>
<div class="meta">{report.site_name} &nbsp;·&nbsp; {report.site_url} &nbsp;·&nbsp; {report.audit_date}</div>
{_html_summary(report)}
{sections}
</body>
</html>"""
    with open(path, "w", encoding="utf-8") as f:
        f.write(html)


def _html_summary(report: AuditReport) -> str:
    colours = {
        HEALTHY:             ("#39FF14", "#0a2200"),
        MISSING_FROM_SERVER: ("#e74c3c", "#2c0000"),
        WRONG_LOCATION:      ("#e67e22", "#2c1500"),
        SIZE_MISMATCH:       ("#f39c12", "#2c1e00"),
        NOT_IN_DB:           ("#9b59b6", "#1a0030"),
        ORPHANED_IN_DB:      ("#3498db", "#001830"),
        ORPHANED_ON_SERVER:  ("#95a5a6", "#1a1a1a"),
    }
    labels = {
        HEALTHY: "Healthy", MISSING_FROM_SERVER: "Missing",
        WRONG_LOCATION: "Wrong Location", SIZE_MISMATCH: "Size Mismatch",
        NOT_IN_DB: "Not in DB", ORPHANED_IN_DB: "Orphaned (DB)",
        ORPHANED_ON_SERVER: "Orphaned (Server)",
    }
    chips = ""
    for cat, (fg, bg) in colours.items():
        n = report.summary.get(cat, 0)
        chips += (
            f'<div class="chip" style="color:{fg};background:{bg}">'
            f'{labels[cat]}: {n}</div>'
        )
    return f'<div class="summary">{chips}</div>'


def _html_problems(
    report: AuditReport, cat: str, title: str, colour: str, advice: str
) -> str:
    entries = report.by_category(cat)
    if not entries:
        return ""
    rows = "".join(
        f'<div class="row">{e.restores_to}'
        + (f'<span class="note">{e.note}</span>' if e.note else "")
        + "</div>"
        for e in entries
    )
    return f"""<details class="section" open>
<summary class="section-header">
  <h2>{title}</h2>
  <span class="badge" style="background:{colour}">{len(entries)}</span>
  <span style="color:#888;font-size:.85em">{advice}</span>
</summary>
<div class="rows">{rows}</div>
</details>"""


def _html_orphan_server(report: AuditReport) -> str:
    rows = "".join(f'<div class="row">{p}</div>' for p in report.orphan_server)
    return f"""<details class="section">
<summary class="section-header">
  <h2>Orphaned on Server</h2>
  <span class="badge" style="background:#7f8c8d">{len(report.orphan_server)}</span>
  <span style="color:#888;font-size:.85em">On FTP but not in manifest. Post-export uploads or junk.</span>
</summary>
<div class="rows">{rows}</div>
</details>"""


def _html_healthy(report: AuditReport) -> str:
    entries = report.by_category(HEALTHY)
    if not entries:
        return ""
    rows = "".join(
        f'<div class="row" style="color:#555">✓ {e.restores_to}</div>'
        for e in entries
    )
    return f"""<details class="section">
<summary class="section-header">
  <h2>Healthy</h2>
  <span class="badge" style="background:#1a3a00;color:#39FF14">{len(entries)}</span>
  <span style="color:#888;font-size:.85em">All good. Collapsed by default.</span>
</summary>
<div class="rows">{rows}</div>
</details>"""
# ===== SNAPSMACK EOF =====
