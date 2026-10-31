from django.contrib import messages
from django.contrib.auth.mixins import LoginRequiredMixin
from django.shortcuts import redirect, render
from django.views import View
from django.views.generic import TemplateView

from apps.audit import services as audit
from apps.audit.models import AuditLog
from apps.finance import services as fin
from apps.finance.models import Expense, Income, Payment, Status

from .forms import BusinessSettingsForm
from .models import BusinessSettings
from .periods import PERIOD_CHOICES, resolve_period
from .permissions import AdminRequiredMixin


class DashboardView(LoginRequiredMixin, TemplateView):
    def get_template_names(self):
        if self.request.user.has_capability("view_reports"):
            return ["core/dashboard.html"]
        return ["core/dashboard_entry.html"]

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        user = self.request.user
        business = BusinessSettings.load()
        period = resolve_period(self.request.GET, business.financial_year_start_month)
        ctx.update(period=period, period_choices=PERIOD_CHOICES)
        ctx["recent"] = fin.recent_transactions(user, limit=8)

        if user.has_capability("view_reports"):
            summary = fin.financial_summary(period.start, period.end)
            profit_rows = fin.vehicle_profitability(period.start, period.end)
            ctx.update(
                summary=summary,
                profit_rows=sorted(profit_rows, key=lambda r: r["profit"], reverse=True)[:8],
                trend=fin.monthly_trend(period.start, period.end),
                expense_chart={
                    "labels": [r["label"] for r in summary["expense_by_category"]],
                    "values": [float(r["amount"]) for r in summary["expense_by_category"]],
                },
                category_chart={
                    "labels": [c["label"] for c in summary["categories"].values()],
                    "income": [float(c["income"]) for c in summary["categories"].values()],
                    "expense": [float(c["expense"]) for c in summary["categories"].values()],
                },
                driver_rows=fin.driver_expense_summary(period.start, period.end)[:5],
                pending={
                    "income": Income.objects.pending().count(),
                    "expense": Expense.objects.pending().count(),
                    "payment": Payment.objects.pending().count(),
                },
            )
        else:
            mine = {"created_by": user}
            ctx["my_counts"] = {
                status: Income.objects.filter(status=status, **mine).count()
                + Expense.objects.filter(status=status, **mine).count()
                + Payment.objects.filter(status=status, **mine).count()
                for status in Status.values
            }
            period_qs = {"date__gte": period.start} if period.start else {}
            ctx["my_totals"] = {
                "income": Income.objects.filter(**mine, **period_qs).exclude(status=Status.REJECTED).total(),
                "expense": Expense.objects.filter(**mine, **period_qs).exclude(status=Status.REJECTED).total(),
                "payment": Payment.objects.filter(**mine, **period_qs).exclude(status=Status.REJECTED).total(),
            }
            ctx["rejected"] = (
                list(Income.objects.filter(status=Status.REJECTED, **mine)[:5])
                + list(Expense.objects.filter(status=Status.REJECTED, **mine)[:5])
                + list(Payment.objects.filter(status=Status.REJECTED, **mine)[:5])
            )
        return ctx


class BusinessSettingsView(AdminRequiredMixin, View):
    template_name = "core/settings.html"

    def get(self, request):
        return render(request, self.template_name, {"form": BusinessSettingsForm(instance=BusinessSettings.load())})

    def post(self, request):
        instance = BusinessSettings.load()
        before = audit.snapshot(instance)
        form = BusinessSettingsForm(request.POST, request.FILES, instance=instance)
        if form.is_valid():
            obj = form.save()
            changes = audit.diff(before, audit.snapshot(obj))
            if changes:
                audit.log(AuditLog.Action.SETTINGS, obj, changes={"fields": changes})
            messages.success(request, "Business settings saved. The new details now appear on every page and report.")
            return redirect("core:settings")
        messages.error(request, "Settings were not saved. Please correct the errors below.")
        return render(request, self.template_name, {"form": form})


def protected_media(request, path):
    """Serve uploaded files with access checks. Branding (logo) is public;
    expense bills are visible only to users who can see that expense."""
    from django.conf import settings
    from django.contrib.auth.views import redirect_to_login
    from django.core.exceptions import PermissionDenied
    from django.http import Http404
    from django.views.static import serve

    if path.startswith("branding/"):
        return serve(request, path, document_root=settings.MEDIA_ROOT)
    if not request.user.is_authenticated:
        return redirect_to_login(request.get_full_path())
    if path.startswith("expense_bills/"):
        expense = Expense.objects.filter(attachment=path).first()
        if expense is None:
            raise Http404
        if not Expense.objects.visible_to(request.user).filter(pk=expense.pk).exists():
            raise PermissionDenied("You cannot view this attachment.")
        return serve(request, path, document_root=settings.MEDIA_ROOT)
    raise Http404


def error_403(request, exception=None):
    return render(request, "errors/403.html", {"message": str(exception) if exception else ""}, status=403)


def error_404(request, exception=None):
    return render(request, "errors/404.html", status=404)


def error_500(request):
    try:
        return render(request, "errors/500.html", status=500)
    except Exception:  # the database itself may be the problem
        from django.http import HttpResponseServerError

        return HttpResponseServerError("<h1>Server error</h1><p>Please try again shortly.</p>")
