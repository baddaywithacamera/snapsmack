#!/usr/bin/env python3
"""
Render a SECAUDIT markdown report to the published PDF.

    python tools/_build/build-secaudit-pdf.py secaudits/2026-08-07-041-....md
    python tools/_build/build-secaudit-pdf.py --all          # every missing PDF

Buzzers (projects/snapsmack-ca/buzzers.php) links `.pdf`, and the published
copies live in projects/snapsmack-ca/secaudits/ — a different directory from the
markdown originals in secaudits/. A report that exists only as markdown is a dead
"Read the full report" link on the public security page, which is a poor look on
the one page whose entire job is showing that we do this properly.

WHY THIS FILE EXISTS. The first five of these were built by five near-identical
throwaway scripts under .codex-temp/ and tmp/, each with its source path, output
path and document title hardcoded. That works exactly once per audit and then
rots. This is the same renderer — same fonts, same crimson, same table styling,
so new reports sit beside the old ones without looking imported — with the
per-audit parts derived from the filename instead of retyped.

Layout lifted from .codex-temp/build_secaudit_039_pdf.py so output stays
consistent with audits 035-039. If you change the look here, the back catalogue
will no longer match; regenerate all of them or change nothing.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import re
import sys
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.pagesizes import LETTER
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.platypus import (
    BaseDocTemplate, Frame, PageTemplate, Paragraph, Spacer, Table, TableStyle,
    PageBreak,
)

ROOT = Path(__file__).resolve().parents[2]
SRC_DIR = ROOT / "secaudits"
OUT_DIR = ROOT / "projects/snapsmack-ca/secaudits"

RED = colors.HexColor("#D40000")
INK = colors.HexColor("#191919")
MID = colors.HexColor("#666666")
LIGHT = colors.HexColor("#F2F2F2")
RULE = colors.HexColor("#D8D8D8")


def normalize(text: str) -> str:
    """Flatten typography ReportLab's core fonts cannot render."""
    return (
        text.replace("—", " - ")
        .replace("–", "-")
        .replace("−", "-")
        .replace("‑", "-")
        .replace(" ", " ")
        .replace("→", "->")
        .replace("←", "<-")
        .replace("“", '"').replace("”", '"')
        .replace("‘", "'").replace("’", "'")
        .replace("…", "...")
        .replace("×", "x")
    )


def inline(text: str) -> str:
    """
    Markdown emphasis -> ReportLab markup.

    Code spans are pulled out to placeholders BEFORE the emphasis passes and put
    back afterwards. Converting them in place looks equivalent and is not: a
    literal asterisk inside one code span and another inside the next made the
    italic pattern match straight across the intervening tags, producing
    `<font ...>google_<i></font> ... </i></font>` — interleaved markup that
    ReportLab rejects with a parse error naming neither the file nor the line.
    The one-off scripts this was generalised from carry the same bug; they simply
    never met a report that used the pattern.
    """
    text = escape(normalize(text))

    spans: list[str] = []

    def _stash(m):
        spans.append(m.group(1))
        return f"\x00CODE{len(spans) - 1}\x00"

    text = re.sub(r"`([^`]+)`", _stash, text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"\*([^*]+)\*", r"<i>\1</i>", text)

    for i, span in enumerate(spans):
        text = text.replace(f"\x00CODE{i}\x00", f'<font name="Courier">{span}</font>')
    return text


styles = getSampleStyleSheet()
body = ParagraphStyle(
    "AuditBody", parent=styles["BodyText"], fontName="Helvetica", fontSize=9.2,
    leading=13.2, textColor=INK, spaceAfter=7, allowWidows=0, allowOrphans=0,
)
h1 = ParagraphStyle("AuditTitle", parent=body, fontName="Helvetica-Bold",
                    fontSize=20, leading=23, textColor=INK, spaceAfter=8)
subtitle = ParagraphStyle("AuditSubtitle", parent=body, fontName="Helvetica-Bold",
                          fontSize=10, leading=13, textColor=RED, spaceAfter=18)
h2 = ParagraphStyle("AuditH2", parent=body, fontName="Helvetica-Bold", fontSize=13,
                    leading=16, textColor=INK, spaceBefore=12, spaceAfter=6,
                    keepWithNext=True)
h3 = ParagraphStyle("AuditH3", parent=body, fontName="Helvetica-Bold", fontSize=10.5,
                    leading=14, textColor=RED, spaceBefore=9, spaceAfter=4,
                    keepWithNext=True)
bullet = ParagraphStyle("AuditBullet", parent=body, leftIndent=15,
                        firstLineIndent=-8, bulletIndent=5, spaceAfter=4)
code = ParagraphStyle("AuditCode", parent=body, fontName="Courier", fontSize=7.3,
                      leading=10, leftIndent=8, rightIndent=8, backColor=LIGHT,
                      borderPadding=7, spaceAfter=8)
small = ParagraphStyle("AuditSmall", parent=body, fontSize=7.7, leading=10.5,
                       textColor=MID)


class AuditDoc(BaseDocTemplate):
    def __init__(self, filename, title):
        super().__init__(
            filename, pagesize=LETTER,
            leftMargin=0.72 * inch, rightMargin=0.72 * inch,
            topMargin=0.72 * inch, bottomMargin=0.45 * inch,
            title=title, author="SnapSmack Security Review", subject=title,
        )
        frame = Frame(self.leftMargin, self.bottomMargin, self.width, self.height,
                      id="main")
        self.addPageTemplates(PageTemplate(id="audit", frames=[frame]))


def render(src: Path, out: Path) -> None:
    lines = src.read_text(encoding="utf-8").splitlines()
    story, paragraph, code_lines, table_rows = [], [], [], []
    in_code = False
    in_html_comment = False
    first_heading = True
    doc_title = src.stem

    def flush_paragraph():
        if paragraph:
            story.append(Paragraph(inline(" ".join(x.strip() for x in paragraph)), body))
            paragraph.clear()

    def flush_table():
        if not table_rows:
            return
        rows = [[Paragraph(inline(c.strip()), small) for c in row] for row in table_rows]
        widths = [1.25 * inch, 5.75 * inch] if len(rows[0]) == 2 else None
        tbl = Table(rows, colWidths=widths, repeatRows=1, hAlign="LEFT")
        tbl.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#222222")),
            ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
            ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
            ("BACKGROUND", (0, 1), (0, -1), LIGHT),
            ("GRID", (0, 0), (-1, -1), 0.45, RULE),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 6),
            ("RIGHTPADDING", (0, 0), (-1, -1), 6),
            ("TOPPADDING", (0, 0), (-1, -1), 5),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        ]))
        story.extend([tbl, Spacer(1, 8)])
        table_rows.clear()

    for raw in lines:
        line = raw.rstrip()
        if not in_html_comment and line.startswith("<!--"):
            if not line.endswith("-->"):
                in_html_comment = True
            continue
        if in_html_comment:
            if line.strip() == "-->":
                in_html_comment = False
            continue
        if line.startswith("```"):
            flush_paragraph()
            flush_table()
            if in_code:
                story.append(Paragraph(
                    "<br/>".join(escape(normalize(x)) for x in code_lines), code))
                code_lines.clear()
            in_code = not in_code
            continue
        if in_code:
            code_lines.append(line)
            continue
        if re.match(r"^\|.*\|$", line):
            flush_paragraph()
            cells = list(line.strip("|").split("|"))
            if all(re.fullmatch(r"\s*:?-+:?\s*", c or "") for c in cells):
                continue
            table_rows.append(cells)
            continue
        flush_table()
        if not line.strip() or line.strip() == "---":
            flush_paragraph()
            if line.strip() == "---":
                story.append(Spacer(1, 5))
            continue
        if line.startswith("# "):
            flush_paragraph()
            if not first_heading:
                story.append(PageBreak())
            else:
                doc_title = normalize(line[2:]).strip()
            story.append(Paragraph(inline(line[2:]), h1))
            story.append(Paragraph("SECURITY AUDIT / PUBLIC REMEDIATION RECORD", subtitle))
            first_heading = False
            continue
        if line.startswith("## "):
            flush_paragraph()
            story.append(Paragraph(inline(line[3:]), h2))
            continue
        if line.startswith("### "):
            flush_paragraph()
            story.append(Paragraph(inline(line[4:]), h3))
            continue
        m = re.match(r"^\s*(?:[-*]|\d+\.)\s+(.*)$", line)
        if m:
            flush_paragraph()
            num = re.match(r"^\s*(\d+\.)", line)
            story.append(Paragraph(inline(m.group(1)), bullet,
                                   bulletText=(num.group(1) if num else "•")))
            continue
        paragraph.append(line)

    flush_paragraph()
    flush_table()
    out.parent.mkdir(parents=True, exist_ok=True)
    AuditDoc(str(out), doc_title).build(story)


def main(argv) -> int:
    if not argv:
        print(__doc__.strip().split("\n\n")[1])
        return 2

    if argv[0] == "--all":
        sources = sorted(SRC_DIR.glob("*.md"))
        pending = [s for s in sources if not (OUT_DIR / f"{s.stem}.pdf").exists()]
        if not pending:
            print("Every audit already has a published PDF.")
            return 0
        targets = pending
    else:
        targets = [Path(a) if Path(a).is_absolute() else ROOT / a for a in argv]

    for src in targets:
        if not src.is_file():
            print(f"missing source: {src}", file=sys.stderr)
            return 1
        out = OUT_DIR / f"{src.stem}.pdf"
        render(src, out)
        kb = out.stat().st_size / 1024
        print(f"  {out.relative_to(ROOT)}  ({kb:.0f} KB)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
# ===== SNAPSMACK EOF =====
