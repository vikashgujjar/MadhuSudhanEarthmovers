from django import forms
from django.contrib.auth.mixins import LoginRequiredMixin
from django.urls import reverse, reverse_lazy
from django.views.generic import DetailView, ListView, TemplateView

from apps.core.forms import StyledFormMixin
from apps.core.generic import AuditedCreateView, AuditedDeleteView, AuditedUpdateView
from apps.core.permissions import AdminRequiredMixin, CapabilityRequiredMixin
from apps.core.views_mixins import SearchFilterMixin
from apps.finance import services as fin
from apps.finance.models import ZERO, Income, Payment, Status, customer_balance_rows, money
from apps.reports.exporters import ExportMixin, ReportTable

from .models import Customer

FINANCE_VIEW = ("view_reports", "record_payment")


class CustomerForm(StyledFormMixin, forms.ModelForm):
    class Meta:
        model = Customer
        fields = ["name", "contact_person", "phone", "email", "gstin", "opening_balance", "is_active", "address", "notes"]

    def clean_name(self):
        name = " ".join(self.cleaned_data["name"].split())
        if Customer.objects.filter(name__iexact=name).exclude(pk=self.instance.pk).exists():
            raise forms.ValidationError("A customer with this name already exists.")
        return name

    def clean_phone(self):
        phone = self.cleaned_data.get("phone", "").strip()
        if phone and len([c for c in phone if c.isdigit()]) < 10:
            raise forms.ValidationError("Enter a valid phone number (at least 10 digits).")
        return phone

    def clean_gstin(self):
        gstin = self.cleaned_data.get("gstin", "").strip().upper()
        if gstin and (len(gstin) != 15 or not gstin.isalnum()):
            raise forms.ValidationError("GSTIN must be 15 letters/digits.")
        return gstin


def can_see_balances(user):
    return any(user.has_capability(c) for c in FINANCE_VIEW)


class CustomerListView(LoginRequiredMixin, ExportMixin, SearchFilterMixin, ListView):
    template_name = "customers/customer_list.html"
    context_object_name = "customers"
    search_fields = ("name", "contact_person", "phone", "email", "gstin")

    def get_queryset(self):
        qs = Customer.objects.all()
        status = self.request.GET.get("status")
        if status == "active":
            qs = qs.filter(is_active=True)
        elif status == "inactive":
            qs = qs.filter(is_active=False)
        qs = self.apply_search(qs)
        if can_see_balances(self.request.user):
            qs = customer_balance_rows(qs)
        return qs.order_by("name")

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["show_balances"] = can_see_balances(self.request.user)
        for c in ctx["customers"]:
            if ctx["show_balances"]:
                c.balance = money(c.opening_balance + c.billed - c.received)
        return ctx

    def build_table(self):
        show = can_see_balances(self.request.user)
        rows = []
        for c in self.get_queryset():
            row = {"name": c.name, "contact": c.contact_person, "phone": c.phone, "email": c.email, "gstin": c.gstin,
                   "status": "Active" if c.is_active else "Inactive"}
            if show:
                row.update(billed=c.billed, received=c.received,
                           balance=money(c.opening_balance + c.billed - c.received))
            rows.append(row)
        cols = [("Customer", "name", "text"), ("Contact", "contact", "text"), ("Phone", "phone", "text"),
                ("Email", "email", "text"), ("GSTIN", "gstin", "text"), ("Status", "status", "text")]
        if show:
            cols += [("Billed", "billed", "money"), ("Received", "received", "money"), ("Balance", "balance", "money")]
        return ReportTable(title="Customer Register", columns=cols, rows=rows)


class CustomerDetailView(LoginRequiredMixin, DetailView):
    model = Customer
    template_name = "customers/customer_detail.html"
    context_object_name = "customer"

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        user = self.request.user
        c = self.object
        ctx["show_balances"] = can_see_balances(user)
        if ctx["show_balances"]:
            ctx["statement"], ctx["totals"] = customer_statement(c)
            ctx["open_invoices"] = [
                i for i in Income.objects.approved().filter(customer=c).order_by("date") if i.outstanding > 0
            ]
            ctx["pending_income"] = Income.objects.pending().filter(customer=c)
            ctx["pending_payments"] = Payment.objects.pending().filter(customer=c)
        else:
            ctx["my_income"] = Income.objects.visible_to(user).filter(customer=c)[:10]
        return ctx


def customer_statement(customer):
    """Ledger of approved invoices (debit) and payments (credit) with running balance."""
    lines = []
    for inc in Income.objects.approved().filter(customer=customer).select_related("vehicle"):
        lines.append({"date": inc.date, "number": inc.number, "obj": inc, "kind": "Invoice",
                      "particulars": f"{inc.vehicle.display_name} · {inc.get_billing_type_display()}"
                                     f"{' · ' + inc.work_location if inc.work_location else ''}",
                      "debit": inc.amount, "credit": ZERO, "url": inc.get_absolute_url()})
    for pay in Payment.objects.approved().filter(customer=customer).select_related("income"):
        lines.append({"date": pay.date, "number": pay.number, "obj": pay, "kind": "Payment",
                      "particulars": f"{pay.get_mode_display()}"
                                     f"{' against ' + pay.income.number if pay.income_id else ' · advance / on account'}"
                                     f"{' · Ref ' + pay.reference if pay.reference else ''}",
                      "debit": ZERO, "credit": pay.amount, "url": pay.get_absolute_url()})
    lines.sort(key=lambda x: (x["date"], 0 if x["kind"] == "Invoice" else 1, x["number"]))
    balance = customer.opening_balance
    for line in lines:
        balance += line["debit"] - line["credit"]
        line["balance"] = balance
    totals = {
        "opening": customer.opening_balance,
        "debit": sum((x["debit"] for x in lines), ZERO),
        "credit": sum((x["credit"] for x in lines), ZERO),
        "closing": money(balance),
    }
    return lines, totals


class CustomerCreateView(CapabilityRequiredMixin, AuditedCreateView):
    capability = "manage_masters"
    model = Customer
    form_class = CustomerForm
    template_name = "customers/customer_form.html"

    def get_success_url(self):
        nxt = self.request.GET.get("next")
        if nxt and nxt.startswith("/") and not nxt.startswith("//"):
            return nxt
        return reverse("customers:detail", args=[self.object.pk])


class CustomerUpdateView(CapabilityRequiredMixin, AuditedUpdateView):
    capability = "manage_masters"
    model = Customer
    form_class = CustomerForm
    template_name = "customers/customer_form.html"

    def get_success_url(self):
        return reverse("customers:detail", args=[self.object.pk])


class CustomerDeleteView(AdminRequiredMixin, AuditedDeleteView):
    model = Customer
    success_url = reverse_lazy("customers:list")
    protected_message = "This customer has invoices or payments and cannot be deleted. Mark them inactive instead."


class OutstandingView(CapabilityRequiredMixin, ExportMixin, TemplateView):
    capability = FINANCE_VIEW
    template_name = "customers/outstanding.html"

    def rows(self):
        return fin.customer_outstanding_rows(
            search=(self.request.GET.get("q") or "").strip(),
            only_due=self.request.GET.get("show") != "all",
        )

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        rows = self.rows()
        ctx["rows"] = rows
        ctx["totals"] = {
            "opening": sum((r["opening"] for r in rows), ZERO),
            "billed": sum((r["billed"] for r in rows), ZERO),
            "received": sum((r["received"] for r in rows), ZERO),
            # Advances held for one customer do not reduce what others owe.
            "balance": sum((r["balance"] for r in rows if r["balance"] > 0), ZERO),
        }
        ctx["pending_payments"] = Payment.objects.filter(status=Status.PENDING).count()
        return ctx

    def build_table(self):
        rows = self.rows()
        data = [{"name": r["customer"].name, "phone": r["customer"].phone, "opening": r["opening"],
                 "billed": r["billed"], "received": r["received"], "balance": r["balance"]} for r in rows]
        return ReportTable(
            title="Customer Outstanding Balances",
            columns=[("Customer", "name", "text"), ("Phone", "phone", "text"), ("Opening balance", "opening", "money"),
                     ("Billed", "billed", "money"), ("Received", "received", "money"),
                     ("Outstanding", "balance", "money")],
            rows=data,
            totals={
                **{k: sum((r[k] for r in data), ZERO) for k in ("opening", "billed", "received")},
                "balance": sum((r["balance"] for r in data if r["balance"] > 0), ZERO),
            },
            filters=[("Showing", "All customers" if self.request.GET.get("show") == "all" else "Customers with dues"),
                     ("Basis", "Approved invoices and payments to date")],
        )
