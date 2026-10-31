"""Reporting periods (financial year aware)."""
from dataclasses import dataclass
from datetime import date, timedelta

from django.utils import timezone
from django.utils.dateparse import parse_date

PERIOD_CHOICES = [
    ("this_month", "This month"),
    ("last_month", "Last month"),
    ("this_fy", "This financial year"),
    ("last_fy", "Last financial year"),
    ("all", "All time"),
    ("custom", "Custom range"),
]


@dataclass
class Period:
    key: str
    start: date | None
    end: date | None
    label: str

    @property
    def querystring(self):
        if self.key == "custom":
            return f"period=custom&from={self.start or ''}&to={self.end or ''}"
        return f"period={self.key}"


def fy_start(today, start_month):
    year = today.year if today.month >= start_month else today.year - 1
    return date(year, start_month, 1)


def resolve_period(params, start_month=4, default="this_fy"):
    today = timezone.localdate()
    key = params.get("period") or default
    if key not in dict(PERIOD_CHOICES):
        key = default
    if key == "this_month":
        start = today.replace(day=1)
        return Period(key, start, today, start.strftime("%B %Y"))
    if key == "last_month":
        end = today.replace(day=1) - timedelta(days=1)
        start = end.replace(day=1)
        return Period(key, start, end, start.strftime("%B %Y"))
    if key in ("this_fy", "last_fy"):
        start = fy_start(today, start_month)
        if key == "last_fy":
            start = date(start.year - 1, start_month, 1)
        end = date(start.year + 1, start_month, 1) - timedelta(days=1)
        label = f"FY {start.year}-{str(end.year)[-2:]}" if start_month != 1 else f"FY {start.year}"
        return Period(key, start, min(end, today) if key == "this_fy" else end, label)
    if key == "custom":
        start = parse_date(params.get("from") or "") if params.get("from") else None
        end = parse_date(params.get("to") or "") if params.get("to") else None
        if start and end and start > end:
            start, end = end, start
        label = f"{start:%d %b %Y} to {end:%d %b %Y}" if start and end else (
            f"From {start:%d %b %Y}" if start else (f"Up to {end:%d %b %Y}" if end else "All time")
        )
        return Period(key, start, end, label)
    return Period("all", None, None, "All time")
