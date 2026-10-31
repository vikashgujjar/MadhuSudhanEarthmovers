from .models import BusinessSettings


def business(request):
    """Expose the organisation profile to every template as `business`."""
    if not hasattr(request, "_business_settings"):
        request._business_settings = BusinessSettings.load()
    context = {"business": request._business_settings}
    user = getattr(request, "user", None)
    if user is not None and user.is_authenticated and user.is_admin:
        from apps.finance.models import Expense, Income, Payment

        context["pending_approvals_count"] = (
            Income.objects.pending().count() + Expense.objects.pending().count() + Payment.objects.pending().count()
        )
    return context
