from django.utils.dateparse import parse_date
from django.views.generic import DetailView, ListView

from apps.accounts.models import User
from apps.core.permissions import AdminRequiredMixin
from apps.core.views_mixins import SearchFilterMixin
from apps.reports.exporters import ExportMixin, ReportTable

from .models import AuditLog


class AuditLogListView(AdminRequiredMixin, ExportMixin, SearchFilterMixin, ListView):
    model = AuditLog
    template_name = "audit/log_list.html"
    context_object_name = "logs"
    search_fields = ("object_repr", "username", "model_name", "object_id")
    export_name = "audit-log"

    def get_queryset(self):
        qs = self.apply_search(AuditLog.objects.select_related("user"))
        g = self.request.GET
        if g.get("action") in AuditLog.Action.values:
            qs = qs.filter(action=g["action"])
        if g.get("model"):
            qs = qs.filter(model_name=g["model"])
        if g.get("user"):
            qs = qs.filter(user_id=g["user"])
        if g.get("from") and parse_date(g["from"]):
            qs = qs.filter(timestamp__date__gte=parse_date(g["from"]))
        if g.get("to") and parse_date(g["to"]):
            qs = qs.filter(timestamp__date__lte=parse_date(g["to"]))
        return qs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["actions"] = AuditLog.Action.choices
        ctx["models"] = AuditLog.objects.order_by().values_list("model_name", flat=True).distinct()
        ctx["users"] = User.objects.order_by("username")
        return ctx

    def build_table(self):
        rows = [
            {
                "timestamp": log.timestamp, "user": log.username, "action": log.get_action_display(),
                "record": f"{log.model_name} {log.object_repr}".strip(), "ip": log.ip_address or "",
                "changes": ", ".join(log.changes.get("fields", {}).keys()) if log.changes else "",
            }
            for log in self.get_queryset()[:5000]
        ]
        return ReportTable(
            title="Audit Log",
            columns=[("Time", "timestamp", "datetime"), ("User", "user", "text"), ("Action", "action", "text"),
                     ("Record", "record", "text"), ("Changed fields", "changes", "text"), ("IP", "ip", "text")],
            rows=rows,
        )


class AuditLogDetailView(AdminRequiredMixin, DetailView):
    model = AuditLog
    template_name = "audit/log_detail.html"
    context_object_name = "log"
