from django.urls import path

from . import views

app_name = "finance"

urlpatterns = [
    path("income/", views.IncomeListView.as_view(), name="income_list"),
    path("income/new/", views.IncomeCreateView.as_view(), name="income_create"),
    path("income/<int:pk>/", views.IncomeDetailView.as_view(), name="income_detail"),
    path("income/<int:pk>/edit/", views.IncomeUpdateView.as_view(), name="income_update"),
    path("income/<int:pk>/delete/", views.IncomeDeleteView.as_view(), name="income_delete"),

    path("expenses/", views.ExpenseListView.as_view(), name="expense_list"),
    path("expenses/new/", views.ExpenseCreateView.as_view(), name="expense_create"),
    path("expenses/<int:pk>/", views.ExpenseDetailView.as_view(), name="expense_detail"),
    path("expenses/<int:pk>/edit/", views.ExpenseUpdateView.as_view(), name="expense_update"),
    path("expenses/<int:pk>/delete/", views.ExpenseDeleteView.as_view(), name="expense_delete"),

    path("driver-expenses/", views.DriverExpenseListView.as_view(), name="driver_expense_list"),
    path("driver-expenses/new/", views.DriverExpenseCreateView.as_view(), name="driver_expense_create"),

    path("payments/", views.PaymentListView.as_view(), name="payment_list"),
    path("payments/new/", views.PaymentCreateView.as_view(), name="payment_create"),
    path("payments/<int:pk>/", views.PaymentDetailView.as_view(), name="payment_detail"),
    path("payments/<int:pk>/edit/", views.PaymentUpdateView.as_view(), name="payment_update"),
    path("payments/<int:pk>/delete/", views.PaymentDeleteView.as_view(), name="payment_delete"),

    path("approvals/", views.ApprovalQueueView.as_view(), name="approvals"),
    path("approvals/bulk/", views.BulkApproveView.as_view(), name="bulk_approve"),
    path("approvals/<str:kind>/<int:pk>/approve/", views.ApproveView.as_view(), name="approve"),
    path("approvals/<str:kind>/<int:pk>/reject/", views.RejectView.as_view(), name="reject"),

    path("api/open-invoices/", views.OpenInvoicesView.as_view(), name="api_open_invoices"),
]
