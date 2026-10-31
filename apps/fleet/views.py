from django.contrib.auth.mixins import LoginRequiredMixin
from django.db.models import Count, Sum
from django.http import JsonResponse
from django.urls import reverse, reverse_lazy
from django.views import View
from django.views.generic import DetailView, ListView

from apps.core.generic import AuditedCreateView, AuditedDeleteView, AuditedUpdateView
from apps.core.models import BusinessSettings
from apps.core.periods import PERIOD_CHOICES, resolve_period
from apps.core.permissions import AdminRequiredMixin, CapabilityRequiredMixin
from apps.core.views_mixins import SearchFilterMixin
from apps.finance import services as fin
from apps.finance.models import Expense, Income
from apps.reports.exporters import ExportMixin, ReportTable

from .forms import DriverForm, VehicleForm
from .models import Driver, Vehicle, VehicleCategory


# --------------------------------------------------------------------- Vehicles
class VehicleListView(LoginRequiredMixin, ExportMixin, SearchFilterMixin, ListView):
    template_name = "fleet/vehicle_list.html"
    context_object_name = "vehicles"
    search_fields = ("registration_number", "name", "make", "model")
    category = None  # set for the dedicated JCB / Tractor screens

    def get_queryset(self):
        qs = Vehicle.objects.annotate(driver_count=Count("drivers", distinct=True))
        category = self.category or self.request.GET.get("category")
        if category in VehicleCategory.values:
            qs = qs.filter(category=category)
        status = self.request.GET.get("status")
        if status in Vehicle.Status.values:
            qs = qs.filter(status=status)
        return self.apply_search(qs).order_by("category", "registration_number")

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx.update(category=self.category, categories=VehicleCategory.choices, statuses=Vehicle.Status.choices)
        if self.category:
            ctx["category_label"] = VehicleCategory(self.category).label
            base = Vehicle.objects.filter(category=self.category)
            ctx["counts"] = {
                "total": base.count(),
                "active": base.filter(status=Vehicle.Status.ACTIVE).count(),
                "maintenance": base.filter(status=Vehicle.Status.MAINTENANCE).count(),
            }
            if self.request.user.has_capability("view_reports"):
                business = BusinessSettings.load()
                period = resolve_period(self.request.GET, business.financial_year_start_month)
                rows = fin.vehicle_profitability(period.start, period.end, category=self.category)
                ctx.update(
                    period=period, period_choices=PERIOD_CHOICES,
                    profit_map={r["vehicle"].pk: r for r in rows},
                    cat_totals={
                        "income": sum(r["income"] for r in rows),
                        "expense": sum(r["expense"] for r in rows),
                        "profit": sum(r["profit"] for r in rows),
                        "received": sum(r["received"] for r in rows),
                    },
                )
        return ctx

    def build_table(self):
        rows = [
            {"category": v.get_category_display(), "reg": v.registration_number, "name": v.name,
             "make": f"{v.make} {v.model}".strip(), "year": v.year or "", "hourly": v.hourly_rate,
             "daily": v.daily_rate, "status": v.get_status_display(), "insurance": v.insurance_expiry}
            for v in self.get_queryset()
        ]
        title = f"{VehicleCategory(self.category).label} Fleet Register" if self.category else "Vehicle Register"
        return ReportTable(
            title=title,
            columns=[("Category", "category", "text"), ("Registration", "reg", "text"), ("Name", "name", "text"),
                     ("Make / model", "make", "text"), ("Year", "year", "text"), ("Hourly rate", "hourly", "money"),
                     ("Daily rate", "daily", "money"), ("Status", "status", "text"),
                     ("Insurance expiry", "insurance", "date")],
            rows=rows,
        )


class VehicleDetailView(LoginRequiredMixin, DetailView):
    model = Vehicle
    template_name = "fleet/vehicle_detail.html"
    context_object_name = "vehicle"

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        user = self.request.user
        v = self.object
        ctx["drivers"] = v.drivers.all()
        ctx["incomes"] = Income.objects.visible_to(user).filter(vehicle=v).select_related("customer")[:10]
        ctx["expenses"] = Expense.objects.visible_to(user).filter(vehicle=v).select_related("driver")[:10]
        if user.has_capability("view_reports"):
            business = BusinessSettings.load()
            period = resolve_period(self.request.GET, business.financial_year_start_month)
            rows = fin.vehicle_profitability(period.start, period.end)
            ctx.update(period=period, period_choices=PERIOD_CHOICES,
                       stats=next((r for r in rows if r["vehicle"].pk == v.pk), None))
            expenses = Expense.objects.approved().between(period.start, period.end).filter(vehicle=v)
            ctx["expense_breakdown"] = fin.expense_breakdown(expenses)
        return ctx


class VehicleCreateView(CapabilityRequiredMixin, AuditedCreateView):
    capability = "manage_masters"
    model = Vehicle
    form_class = VehicleForm
    template_name = "fleet/vehicle_form.html"

    def get_initial(self):
        category = self.request.GET.get("category")
        return {"category": category} if category in VehicleCategory.values else {}

    def get_success_url(self):
        return reverse("fleet:vehicle_detail", args=[self.object.pk])


class VehicleUpdateView(CapabilityRequiredMixin, AuditedUpdateView):
    capability = "manage_masters"
    model = Vehicle
    form_class = VehicleForm
    template_name = "fleet/vehicle_form.html"

    def get_success_url(self):
        return reverse("fleet:vehicle_detail", args=[self.object.pk])


class VehicleDeleteView(AdminRequiredMixin, AuditedDeleteView):
    model = Vehicle
    success_url = reverse_lazy("fleet:vehicle_list")
    protected_message = (
        "This vehicle has income or expense records and cannot be deleted. "
        "Set its status to 'Inactive / sold' instead to keep the history intact."
    )


class VehicleOptionsView(LoginRequiredMixin, View):
    """JSON list of usable vehicles for a category (category-first selection)."""

    def get(self, request):
        category = request.GET.get("category")
        if category not in VehicleCategory.values:
            return JsonResponse({"vehicles": [], "error": "Unknown category"}, status=400)
        vehicles = Vehicle.objects.filter(category=category).exclude(status=Vehicle.Status.INACTIVE)
        return JsonResponse({
            "vehicles": [
                {"id": v.pk, "label": f"{v.display_name} · {v.registration_number}" if v.name else v.registration_number,
                 "hourly_rate": str(v.hourly_rate), "daily_rate": str(v.daily_rate)}
                for v in vehicles
            ]
        })


# ---------------------------------------------------------------------- Drivers
class DriverListView(LoginRequiredMixin, ExportMixin, SearchFilterMixin, ListView):
    template_name = "fleet/driver_list.html"
    context_object_name = "drivers"
    search_fields = ("name", "phone", "license_number")

    def get_queryset(self):
        qs = Driver.objects.select_related("assigned_vehicle")
        status = self.request.GET.get("status")
        if status in Driver.Status.values:
            qs = qs.filter(status=status)
        vehicle = self.request.GET.get("vehicle")
        if vehicle and vehicle.isdigit():
            qs = qs.filter(assigned_vehicle_id=vehicle)
        return self.apply_search(qs)

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["statuses"] = Driver.Status.choices
        ctx["vehicles"] = Vehicle.objects.exclude(status=Vehicle.Status.INACTIVE)
        return ctx

    def build_table(self):
        rows = [
            {"name": d.name, "phone": d.phone, "license": d.license_number, "expiry": d.license_expiry,
             "vehicle": d.assigned_vehicle.display_name if d.assigned_vehicle else "", "salary": d.monthly_salary,
             "bata": d.daily_allowance, "status": d.get_status_display()}
            for d in self.get_queryset()
        ]
        return ReportTable(
            title="Driver Register",
            columns=[("Name", "name", "text"), ("Phone", "phone", "text"), ("Licence no.", "license", "text"),
                     ("Licence expiry", "expiry", "date"), ("Assigned vehicle", "vehicle", "text"),
                     ("Monthly salary", "salary", "money"), ("Daily allowance", "bata", "money"),
                     ("Status", "status", "text")],
            rows=rows,
        )


class DriverDetailView(LoginRequiredMixin, DetailView):
    model = Driver
    template_name = "fleet/driver_detail.html"
    context_object_name = "driver"

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        user = self.request.user
        d = self.object
        ctx["expenses"] = Expense.objects.visible_to(user).filter(driver=d).select_related("vehicle")[:15]
        if user.has_capability("view_reports"):
            business = BusinessSettings.load()
            period = resolve_period(self.request.GET, business.financial_year_start_month)
            approved = Expense.objects.approved().between(period.start, period.end).filter(
                driver=d, category=Expense.Category.DRIVER
            )
            labels = dict(Expense.DriverExpenseType.choices)
            ctx.update(
                period=period, period_choices=PERIOD_CHOICES, total=approved.total(),
                by_type=[{"label": labels.get(r["driver_expense_type"], "Other"), "amount": r["t"]}
                         for r in approved.values("driver_expense_type").annotate(t=Sum("amount")).order_by("-t")],
                by_vehicle=approved.values("vehicle__registration_number", "vehicle__name")
                .annotate(t=Sum("amount")).order_by("-t"),
            )
        return ctx


class DriverCreateView(CapabilityRequiredMixin, AuditedCreateView):
    capability = "manage_masters"
    model = Driver
    form_class = DriverForm
    template_name = "fleet/driver_form.html"

    def get_success_url(self):
        return reverse("fleet:driver_detail", args=[self.object.pk])


class DriverUpdateView(CapabilityRequiredMixin, AuditedUpdateView):
    capability = "manage_masters"
    model = Driver
    form_class = DriverForm
    template_name = "fleet/driver_form.html"

    def get_success_url(self):
        return reverse("fleet:driver_detail", args=[self.object.pk])


class DriverDeleteView(AdminRequiredMixin, AuditedDeleteView):
    model = Driver
    success_url = reverse_lazy("fleet:driver_list")
    protected_message = (
        "This driver has expense records and cannot be deleted. Mark the driver Inactive instead."
    )

