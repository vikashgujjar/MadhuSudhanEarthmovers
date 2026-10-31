from django.urls import path

from . import views

app_name = "reports"

urlpatterns = [
    path("", views.ReportsIndexView.as_view(), name="index"),
    path("cash-book/", views.CashBookView.as_view(), name="cashbook"),
    path("profitability/", views.ProfitabilityView.as_view(), name="profitability"),
    path("pnl/", views.ProfitLossView.as_view(), name="pnl"),
    path("driver-expenses/", views.DriverExpenseReportView.as_view(), name="driver_expenses"),
    path("expense-categories/", views.ExpenseCategoryReportView.as_view(), name="expense_categories"),
]
