"""Financial aggregations shared by the dashboard, reports and exports.

All figures come from APPROVED database rows. See the rules at the top of
models.py; this module is the single place where totals are computed.
"""
from collections import OrderedDict
from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal

from django.db.models import Count, DecimalField, OuterRef, Q, Subquery, Sum, Value
from django.db.models.functions import Coalesce, TruncMonth
from django.utils import timezone

from apps.core.models import BusinessSettings
from apps.customers.models import Customer
from apps.fleet.models import Driver, Vehicle, VehicleCategory

from .models import ZERO, Expense, Income, Payment, PaymentMode, customer_balance_rows, money

MONEY_FIELD = DecimalField(max_digits=14, decimal_places=2)


def _sum(qs):
    return money(qs.aggregate(t=Sum("amount"))["t"])


def _by(qs, key):
    return {row[key]: money(row["t"]) for row in qs.values(key).annotate(t=Sum("amount")).order_by(key)}


def financial_summary(start=None, end=None):
    incomes = Income.objects.approved().between(start, end)
    expenses = Expense.objects.approved().between(start, end)
    payments = Payment.objects.approved().between(start, end)

    income_total = _sum(incomes)
    expense_total = _sum(expenses)
    received_total = _sum(payments)

    income_by_cat = _by(incomes, "vehicle_category")
    # Expenses are split by the category of the vehicle they are linked to;
    # expenses without a vehicle are general overheads. These three parts sum
    # exactly to expense_total.
    expense_by_vcat = _by(expenses.filter(vehicle__isnull=False), "vehicle__category")
    general_expense = _sum(expenses.filter(vehicle__isnull=True))

    categories = OrderedDict()
    for key, label in VehicleCategory.choices:
        inc = income_by_cat.get(key, ZERO)
        exp = expense_by_vcat.get(key, ZERO)
        categories[key] = {"label": label, "income": inc, "expense": exp, "profit": inc - exp}

    outstanding = outstanding_total(end)
    return {
        "income_total": income_total,
        "expense_total": expense_total,
        "net_profit": income_total - expense_total,
        "received_total": received_total,
        "outstanding_total": outstanding,
        "general_expense": general_expense,
        "driver_expense_total": _sum(expenses.filter(category=Expense.Category.DRIVER)),
        "categories": categories,
        "expense_by_category": expense_breakdown(expenses),
        "income_count": incomes.count(),
        "expense_count": expenses.count(),
        "collection_rate": (received_total / income_total * 100).quantize(Decimal("0.1")) if income_total else None,
        "margin": ((income_total - expense_total) / income_total * 100).quantize(Decimal("0.1")) if income_total else None,
    }


def outstanding_total(end=None):
    """Sum of what customers owe as of `end` (opening balances included).
    Customers in credit (advance paid) are not netted against debtors."""
    total = ZERO
    for c in customer_balance_rows(end=end):
        bal = c.opening_balance + c.billed - c.received
        if bal > 0:
            total += bal
    return money(total)


def expense_breakdown(expenses):
    labels = dict(Expense.Category.choices)
    data = _by(expenses, "category")
    return [{"key": k, "label": labels[k], "amount": data.get(k, ZERO)} for k in labels if data.get(k)]


def monthly_trend(start=None, end=None, months=12):
    """Income billed, payments received and expenses per month."""
    today = timezone.localdate()
    if not end:
        end = today
    if not start or (end.year - start.year) * 12 + end.month - start.month >= 24:
        y, m = end.year, end.month - (months - 1)
        while m <= 0:
            m += 12
            y -= 1
        start = date(y, m, 1)

    def series(qs):
        rows = (
            qs.between(start, end).annotate(m=TruncMonth("date")).values("m").annotate(t=Sum("amount")).order_by("m")
        )
        return {(r["m"].year, r["m"].month): money(r["t"]) for r in rows}

    inc = series(Income.objects.approved())
    exp = series(Expense.objects.approved())
    rec = series(Payment.objects.approved())

    labels, income, expense, received = [], [], [], []
    y, m = start.year, start.month
    while (y, m) <= (end.year, end.month):
        labels.append(date(y, m, 1).strftime("%b %Y"))
        income.append(float(inc.get((y, m), 0)))
        expense.append(float(exp.get((y, m), 0)))
        received.append(float(rec.get((y, m), 0)))
        m += 1
        if m > 12:
            m, y = 1, y + 1
    return {"labels": labels, "income": income, "expense": expense, "received": received}


def _subsum(qs, link):
    return Coalesce(
        Subquery(qs.filter(**{link: OuterRef("pk")}).values(link).annotate(s=Sum("amount")).values("s")[:1],
                 output_field=MONEY_FIELD),
        Value(ZERO), output_field=MONEY_FIELD,
    )


def vehicle_profitability(start=None, end=None, category=None, include_idle=True):
    incomes = Income.objects.approved().between(start, end)
    expenses = Expense.objects.approved().between(start, end)
    payments = Payment.objects.approved().between(start, end).filter(income__isnull=False)
    qs = Vehicle.objects.all()
    if category:
        qs = qs.filter(category=category)
    qs = qs.annotate(
        income=_subsum(incomes, "vehicle"),
        expense=_subsum(expenses, "vehicle"),
        received=_subsum(payments, "income__vehicle"),
        driver_expense=_subsum(expenses.filter(category=Expense.Category.DRIVER), "vehicle"),
        diesel_expense=_subsum(expenses.filter(category=Expense.Category.DIESEL), "vehicle"),
        jobs=Coalesce(
            Subquery(incomes.filter(vehicle=OuterRef("pk")).values("vehicle").annotate(c=Count("id")).values("c")[:1]),
            Value(0),
        ),
    ).order_by("category", "registration_number")
    rows = []
    for v in qs:
        if not include_idle and not (v.income or v.expense):
            continue
        profit = v.income - v.expense
        rows.append({
            "vehicle": v, "income": v.income, "expense": v.expense, "profit": profit,
            "received": v.received, "driver_expense": v.driver_expense, "diesel_expense": v.diesel_expense,
            "jobs": v.jobs,
            "margin": (profit / v.income * 100).quantize(Decimal("0.1")) if v.income else None,
        })
    return rows


def driver_expense_summary(start=None, end=None):
    expenses = Expense.objects.approved().between(start, end).filter(category=Expense.Category.DRIVER)
    rows = []
    for d in Driver.objects.annotate(total=_subsum(expenses, "driver")).filter(total__gt=0).order_by("-total"):
        by_type = _by(expenses.filter(driver=d), "driver_expense_type")
        rows.append({"driver": d, "total": d.total, "by_type": by_type})
    return rows


@dataclass
class CashBookEntry:
    date: date
    number: str
    particulars: str
    mode: str
    receipt: Decimal = ZERO
    payment: Decimal = ZERO
    balance: Decimal = ZERO
    url: str = ""


@dataclass
class CashBook:
    opening: Decimal
    entries: list = field(default_factory=list)
    total_receipts: Decimal = ZERO
    total_payments: Decimal = ZERO

    @property
    def closing(self):
        return self.opening + self.total_receipts - self.total_payments


def cash_book(start=None, end=None, mode=None):
    """Money actually received (approved payments) and money actually paid out
    (approved expenses), with a running balance."""
    settings = BusinessSettings.load()
    payments = Payment.objects.approved().select_related("customer", "income")
    expenses = Expense.objects.approved().select_related("vehicle", "driver")
    if mode:
        payments = payments.filter(mode=mode)
        expenses = expenses.filter(payment_mode=mode)

    opening = settings.opening_cash_balance if not mode else ZERO
    if start:
        opening += _sum(payments.filter(date__lt=start)) - _sum(expenses.filter(date__lt=start))

    modes = dict(PaymentMode.choices)
    entries = []
    for p in payments.between(start, end):
        inv = f" against {p.income.number}" if p.income_id else " (advance / on account)"
        entries.append(CashBookEntry(p.date, p.number, f"Received from {p.customer}{inv}", modes[p.mode],
                                     receipt=p.amount, url=f"/finance/payments/{p.pk}/"))
    for e in expenses.between(start, end):
        parts = [e.get_category_display()]
        if e.driver_id:
            parts.append(e.driver.name)
        if e.vehicle_id:
            parts.append(e.vehicle.display_name)
        if e.vendor:
            parts.append(e.vendor)
        entries.append(CashBookEntry(e.date, e.number, " · ".join(parts), modes[e.payment_mode],
                                     payment=e.amount, url=f"/finance/expenses/{e.pk}/"))
    entries.sort(key=lambda x: (x.date, x.number))

    book = CashBook(opening=money(opening))
    running = book.opening
    for entry in entries:
        running += entry.receipt - entry.payment
        entry.balance = running
        book.total_receipts += entry.receipt
        book.total_payments += entry.payment
    book.entries = entries
    return book


def customer_outstanding_rows(search="", only_due=False):
    customers = Customer.objects.all()
    if search:
        customers = customers.filter(Q(name__icontains=search) | Q(phone__icontains=search))
    rows = []
    for c in customer_balance_rows(customers).order_by("name"):
        balance = money(c.opening_balance + c.billed - c.received)
        if only_due and balance <= 0:
            continue
        rows.append({"customer": c, "opening": c.opening_balance, "billed": c.billed,
                     "received": c.received, "balance": balance})
    return rows


def recent_transactions(user, limit=10):
    items = []
    for inc in Income.objects.visible_to(user).select_related("customer", "vehicle")[:limit]:
        items.append({"kind": "Income", "obj": inc, "date": inc.date, "amount": inc.amount, "sign": 1,
                      "title": inc.customer.name, "subtitle": inc.vehicle.display_name,
                      "url": f"/finance/income/{inc.pk}/", "created": inc.created_at})
    for exp in Expense.objects.visible_to(user).select_related("vehicle", "driver")[:limit]:
        sub = exp.driver.name if exp.driver_id else (exp.vehicle.display_name if exp.vehicle_id else "General")
        items.append({"kind": "Expense", "obj": exp, "date": exp.date, "amount": exp.amount, "sign": -1,
                      "title": exp.get_category_display(), "subtitle": sub,
                      "url": f"/finance/expenses/{exp.pk}/", "created": exp.created_at})
    for pay in Payment.objects.visible_to(user).select_related("customer")[:limit]:
        items.append({"kind": "Payment", "obj": pay, "date": pay.date, "amount": pay.amount, "sign": 1,
                      "title": pay.customer.name, "subtitle": pay.get_mode_display(),
                      "url": f"/finance/payments/{pay.pk}/", "created": pay.created_at})
    items.sort(key=lambda x: (x["date"], x["created"]), reverse=True)
    return items[:limit]
