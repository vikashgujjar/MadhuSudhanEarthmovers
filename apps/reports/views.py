from decimal import Decimal

from django.db.models import Sum
from django.views.generic import TemplateView

from apps.core.models import BusinessSettings
from apps.core.periods import PERIOD_CHOICES, resolve_period
from apps.core.permissions import CapabilityRequiredMixin
from apps.finance import services as fin
from apps.finance.models import ZERO, Expense, Income, PaymentMode, money
from apps.fleet.models import VehicleCategory

from .exporters import ExportMixin, ReportTable


class ReportView(CapabilityRequiredMixin, ExportMixin, TemplateView):
    capability = "view_reports"
    default_period = "this_fy"

    def get_period(self):
        if not hasattr(self, "_period"):
            business = BusinessSettings.load()
            self._period = resolve_period(
                self.request.GET, business.financial_year_start_month, default=self.default_period
            )
        return self._period

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx.update(period=self.get_period(), period_choices=PERIOD_CHOICES)
        return ctx

    def period_filter(self):
        return [("Period", self.get_period().label), ("Basis", "Approved transactions only")]


class ReportsIndexView(CapabilityRequiredMixin, TemplateView):
    capability = "view_reports"
    template_name = "reports/index.html"


# ------------------------------------------------------------------ Cash book
class CashBookView(ReportView):
    template_name = "reports/cashbook.html"
    default_period = "this_month"

    def get_mode(self):
        mode = self.request.GET.get("mode")
        return mode if mode in PaymentMode.values else None

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        period = self.get_period()
        ctx["book"] = fin.cash_book(period.start, period.end, self.get_mode())
        ctx["modes"] = PaymentMode.choices
        return ctx

    def build_table(self):
        period = self.get_period()
        book = fin.cash_book(period.start, period.end, self.get_mode())
        rows = [{"date": None, "number": "", "particulars": "Opening balance", "mode": "", "receipt": None,
                 "payment": None, "balance": book.opening}]
        rows += [{"date": e.date, "number": e.number, "particulars": e.particulars, "mode": e.mode,
                  "receipt": e.receipt or None, "payment": e.payment or None, "balance": e.balance}
                 for e in book.entries]
        mode = dict(PaymentMode.choices).get(self.get_mode(), "All modes")
        return ReportTable(
            title="Cash Book",
            columns=[("Date", "date", "date"), ("Voucher", "number", "text"), ("Particulars", "particulars", "text"),
                     ("Mode", "mode", "text"), ("Receipts", "receipt", "money"), ("Payments", "payment", "money"),
                     ("Balance", "balance", "money")],
            rows=rows,
            totals={"receipt": book.total_receipts, "payment": book.total_payments, "balance": book.closing},
            totals_label="Closing balance",
            filters=self.period_filter() + [("Mode", mode)],
        )


# --------------------------------------------------------- Vehicle profitability
class ProfitabilityView(ReportView):
    template_name = "reports/profitability.html"

    def get_category(self):
        cat = self.request.GET.get("category")
        return cat if cat in VehicleCategory.values else None

    def data(self):
        period = self.get_period()
        rows = fin.vehicle_profitability(period.start, period.end, category=self.get_category())
        totals = {k: sum((r[k] for r in rows), ZERO) for k in ("income", "expense", "profit", "received",
                                                               "driver_expense", "diesel_expense")}
        totals["margin"] = (totals["profit"] / totals["income"] * 100).quantize(Decimal("0.1")) if totals["income"] else None
        return rows, totals

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        rows, totals = self.data()
        period = self.get_period()
        summary = fin.financial_summary(period.start, period.end)
        ctx.update(
            rows=rows, totals=totals, categories=VehicleCategory.choices, category=self.get_category(),
            summary=summary,
            chart={"labels": [r["vehicle"].display_name for r in rows if r["income"] or r["expense"]],
                   "income": [float(r["income"]) for r in rows if r["income"] or r["expense"]],
                   "expense": [float(r["expense"]) for r in rows if r["income"] or r["expense"]],
                   "profit": [float(r["profit"]) for r in rows if r["income"] or r["expense"]]},
        )
        return ctx

    def build_table(self):
        rows, totals = self.data()
        data = [{"vehicle": r["vehicle"].display_name, "reg": r["vehicle"].registration_number,
                 "category": r["vehicle"].get_category_display(), "jobs": r["jobs"], "income": r["income"],
                 "diesel": r["diesel_expense"], "driver": r["driver_expense"], "expense": r["expense"],
                 "profit": r["profit"], "margin": r["margin"], "received": r["received"]} for r in rows]
        cat = self.get_category()
        return ReportTable(
            title="Vehicle-wise Profitability",
            columns=[("Vehicle", "vehicle", "text"), ("Registration", "reg", "text"), ("Category", "category", "text"),
                     ("Jobs", "jobs", "number"), ("Income", "income", "money"), ("Diesel", "diesel", "money"),
                     ("Driver", "driver", "money"), ("Total expense", "expense", "money"),
                     ("Profit", "profit", "money"), ("Margin", "margin", "percent"), ("Collected", "received", "money")],
            rows=data,
            totals={"income": totals["income"], "diesel": totals["diesel_expense"], "driver": totals["driver_expense"],
                    "expense": totals["expense"], "profit": totals["profit"], "received": totals["received"]},
            filters=self.period_filter() + [("Category", VehicleCategory(cat).label if cat else "JCB & Tractor")],
        )


# ------------------------------------------------------------------- P&L
class ProfitLossView(ReportView):
    template_name = "reports/pnl.html"

    def data(self):
        period = self.get_period()
        incomes = Income.objects.approved().between(period.start, period.end)
        expenses = Expense.objects.approved().between(period.start, period.end)
        cat_labels = dict(VehicleCategory.choices)
        bill_labels = dict(Income.BillingType.choices)
        income_lines = [
            {"label": f"{cat_labels[r['vehicle_category']]} — {bill_labels[r['billing_type']]}", "amount": money(r["t"])}
            for r in incomes.values("vehicle_category", "billing_type").annotate(t=Sum("amount"))
            .order_by("vehicle_category", "billing_type")
        ]
        exp_labels = dict(Expense.Category.choices)
        expense_lines = [
            {"label": exp_labels[r["category"]], "amount": money(r["t"])}
            for r in expenses.values("category").annotate(t=Sum("amount")).order_by("-t")
        ]
        total_income = incomes.total()
        total_expense = expenses.total()
        return {
            "income_lines": income_lines, "expense_lines": expense_lines,
            "total_income": total_income, "total_expense": total_expense, "net": total_income - total_expense,
            "summary": fin.financial_summary(period.start, period.end),
        }

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx.update(self.data())
        return ctx

    def build_table(self):
        d = self.data()
        rows = [{"section": "INCOME", "label": "", "amount": None}]
        rows += [{"section": "", "label": x["label"], "amount": x["amount"]} for x in d["income_lines"]]
        rows += [{"section": "", "label": "Total income billed", "amount": d["total_income"]},
                 {"section": "EXPENSES", "label": "", "amount": None}]
        rows += [{"section": "", "label": x["label"], "amount": x["amount"]} for x in d["expense_lines"]]
        rows += [{"section": "", "label": "Total expenses", "amount": d["total_expense"]}]
        s = d["summary"]
        return ReportTable(
            title="Profit & Loss Summary",
            columns=[("Section", "section", "text"), ("Particulars", "label", "text"), ("Amount", "amount", "money")],
            rows=rows, totals={"amount": d["net"]}, totals_label="Net operating profit",
            filters=self.period_filter(),
            summary=[("Payments received (cash basis)", s["received_total"]),
                     ("Customer outstanding", s["outstanding_total"])],
        )


# --------------------------------------------------------------- Driver expenses
class DriverExpenseReportView(ReportView):
    template_name = "reports/driver_expenses.html"

    def data(self):
        period = self.get_period()
        rows = fin.driver_expense_summary(period.start, period.end)
        types = Expense.DriverExpenseType.choices
        totals = {k: sum((r["by_type"].get(k, ZERO) for r in rows), ZERO) for k, _ in types}
        totals["total"] = sum((r["total"] for r in rows), ZERO)
        return rows, types, totals

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        rows, types, totals = self.data()
        for r in rows:
            r["cells"] = [r["by_type"].get(k, ZERO) for k, _ in types]
        ctx.update(rows=rows, types=types, totals=totals, total_cells=[totals[k] for k, _ in types],
                   overall=fin.financial_summary(self.get_period().start, self.get_period().end))
        return ctx

    def build_table(self):
        rows, types, totals = self.data()
        data = []
        for r in rows:
            row = {"driver": r["driver"].name, "vehicle": r["driver"].assigned_vehicle.display_name
                   if r["driver"].assigned_vehicle else "", "total": r["total"]}
            row.update({k: r["by_type"].get(k) for k, _ in types})
            data.append(row)
        return ReportTable(
            title="Driver Expense Summary",
            columns=[("Driver", "driver", "text"), ("Assigned vehicle", "vehicle", "text")]
            + [(label, key, "money") for key, label in types] + [("Total", "total", "money")],
            rows=data, totals=totals, filters=self.period_filter(),
        )


# ------------------------------------------------------- Expense category matrix
class ExpenseCategoryReportView(ReportView):
    template_name = "reports/expense_categories.html"

    def data(self):
        period = self.get_period()
        expenses = Expense.objects.approved().between(period.start, period.end)
        grid = {}
        for r in expenses.values("category", "vehicle__category").annotate(t=Sum("amount")):
            grid.setdefault(r["category"], {})[r["vehicle__category"] or "GENERAL"] = money(r["t"])
        rows = []
        for key, label in Expense.Category.choices:
            cells = grid.get(key, {})
            row = {"category": label, "JCB": cells.get("JCB", ZERO), "TRACTOR": cells.get("TRACTOR", ZERO),
                   "GENERAL": cells.get("GENERAL", ZERO)}
            row["total"] = row["JCB"] + row["TRACTOR"] + row["GENERAL"]
            rows.append(row)
        totals = {k: sum((r[k] for r in rows), ZERO) for k in ("JCB", "TRACTOR", "GENERAL", "total")}
        return rows, totals

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["rows"], ctx["totals"] = self.data()
        return ctx

    def build_table(self):
        rows, totals = self.data()
        return ReportTable(
            title="Expenses by Category",
            columns=[("Category", "category", "text"), ("JCB", "JCB", "money"), ("Tractor", "TRACTOR", "money"),
                     ("General (no vehicle)", "GENERAL", "money"), ("Total", "total", "money")],
            rows=rows, totals=totals, filters=self.period_filter(),
        )
