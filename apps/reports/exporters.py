"""CSV / Excel / PDF export of any tabular report.

A view builds one `ReportTable` from the same filtered queryset it shows on
screen; the exporters only format it. The organisation name, tagline and
contact details always come from BusinessSettings.
"""
import csv
import io
from dataclasses import dataclass, field
from datetime import date, datetime
from decimal import Decimal

from django.core.exceptions import PermissionDenied
from django.http import HttpResponse
from django.utils import timezone
from django.utils.text import slugify

from apps.audit import services as audit
from apps.audit.models import AuditLog
from apps.core.models import BusinessSettings
from apps.core.templatetags.erp import format_inr

EXPORT_FORMATS = ("csv", "xlsx", "pdf")
NAVY = "0B1F33"
ORANGE = "FF6B00"


@dataclass
class ReportTable:
    title: str
    columns: list  # (label, key, kind) with kind in text|money|number|date|datetime|percent
    rows: list
    totals: dict = field(default_factory=dict)
    filters: list = field(default_factory=list)  # [(label, value)]
    summary: list = field(default_factory=list)  # [(label, value)] shown above the table
    totals_label: str = "Total"

    @property
    def money_note(self):
        return "All amounts in Indian Rupees (INR)."


def _fmt(value, kind, for_pdf=False):
    if value is None or value == "":
        return ""
    if kind == "money":
        return format_inr(value)
    if kind == "number":
        return f"{value:,.2f}".rstrip("0").rstrip(".") if isinstance(value, (Decimal, float)) else str(value)
    if kind == "percent":
        return f"{value}%"
    if kind == "date" and isinstance(value, date):
        return value.strftime("%d-%m-%Y")
    if kind == "datetime" and isinstance(value, datetime):
        return timezone.localtime(value).strftime("%d-%m-%Y %H:%M")
    return str(value)


def _filename(table, ext):
    return f"{slugify(table.title)}-{timezone.localdate():%Y%m%d}.{ext}"


def export_csv(table, business):
    buffer = io.StringIO()
    buffer.write("﻿")  # BOM so Excel opens UTF-8 correctly
    writer = csv.writer(buffer)
    writer.writerow([business.business_name])
    if business.tagline:
        writer.writerow([business.tagline])
    writer.writerow([table.title])
    for label, value in table.filters:
        writer.writerow([f"{label}: {value}"])
    for label, value in table.summary:
        writer.writerow([label, value])
    writer.writerow([])
    writer.writerow([c[0] for c in table.columns])
    for row in table.rows:
        writer.writerow([
            f"{row.get(key):.2f}" if kind == "money" and row.get(key) is not None else _fmt(row.get(key), kind)
            for _, key, kind in table.columns
        ])
    if table.totals:
        writer.writerow([
            table.totals_label if i == 0 else (
                f"{table.totals[key]:.2f}" if key in table.totals and kind == "money" else _fmt(table.totals.get(key), kind)
            )
            for i, (_, key, kind) in enumerate(table.columns)
        ])
    writer.writerow([])
    writer.writerow([f"Generated {timezone.localtime():%d-%m-%Y %H:%M} · {table.money_note}"])
    response = HttpResponse(buffer.getvalue(), content_type="text/csv; charset=utf-8")
    response["Content-Disposition"] = f'attachment; filename="{_filename(table, "csv")}"'
    return response


def export_xlsx(table, business):
    from openpyxl import Workbook
    from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
    from openpyxl.utils import get_column_letter

    wb = Workbook()
    ws = wb.active
    ws.title = table.title[:31]
    ncols = max(len(table.columns), 1)
    last = get_column_letter(ncols)
    navy = PatternFill("solid", fgColor=NAVY)
    orange = PatternFill("solid", fgColor=ORANGE)
    thin = Side(style="thin", color="D6DFEA")

    def banner(row, text, font, fill=None, height=None):
        ws.merge_cells(f"A{row}:{last}{row}")
        cell = ws[f"A{row}"]
        cell.value = text
        cell.font = font
        cell.alignment = Alignment(horizontal="left", vertical="center")
        if fill:
            for c in range(1, ncols + 1):
                ws.cell(row=row, column=c).fill = fill
        if height:
            ws.row_dimensions[row].height = height

    r = 1
    banner(r, business.business_name.upper(), Font(size=16, bold=True, color="FFFFFF"), navy, 28); r += 1
    sub = " · ".join(p for p in [business.tagline, business.contact_line] if p)
    banner(r, sub, Font(size=9, color="FFFFFF"), orange, 16); r += 1
    if business.address:
        banner(r, " ".join(business.address.split()), Font(size=9, color="475569")); r += 1
    r += 1
    banner(r, table.title, Font(size=13, bold=True, color=NAVY), height=20); r += 1
    for label, value in table.filters:
        banner(r, f"{label}: {value}", Font(size=9, color="475569")); r += 1
    if table.summary:
        r += 1
        for label, value in table.summary:
            ws.cell(row=r, column=1, value=label).font = Font(bold=True, color=NAVY)
            ws.cell(row=r, column=2, value=float(value) if isinstance(value, Decimal) else value)
            if isinstance(value, Decimal):
                ws.cell(row=r, column=2).number_format = "#,##0.00"
            r += 1
    r += 1

    header_row = r
    for i, (label, _, kind) in enumerate(table.columns, start=1):
        cell = ws.cell(row=r, column=i, value=label)
        cell.font = Font(bold=True, color="FFFFFF")
        cell.fill = navy
        cell.alignment = Alignment(horizontal="right" if kind in ("money", "number", "percent") else "left",
                                   vertical="center", wrap_text=True)
    ws.row_dimensions[r].height = 22
    r += 1

    widths = [len(c[0]) + 2 for c in table.columns]
    for idx, row in enumerate(table.rows):
        for i, (_, key, kind) in enumerate(table.columns, start=1):
            value = row.get(key)
            if kind in ("money", "number") and value is not None and value != "":
                value = float(value)
            elif kind == "datetime" and isinstance(value, datetime):
                value = timezone.localtime(value).replace(tzinfo=None)
            elif kind not in ("date",) and value is not None and not isinstance(value, (int, float)):
                value = str(value)
            cell = ws.cell(row=r, column=i, value=value)
            cell.border = Border(bottom=thin)
            if kind == "money":
                cell.number_format = "#,##0.00"
            elif kind == "date":
                cell.number_format = "DD-MM-YYYY"
            elif kind == "datetime":
                cell.number_format = "DD-MM-YYYY HH:MM"
            if idx % 2:
                cell.fill = PatternFill("solid", fgColor="F5F7FA")
            widths[i - 1] = min(max(widths[i - 1], len(_fmt(row.get(key), kind)) + 2), 60)
        r += 1

    if table.totals:
        for i, (_, key, kind) in enumerate(table.columns, start=1):
            value = table.totals_label if i == 1 else table.totals.get(key)
            if isinstance(value, Decimal):
                value = float(value)
            cell = ws.cell(row=r, column=i, value=value)
            cell.font = Font(bold=True, color=NAVY)
            cell.fill = PatternFill("solid", fgColor="FFE4CF")
            cell.border = Border(top=Side(style="medium", color=ORANGE))
            if kind == "money":
                cell.number_format = "#,##0.00"
        r += 1

    r += 1
    banner(r, f"Generated {timezone.localtime():%d-%m-%Y %H:%M} · {table.money_note} {business.report_footer}",
           Font(size=8, italic=True, color="64748B"))
    for i, w in enumerate(widths, start=1):
        ws.column_dimensions[get_column_letter(i)].width = max(w, 10)
    ws.freeze_panes = ws.cell(row=header_row + 1, column=1)
    ws.print_title_rows = f"{header_row}:{header_row}"
    ws.page_setup.orientation = "landscape" if ncols > 6 else "portrait"
    ws.page_setup.fitToWidth = 1
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    ws.page_setup.fitToHeight = 0

    out = io.BytesIO()
    wb.save(out)
    response = HttpResponse(
        out.getvalue(), content_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    )
    response["Content-Disposition"] = f'attachment; filename="{_filename(table, "xlsx")}"'
    return response


def export_pdf(table, business, user=None):
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_RIGHT
    from reportlab.lib.pagesizes import A4, landscape
    from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
    from reportlab.lib.units import mm
    from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

    navy = colors.HexColor("#" + NAVY)
    orange = colors.HexColor("#" + ORANGE)
    pagesize = landscape(A4) if len(table.columns) > 6 else A4
    out = io.BytesIO()
    doc = SimpleDocTemplate(
        out, pagesize=pagesize, leftMargin=12 * mm, rightMargin=12 * mm, topMargin=34 * mm, bottomMargin=16 * mm,
        title=f"{table.title} - {business.business_name}", author=business.business_name,
    )
    styles = getSampleStyleSheet()
    cell = ParagraphStyle("cell", parent=styles["Normal"], fontSize=7.8, leading=9.5)
    cell_r = ParagraphStyle("cellr", parent=cell, alignment=TA_RIGHT)
    head = ParagraphStyle("head", parent=cell, textColor=colors.white, fontName="Helvetica-Bold")
    head_r = ParagraphStyle("headr", parent=head, alignment=TA_RIGHT)
    title_style = ParagraphStyle("title", parent=styles["Heading2"], textColor=navy, spaceAfter=2, fontSize=14)
    meta = ParagraphStyle("meta", parent=styles["Normal"], fontSize=8.5, textColor=colors.HexColor("#475569"))

    def esc(text):
        return str(text).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")

    story = [Paragraph(esc(table.title), title_style)]
    for label, value in table.filters:
        story.append(Paragraph(f"<b>{esc(label)}:</b> {esc(value)}", meta))
    if table.summary:
        story.append(Spacer(1, 4))
        sdata = [[Paragraph(f"<b>{esc(label)}</b>", cell),
                  Paragraph(format_inr(v) if isinstance(v, Decimal) else esc(v), cell_r)]
                 for label, v in table.summary]
        st = Table(sdata, colWidths=[60 * mm, 40 * mm], hAlign="LEFT")
        st.setStyle(TableStyle([("BOX", (0, 0), (-1, -1), 0.5, colors.HexColor("#D6DFEA")),
                                ("INNERGRID", (0, 0), (-1, -1), 0.25, colors.HexColor("#E2E8F0")),
                                ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#F5F7FA"))]))
        story.append(st)
    story.append(Spacer(1, 6))

    numeric = {"money", "number", "percent"}
    data = [[Paragraph(esc(label), head_r if kind in numeric else head) for label, _, kind in table.columns]]
    for row in table.rows:
        data.append([
            Paragraph(esc(_fmt(row.get(key), kind)), cell_r if kind in numeric else cell)
            for _, key, kind in table.columns
        ])
    if table.totals:
        data.append([
            Paragraph(f"<b>{esc(table.totals_label if i == 0 else _fmt(table.totals.get(key), kind))}</b>",
                      cell_r if kind in numeric else cell)
            for i, (_, key, kind) in enumerate(table.columns)
        ])
    if len(data) == 1:
        data.append([Paragraph("No records for the selected filters.", cell)] + [""] * (len(table.columns) - 1))

    avail = pagesize[0] - doc.leftMargin - doc.rightMargin
    weights = [1.6 if kind == "text" else 1.0 for _, _, kind in table.columns]
    col_widths = [avail * w / sum(weights) for w in weights]
    t = Table(data, colWidths=col_widths, repeatRows=1)
    style = [
        ("BACKGROUND", (0, 0), (-1, 0), navy),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("LINEBELOW", (0, 0), (-1, 0), 1.2, orange),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F5F7FA")]),
        ("LINEBELOW", (0, 1), (-1, -1), 0.25, colors.HexColor("#E2E8F0")),
        ("TOPPADDING", (0, 0), (-1, -1), 4), ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]
    if table.totals:
        style += [("BACKGROUND", (0, -1), (-1, -1), colors.HexColor("#FFE4CF")),
                  ("LINEABOVE", (0, -1), (-1, -1), 1, orange)]
    t.setStyle(TableStyle(style))
    story.append(t)
    story.append(Spacer(1, 6))
    story.append(Paragraph(table.money_note, meta))

    generated = timezone.localtime().strftime("%d-%m-%Y %H:%M")
    by = f" by {user}" if user else ""

    def decorate(canvas, doc_):
        w, h = pagesize
        canvas.saveState()
        canvas.setFillColor(navy)
        canvas.rect(0, h - 24 * mm, w, 24 * mm, fill=1, stroke=0)
        canvas.setFillColor(orange)
        canvas.rect(0, h - 25.5 * mm, w, 1.5 * mm, fill=1, stroke=0)
        x = doc_.leftMargin
        if business.logo:
            try:
                canvas.drawImage(business.logo.path, x, h - 20 * mm, 16 * mm, 16 * mm,
                                 preserveAspectRatio=True, mask="auto")
                x += 19 * mm
            except Exception:  # missing/corrupt logo file must not break the report
                pass
        canvas.setFillColor(colors.white)
        canvas.setFont("Helvetica-Bold", 16)
        canvas.drawString(x, h - 11 * mm, business.business_name.upper())
        canvas.setFont("Helvetica", 8)
        canvas.setFillColor(colors.HexColor("#FFC79E"))
        canvas.drawString(x, h - 15.5 * mm, business.tagline or "")
        canvas.setFillColor(colors.HexColor("#D6DFEA"))
        details = " | ".join(p for p in [" ".join(business.address.split()), business.contact_line] if p)
        canvas.drawString(x, h - 19.5 * mm, details[:160])
        canvas.setFont("Helvetica-Bold", 9)
        canvas.setFillColor(colors.white)
        canvas.drawRightString(w - doc_.rightMargin, h - 11 * mm, business.erp_title)
        canvas.setFont("Helvetica", 7.5)
        canvas.setFillColor(colors.HexColor("#64748B"))
        canvas.drawString(doc_.leftMargin, 8 * mm, f"Generated {generated}{by}. {business.report_footer}")
        canvas.drawRightString(w - doc_.rightMargin, 8 * mm, f"Page {doc_.page}")
        canvas.restoreState()

    doc.build(story, onFirstPage=decorate, onLaterPages=decorate)
    response = HttpResponse(out.getvalue(), content_type="application/pdf")
    response["Content-Disposition"] = f'attachment; filename="{_filename(table, "pdf")}"'
    return response


def render_export(request, table, fmt):
    if not request.user.has_capability("export_reports"):
        raise PermissionDenied("You do not have permission to export reports.")
    business = BusinessSettings.load()
    audit.log(AuditLog.Action.EXPORT, model_name="Report", object_repr=f"{table.title} ({fmt.upper()})",
              changes={"rows": len(table.rows), "filters": dict(table.filters)})
    if fmt == "csv":
        return export_csv(table, business)
    if fmt == "xlsx":
        return export_xlsx(table, business)
    return export_pdf(table, business, request.user)


class ExportMixin:
    """Add to a view with `build_table()`; `?export=csv|xlsx|pdf` downloads it."""

    def get(self, request, *args, **kwargs):
        fmt = request.GET.get("export")
        if fmt in EXPORT_FORMATS:
            return render_export(request, self.build_table(), fmt)
        return super().get(request, *args, **kwargs)

    def build_table(self):  # pragma: no cover - must be implemented by the view
        raise NotImplementedError
