from django.contrib import messages
from django.contrib.auth.mixins import LoginRequiredMixin
from django.core.exceptions import PermissionDenied, ValidationError
from django.db import transaction
from django.db.models import DecimalField, F, OuterRef, ProtectedError, Subquery, Sum, Value
from django.db.models.functions import Coalesce
from django.http import JsonResponse
from django.shortcuts import get_object_or_404, redirect
from django.urls import reverse
from django.views import View
from django.views.generic import CreateView, DeleteView, DetailView, ListView, TemplateView, UpdateView

from apps.audit import services as audit
from apps.core.models import BusinessSettings
from apps.core.periods import PERIOD_CHOICES, resolve_period
from apps.core.permissions import AdminRequiredMixin
from apps.core.views_mixins import SearchFilterMixin
from apps.customers.models import Customer
from apps.fleet.models import Driver, Vehicle, VehicleCategory
from apps.reports.exporters import ExportMixin, ReportTable

from . import workflow
from .forms import ExpenseForm, IncomeForm, PaymentForm, RejectForm
from .models import ZERO, Expense, Income, Payment, PaymentMode, Status, money

MONEY_FIELD = DecimalField(max_digits=14, decimal_places=2)


def _received_subquery():
    approved = Payment.objects.approved().filter(income=OuterRef("pk"))
    return Coalesce(
        Subquery(approved.values("income").annotate(s=Sum("amount")).values("s")[:1], output_field=MONEY_FIELD),
        Value(ZERO), output_field=MONEY_FIELD,
    )


# ------------------------------------------------------------------ list base
class TransactionListView(LoginRequiredMixin, ExportMixin, SearchFilterMixin, ListView):
    """Shared filtering, totals and export for transaction registers."""

    model = None
    title = ""

    def get_period(self):
        if not hasattr(self, "_period"):
            business = BusinessSettings.load()
            self._period = resolve_period(self.request.GET, business.financial_year_start_month, default="all")
        return self._period

    def base_queryset(self):
        return self.model.objects.visible_to(self.request.user)

    def filter_queryset(self, qs):
        g = self.request.GET
        period = self.get_period()
        qs = qs.between(period.start, period.end)
        if g.get("status") in Status.values:
            qs = qs.filter(status=g["status"])
        return self.apply_search(qs)

    def get_queryset(self):
        return self.filter_queryset(self.base_queryset())

    def filter_summary(self):
        period = self.get_period()
        parts = [("Period", period.label)]
        status = self.request.GET.get("status")
        parts.append(("Status", dict(Status.choices)[status] if status in Status.values else "All statuses"))
        if self.request.GET.get("q"):
            parts.append(("Search", self.request.GET["q"]))
        return parts

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        qs = self.get_queryset()
        ctx.update(
            period=self.get_period(), period_choices=PERIOD_CHOICES, statuses=Status.choices,
            total_approved=qs.approved().total(), total_pending=qs.pending().total(),
            total_count=qs.count(), title=self.title,
        )
        return ctx


def _vehicle_filters(view, qs):
    g = view.request.GET
    category = g.get("vehicle_category")
    if category in VehicleCategory.values:
        qs = qs.filter(vehicle_category=category)
    vehicle = g.get("vehicle")
    if vehicle and vehicle.isdigit():
        qs = qs.filter(vehicle_id=vehicle)
    return qs


def _vehicle_choices(request):
    category = request.GET.get("vehicle_category")
    qs = Vehicle.objects.all()
    if category in VehicleCategory.values:
        qs = qs.filter(category=category)
    return qs


# ---------------------------------------------------------------------- Income
class IncomeListView(TransactionListView):
    model = Income
    title = "Income Register"
    template_name = "finance/income_list.html"
    context_object_name = "incomes"
    search_fields = ("number", "customer__name", "vehicle__registration_number", "vehicle__name", "work_location",
                     "description")

    def filter_queryset(self, qs):
        qs = _vehicle_filters(self, super().filter_queryset(qs))
        g = self.request.GET
        if g.get("customer", "").isdigit():
            qs = qs.filter(customer_id=g["customer"])
        if g.get("billing_type") in Income.BillingType.values:
            qs = qs.filter(billing_type=g["billing_type"])
        qs = qs.select_related("customer", "vehicle", "driver").annotate(received=_received_subquery())
        payment = g.get("payment")
        if payment == "unpaid":
            qs = qs.filter(received=0)
        elif payment == "partial":
            qs = qs.filter(received__gt=0, received__lt=F("amount"))
        elif payment == "paid":
            qs = qs.filter(received__gte=F("amount"))
        return qs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        qs = self.get_queryset().approved()
        received = qs.aggregate(r=Sum("received"))["r"] or ZERO
        ctx.update(
            categories=VehicleCategory.choices, vehicles=_vehicle_choices(self.request),
            customers=Customer.objects.order_by("name"), billing_types=Income.BillingType.choices,
            total_received=money(received), total_outstanding=money(ctx["total_approved"] - received),
        )
        for inc in ctx["incomes"]:
            inc.balance = money(inc.amount - inc.received)
        return ctx

    def build_table(self):
        rows, approved = [], []
        for i in self.get_queryset():
            qty = f"{i.quantity} {i.unit_label} × {i.rate}" if i.quantity else ""
            rows.append({
                "date": i.date, "number": i.number, "customer": i.customer.name, "category": i.get_vehicle_category_display(),
                "vehicle": i.vehicle.display_name, "billing": i.get_billing_type_display(), "qty": qty,
                "amount": i.amount, "received": i.received, "balance": money(i.amount - i.received),
                "status": i.get_status_display(),
            })
            if i.status == Status.APPROVED:
                approved.append(rows[-1])
        return ReportTable(
            title=self.title,
            columns=[("Date", "date", "date"), ("Invoice", "number", "text"), ("Customer", "customer", "text"),
                     ("Category", "category", "text"), ("Vehicle", "vehicle", "text"), ("Billing", "billing", "text"),
                     ("Qty × rate", "qty", "text"), ("Billed", "amount", "money"), ("Received", "received", "money"),
                     ("Balance", "balance", "money"), ("Status", "status", "text")],
            rows=rows,
            totals={k: sum((r[k] for r in approved), ZERO) for k in ("amount", "received", "balance")},
            totals_label="Total (approved)",
            filters=self.filter_summary(),
        )


class IncomeDetailView(LoginRequiredMixin, DetailView):
    template_name = "finance/income_detail.html"
    context_object_name = "income"

    def get_queryset(self):
        return Income.objects.visible_to(self.request.user).select_related("customer", "vehicle", "driver")

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["payments"] = self.object.payments.select_related("created_by").order_by("date", "id")
        ctx["reject_form"] = RejectForm()
        ctx["history"] = audit_history(self.object)
        return ctx


def audit_history(obj):
    from apps.audit.models import AuditLog

    return AuditLog.objects.filter(model_name=obj._meta.verbose_name.title(), object_id=str(obj.pk))[:20]


class TransactionFormMixin:
    """Create/update plumbing shared by income, expense and payment forms."""

    capability = None
    success_message = "{obj} saved."

    def check_capability(self):
        if not self.request.user.has_capability(self.get_capability()):
            raise PermissionDenied("You do not have permission to perform this action.")

    def get_capability(self):
        return self.capability

    def dispatch(self, request, *args, **kwargs):
        if not request.user.is_authenticated:
            return redirect(f"{reverse('accounts:login')}?next={request.path}")
        self.check_capability()
        return super().dispatch(request, *args, **kwargs)

    def form_invalid(self, form):
        messages.error(self.request, "Not saved. Please correct the highlighted fields.")
        return super().form_invalid(form)


class TransactionCreateView(TransactionFormMixin, CreateView):
    def get_initial(self):
        initial = super().get_initial()
        for key in ("vehicle_category", "vehicle", "customer", "driver", "income"):
            value = self.request.GET.get(key)
            if value:
                initial[key] = value
        return initial

    @transaction.atomic
    def form_valid(self, form):
        workflow.stamp_new(form.instance, self.request.user)
        self.object = form.save()
        self.after_create(form)
        audit.log_create(self.object)
        self.flash()
        return redirect(self.get_success_url())

    def after_create(self, form):
        pass

    def flash(self):
        obj = self.object
        if obj.status == Status.PENDING:
            messages.success(self.request, f"{obj.number} saved and sent for admin approval.")
        else:
            messages.success(self.request, f"{obj.number} saved.")

    def get_success_url(self):
        if self.request.POST.get("save_and_add"):
            return self.request.path
        return self.object.get_absolute_url()


class TransactionUpdateView(TransactionFormMixin, UpdateView):
    def get_queryset(self):
        return self.model.objects.visible_to(self.request.user)

    def get_object(self, queryset=None):
        obj = super().get_object(queryset)
        if not obj.can_edit(self.request.user):
            raise PermissionDenied("Approved transactions can only be changed by an administrator.")
        self._before = audit.snapshot(obj)
        return obj

    @transaction.atomic
    def form_valid(self, form):
        workflow.stamp_edit(form.instance, self.request.user)
        self.object = form.save()
        audit.log_update(self.object, self._before)
        messages.success(self.request, f"{self.object.number} updated.")
        return redirect(self.object.get_absolute_url())


class TransactionDeleteView(AdminRequiredMixin, DeleteView):
    template_name = "partials/confirm_delete.html"
    success_url_name = ""
    protected_message = "This record cannot be deleted because other records depend on it."

    def form_valid(self, form):
        obj = self.get_object()
        label = obj.number
        try:
            with transaction.atomic():
                audit.log_delete(obj)
                obj.delete()
        except ProtectedError:
            messages.error(self.request, self.protected_message)
            return redirect(obj.get_absolute_url())
        messages.success(self.request, f"{label} deleted. A copy is kept in the audit log.")
        return redirect(self.success_url_name)


class IncomeCreateView(TransactionCreateView):
    model = Income
    form_class = IncomeForm
    template_name = "finance/income_form.html"
    capability = "add_income"

    def after_create(self, form):
        received = form.cleaned_data.get("received_now")
        if received and received > 0:
            pay = Payment(
                date=self.object.date, customer=self.object.customer, income=self.object, amount=received,
                mode=form.cleaned_data.get("payment_mode") or PaymentMode.CASH,
                reference=form.cleaned_data.get("payment_reference", ""), is_initial=True,
                notes="Received at the time of billing",
            )
            workflow.stamp_new(pay, self.request.user)
            pay.full_clean()
            pay.save()
            audit.log_create(pay)


class IncomeUpdateView(TransactionUpdateView):
    model = Income
    form_class = IncomeForm
    template_name = "finance/income_form.html"
    capability = "add_income"

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs["with_payment"] = False
        return kwargs


class IncomeDeleteView(TransactionDeleteView):
    model = Income
    success_url_name = "finance:income_list"
    protected_message = "This invoice has payments recorded against it. Delete those payments first."


# --------------------------------------------------------------------- Expenses
class ExpenseListView(TransactionListView):
    model = Expense
    title = "Expense Register"
    template_name = "finance/expense_list.html"
    context_object_name = "expenses"
    driver_only = False
    search_fields = ("number", "vendor", "bill_number", "description", "driver__name", "vehicle__registration_number",
                     "vehicle__name")

    def base_queryset(self):
        qs = super().base_queryset().select_related("vehicle", "driver")
        if self.driver_only:
            qs = qs.filter(category=Expense.Category.DRIVER)
        return qs

    def filter_queryset(self, qs):
        qs = _vehicle_filters(self, super().filter_queryset(qs))
        g = self.request.GET
        if not self.driver_only and g.get("category") in Expense.Category.values:
            qs = qs.filter(category=g["category"])
        if g.get("driver", "").isdigit():
            qs = qs.filter(driver_id=g["driver"])
        if self.driver_only and g.get("type") in Expense.DriverExpenseType.values:
            qs = qs.filter(driver_expense_type=g["type"])
        if g.get("mode") in PaymentMode.values:
            qs = qs.filter(payment_mode=g["mode"])
        if g.get("general") == "1":
            qs = qs.filter(vehicle__isnull=True)
        return qs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx.update(
            driver_only=self.driver_only, categories=VehicleCategory.choices, vehicles=_vehicle_choices(self.request),
            expense_categories=Expense.Category.choices, drivers=Driver.objects.order_by("name"),
            driver_types=Expense.DriverExpenseType.choices, modes=PaymentMode.choices,
        )
        if self.driver_only:
            approved = self.get_queryset().approved()
            ctx["by_driver"] = (
                approved.values("driver__pk", "driver__name").annotate(t=Sum("amount")).order_by("-t")[:8]
            )
        else:
            labels = dict(Expense.Category.choices)
            ctx["by_category"] = [
                {"label": labels[r["category"]], "key": r["category"], "t": r["t"]}
                for r in self.get_queryset().approved().values("category").annotate(t=Sum("amount")).order_by("-t")
            ]
        return ctx

    def build_table(self):
        rows = [{
            "date": e.date, "number": e.number, "category": e.get_category_display(),
            "type": e.get_driver_expense_type_display() if e.is_driver_expense else "",
            "vehicle": e.vehicle.display_name if e.vehicle_id else "General",
            "driver": e.driver.name if e.driver_id else "", "vendor": e.vendor, "mode": e.get_payment_mode_display(),
            "litres": e.litres, "amount": e.amount, "status": e.get_status_display(), "_approved": e.is_approved,
        } for e in self.get_queryset()]
        cols = [("Date", "date", "date"), ("No.", "number", "text")]
        if self.driver_only:
            cols += [("Driver", "driver", "text"), ("Type", "type", "text"), ("Vehicle", "vehicle", "text")]
        else:
            cols += [("Category", "category", "text"), ("Vehicle", "vehicle", "text"), ("Driver", "driver", "text"),
                     ("Litres", "litres", "number")]
        cols += [("Vendor", "vendor", "text"), ("Paid via", "mode", "text"), ("Amount", "amount", "money"),
                 ("Status", "status", "text")]
        return ReportTable(
            title=self.title, columns=cols, rows=rows,
            totals={"amount": sum((r["amount"] for r in rows if r["_approved"]), ZERO)},
            totals_label="Total (approved)", filters=self.filter_summary(),
        )


class DriverExpenseListView(ExpenseListView):
    driver_only = True
    title = "Driver Expense Register"
    template_name = "finance/expense_list.html"


class ExpenseDetailView(LoginRequiredMixin, DetailView):
    template_name = "finance/expense_detail.html"
    context_object_name = "expense"

    def get_queryset(self):
        return Expense.objects.visible_to(self.request.user).select_related("vehicle", "driver")

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["reject_form"] = RejectForm()
        ctx["history"] = audit_history(self.object)
        return ctx


class ExpenseCreateView(TransactionCreateView):
    model = Expense
    form_class = ExpenseForm
    template_name = "finance/expense_form.html"
    capability = "add_expense"
    driver_mode = False

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs["driver_mode"] = self.driver_mode
        return kwargs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["driver_mode"] = self.driver_mode
        return ctx


class DriverExpenseCreateView(ExpenseCreateView):
    capability = "add_driver_expense"
    driver_mode = True


class ExpenseUpdateView(TransactionUpdateView):
    model = Expense
    form_class = ExpenseForm
    template_name = "finance/expense_form.html"

    def get_capability(self):
        # Decide by the record being edited (driver vs other expense).
        obj = get_object_or_404(Expense, pk=self.kwargs["pk"])
        self.driver_mode = obj.category == Expense.Category.DRIVER
        return "add_driver_expense" if self.driver_mode else "add_expense"

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs["driver_mode"] = self.driver_mode
        return kwargs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["driver_mode"] = self.driver_mode
        return ctx


class ExpenseDeleteView(TransactionDeleteView):
    model = Expense
    success_url_name = "finance:expense_list"


# --------------------------------------------------------------------- Payments
class PaymentListView(TransactionListView):
    model = Payment
    title = "Payments Received"
    template_name = "finance/payment_list.html"
    context_object_name = "payments"
    search_fields = ("number", "customer__name", "reference", "income__number", "notes")

    def filter_queryset(self, qs):
        qs = super().filter_queryset(qs).select_related("customer", "income")
        g = self.request.GET
        if g.get("customer", "").isdigit():
            qs = qs.filter(customer_id=g["customer"])
        if g.get("mode") in PaymentMode.values:
            qs = qs.filter(mode=g["mode"])
        if g.get("type") == "advance":
            qs = qs.filter(income__isnull=True)
        elif g.get("type") == "invoice":
            qs = qs.filter(income__isnull=False)
        return qs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx.update(customers=Customer.objects.order_by("name"), modes=PaymentMode.choices)
        ctx["by_mode"] = [
            {"label": dict(PaymentMode.choices)[r["mode"]], "t": r["t"]}
            for r in self.get_queryset().approved().values("mode").annotate(t=Sum("amount")).order_by("-t")
        ]
        return ctx

    def build_table(self):
        rows = [{
            "date": p.date, "number": p.number, "customer": p.customer.name,
            "invoice": p.income.number if p.income_id else "Advance / on account", "mode": p.get_mode_display(),
            "reference": p.reference, "amount": p.amount, "status": p.get_status_display(), "_ok": p.is_approved,
        } for p in self.get_queryset()]
        return ReportTable(
            title=self.title,
            columns=[("Date", "date", "date"), ("Receipt", "number", "text"), ("Customer", "customer", "text"),
                     ("Invoice", "invoice", "text"), ("Mode", "mode", "text"), ("Reference", "reference", "text"),
                     ("Amount", "amount", "money"), ("Status", "status", "text")],
            rows=rows, totals={"amount": sum((r["amount"] for r in rows if r["_ok"]), ZERO)},
            totals_label="Total (approved)", filters=self.filter_summary(),
        )


class PaymentDetailView(LoginRequiredMixin, DetailView):
    template_name = "finance/payment_detail.html"
    context_object_name = "payment"

    def get_queryset(self):
        return Payment.objects.visible_to(self.request.user).select_related("customer", "income")

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["reject_form"] = RejectForm()
        ctx["history"] = audit_history(self.object)
        return ctx


class LockedPaymentMixin:
    """Lock the invoice row and re-check its balance inside the transaction, so
    two simultaneous payments cannot together exceed the amount due."""

    def form_valid(self, form):
        try:
            with transaction.atomic():
                if form.instance.income_id:
                    Income.objects.select_for_update().get(pk=form.instance.income_id)
                    form.instance.clean()
                return super().form_valid(form)
        except ValidationError as exc:
            for field, errors in exc.message_dict.items():
                form.add_error(field if field in form.fields else None, errors)
            return self.form_invalid(form)


class PaymentCreateView(LockedPaymentMixin, TransactionCreateView):
    model = Payment
    form_class = PaymentForm
    template_name = "finance/payment_form.html"
    capability = "record_payment"


class PaymentUpdateView(LockedPaymentMixin, TransactionUpdateView):
    model = Payment
    form_class = PaymentForm
    template_name = "finance/payment_form.html"
    capability = "record_payment"


class PaymentDeleteView(TransactionDeleteView):
    model = Payment
    success_url_name = "finance:payment_list"


class OpenInvoicesView(LoginRequiredMixin, View):
    """Invoices of a customer with an unpaid balance (for the payment form)."""

    def get(self, request):
        if not request.user.has_capability("record_payment"):
            raise PermissionDenied
        customer = request.GET.get("customer", "")
        include = request.GET.get("include", "")
        if not customer.isdigit():
            return JsonResponse({"invoices": []})
        rows = []
        qs = Income.objects.filter(customer_id=customer).exclude(status=Status.REJECTED).order_by("date", "id")
        for inc in qs:
            committed = inc.payments.exclude(status=Status.REJECTED).total()
            due = money(inc.amount - committed)
            if due > 0 or str(inc.pk) == include:
                pending = " · pending approval" if inc.status == Status.PENDING else ""
                rows.append({"id": inc.pk, "due": str(due),
                             "label": f"{inc.number} · {inc.date:%d %b %Y} · due {due}{pending}"})
        return JsonResponse({"invoices": rows})


# -------------------------------------------------------------------- Approvals
KINDS = {"income": Income, "expense": Expense, "payment": Payment}


class ApprovalQueueView(AdminRequiredMixin, TemplateView):
    template_name = "finance/approvals.html"

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["incomes"] = Income.objects.pending().select_related("customer", "vehicle", "created_by")
        ctx["expenses"] = Expense.objects.pending().select_related("vehicle", "driver", "created_by")
        ctx["payments"] = Payment.objects.pending().select_related("customer", "income", "created_by")
        return ctx


def _safe_next(request, fallback):
    nxt = request.POST.get("next", "")
    return nxt if nxt.startswith("/") and not nxt.startswith("//") else fallback


class ApproveView(AdminRequiredMixin, View):
    def post(self, request, kind, pk):
        model = KINDS.get(kind)
        if model is None:
            raise PermissionDenied
        obj = get_object_or_404(model, pk=pk)
        try:
            obj, cascaded = workflow.approve(obj, request.user)
            extra = f" (with {len(cascaded)} linked payment{'s' if len(cascaded) != 1 else ''})" if cascaded else ""
            messages.success(request, f"{obj.number} approved{extra}.")
        except ValidationError as exc:
            messages.error(request, " ".join(exc.messages))
        return redirect(_safe_next(request, reverse("finance:approvals")))


class RejectView(AdminRequiredMixin, View):
    def post(self, request, kind, pk):
        model = KINDS.get(kind)
        if model is None:
            raise PermissionDenied
        obj = get_object_or_404(model, pk=pk)
        try:
            obj, cascaded = workflow.reject(obj, request.user, request.POST.get("reason", ""))
            messages.success(request, f"{obj.number} rejected.")
        except ValidationError as exc:
            messages.error(request, " ".join(exc.messages))
        return redirect(_safe_next(request, reverse("finance:approvals")))


class BulkApproveView(AdminRequiredMixin, View):
    def post(self, request):
        done, failed = 0, []
        # Invoices first so that their payments can be approved in the same batch.
        for kind in ("income", "expense", "payment"):
            for pk in request.POST.getlist(kind):
                if not pk.isdigit():
                    continue
                obj = KINDS[kind].objects.filter(pk=pk).first()
                if obj is None:
                    continue
                try:
                    _, cascaded = workflow.approve(obj, request.user)
                    done += 1 + len(cascaded)
                except ValidationError as exc:
                    failed.append(f"{obj.number}: {' '.join(exc.messages)}")
        if done:
            messages.success(request, f"{done} transaction{'s' if done != 1 else ''} approved.")
        for f in failed:
            messages.warning(request, f)
        if not done and not failed:
            messages.info(request, "Select at least one transaction to approve.")
        return redirect("finance:approvals")
