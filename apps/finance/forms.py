from decimal import Decimal

from django import forms
from django.urls import reverse_lazy

from apps.core.forms import DateInput, StyledFormMixin
from apps.customers.models import Customer
from apps.fleet.models import Driver, Vehicle, VehicleCategory

from .models import Expense, Income, Payment, PaymentMode, Status, money

MAX_ATTACHMENT_MB = 5


def _vehicle_queryset(category, current=None):
    qs = Vehicle.objects.filter(category=category).exclude(status=Vehicle.Status.INACTIVE)
    if current is not None:
        qs = qs | Vehicle.objects.filter(pk=current.pk)
    return qs.distinct()


class CategoryFirstMixin:
    """Vehicle choices are limited to the selected vehicle category, on the
    server as well as in the browser."""

    allow_empty_category = False

    def setup_vehicle_fields(self):
        data = self.data if self.is_bound else None
        category = (
            (data.get(self.add_prefix("vehicle_category")) if data is not None else None)
            or self.initial.get("vehicle_category")
            or getattr(self.instance, "vehicle_category", "")
        )
        current = self.instance.vehicle if getattr(self.instance, "vehicle_id", None) else None
        vehicle = self.fields["vehicle"]
        if category in VehicleCategory.values:
            vehicle.queryset = _vehicle_queryset(category, current if current and current.category == category else None)
        else:
            vehicle.queryset = Vehicle.objects.none()
        vehicle.label_from_instance = lambda v: (
            f"{v.name} · {v.registration_number}" if v.name else v.registration_number
        )
        cat = self.fields["vehicle_category"]
        cat.widget.attrs.update({
            "data-vehicle-category": "",
            "data-vehicle-target": "#id_vehicle",
            "data-vehicle-url": reverse_lazy("fleet:api_vehicles"),
        })
        if self.allow_empty_category:
            vehicle.widget.attrs["data-allow-empty-category"] = ""
            vehicle.empty_label = "— No vehicle (general) —" if not category else "Select vehicle"
        else:
            vehicle.empty_label = "Select vehicle" if category else "Select a vehicle category first"


class IncomeForm(CategoryFirstMixin, StyledFormMixin, forms.ModelForm):
    received_now = forms.DecimalField(
        label="Amount received now", required=False, min_value=Decimal("0"), max_digits=14, decimal_places=2,
        help_text="Leave empty if nothing was paid yet. Partial amounts are allowed.",
    )
    payment_mode = forms.ChoiceField(label="Received via", choices=PaymentMode.choices, initial=PaymentMode.CASH)
    payment_reference = forms.CharField(label="Payment reference", required=False, max_length=80)

    class Meta:
        model = Income
        fields = [
            "date", "vehicle_category", "vehicle", "customer", "driver", "billing_type", "quantity", "rate", "amount",
            "work_location", "description",
        ]
        widgets = {"date": DateInput(), "description": forms.Textarea(attrs={"rows": 2})}
        labels = {"vehicle_category": "Vehicle category", "quantity": "Hours / days worked", "amount": "Billed amount"}

    def __init__(self, *args, with_payment=True, **kwargs):
        super().__init__(*args, **kwargs)
        self.with_payment = with_payment
        if not with_payment:
            for name in ("received_now", "payment_mode", "payment_reference"):
                del self.fields[name]
        self.fields["vehicle_category"].choices = [("", "Select category")] + list(VehicleCategory.choices)
        self.setup_vehicle_fields()
        customers = Customer.objects.filter(is_active=True)
        if self.instance.customer_id:
            customers = customers | Customer.objects.filter(pk=self.instance.customer_id)
        self.fields["customer"].queryset = customers.distinct().order_by("name")
        self.fields["customer"].empty_label = "Select customer"
        drivers = Driver.objects.filter(status=Driver.Status.ACTIVE)
        if self.instance.driver_id:
            drivers = drivers | Driver.objects.filter(pk=self.instance.driver_id)
        self.fields["driver"].queryset = drivers.distinct()
        self.fields["driver"].empty_label = "— Not specified —"
        self.fields["quantity"].help_text = ""
        self.fields["amount"].required = False
        self.fields["amount"].help_text = "Calculated automatically for hourly and daily billing."
        self.fields["quantity"].widget.attrs.update({"step": "0.25", "min": "0"})
        self.fields["rate"].label = "Rate"
        self.fields["rate"].help_text = "Per hour or per day; pre-filled from the vehicle."
        self.fields["work_location"].widget.attrs["placeholder"] = "Site / village / project"

    def clean(self):
        cleaned = super().clean()
        billing = cleaned.get("billing_type")
        if billing in Income.QUANTITY_BILLING:
            qty, rate = cleaned.get("quantity"), cleaned.get("rate")
            amount = money(qty * rate) if qty and rate else None
        else:
            amount = cleaned.get("amount")
        received = cleaned.get("received_now")
        if self.with_payment and received and amount is not None and received > amount:
            self.add_error("received_now", f"Cannot exceed the billed amount ({amount}).")
        return cleaned


class ExpenseForm(CategoryFirstMixin, StyledFormMixin, forms.ModelForm):
    allow_empty_category = True

    class Meta:
        model = Expense
        fields = [
            "date", "category", "vehicle_category", "vehicle", "driver", "driver_expense_type", "litres", "amount",
            "payment_mode", "vendor", "bill_number", "description", "attachment",
        ]
        widgets = {"date": DateInput(), "description": forms.Textarea(attrs={"rows": 2})}
        labels = {"vehicle_category": "Vehicle category", "litres": "Diesel quantity (litres)",
                  "attachment": "Bill / receipt (optional)"}

    def __init__(self, *args, driver_mode=False, **kwargs):
        super().__init__(*args, **kwargs)
        self.driver_mode = driver_mode
        self.fields["vehicle_category"].choices = [("", "General (no vehicle)")] + list(VehicleCategory.choices)
        self.setup_vehicle_fields()
        drivers = Driver.objects.filter(status=Driver.Status.ACTIVE)
        if self.instance.driver_id:
            drivers = drivers | Driver.objects.filter(pk=self.instance.driver_id)
        self.fields["driver"].queryset = drivers.distinct()
        if driver_mode:
            self.fields["category"].initial = Expense.Category.DRIVER
            self.fields["category"].widget = forms.HiddenInput()
            self.fields["driver"].required = True
            self.fields["driver"].empty_label = "Select driver"
            self.fields["driver_expense_type"].required = True
            self.fields["vehicle_category"].help_text = "Optional: the machine the driver was working on."
            del self.fields["litres"]
        else:
            self.fields["category"].choices = [("", "Select expense type")] + [
                c for c in Expense.Category.choices if c[0] != Expense.Category.DRIVER
            ]
            self.fields["driver"].empty_label = "— Not specified —"
            self.fields["driver"].help_text = "Optional: who incurred it (e.g. driver who filled diesel)."
            del self.fields["driver_expense_type"]
        self.fields["vendor"].widget.attrs["placeholder"] = "Fuel station, workshop, shop…"

    def clean_category(self):
        category = self.cleaned_data.get("category")
        if self.driver_mode:
            return Expense.Category.DRIVER
        if category == Expense.Category.DRIVER:
            raise forms.ValidationError("Use 'Driver Expenses' to record driver expenses.")
        return category

    def clean_attachment(self):
        file = self.cleaned_data.get("attachment")
        if file and hasattr(file, "size") and file.size > MAX_ATTACHMENT_MB * 1024 * 1024:
            raise forms.ValidationError(f"File must be smaller than {MAX_ATTACHMENT_MB} MB.")
        return file

    def clean(self):
        cleaned = super().clean()
        category = cleaned.get("category")
        if category in Expense.VEHICLE_REQUIRED and not cleaned.get("vehicle_category"):
            self.add_error("vehicle_category", "Select the vehicle category first (JCB or Tractor).")
        if not cleaned.get("vehicle_category"):
            cleaned["vehicle"] = None
        return cleaned


class PaymentForm(StyledFormMixin, forms.ModelForm):
    class Meta:
        model = Payment
        fields = ["date", "customer", "income", "amount", "mode", "reference", "notes"]
        widgets = {"date": DateInput(), "notes": forms.Textarea(attrs={"rows": 2})}
        labels = {"income": "Against invoice", "amount": "Amount received", "mode": "Received via"}

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        data = self.data if self.is_bound else None
        customer_id = (
            (data.get(self.add_prefix("customer")) if data is not None else None)
            or self.initial.get("customer")
            or self.instance.customer_id
        )
        customers = Customer.objects.filter(is_active=True)
        if self.instance.customer_id:
            customers = customers | Customer.objects.filter(pk=self.instance.customer_id)
        self.fields["customer"].queryset = customers.distinct().order_by("name")
        self.fields["customer"].empty_label = "Select customer"
        income_qs = Income.objects.none()
        if customer_id and str(customer_id).isdigit():
            income_qs = Income.objects.filter(customer_id=customer_id).exclude(status=Status.REJECTED)
        self.fields["income"].queryset = income_qs.order_by("-date")
        self.fields["income"].label_from_instance = (
            lambda i: f"{i.number} · {i.date:%d %b %Y} · billed {i.amount}"
        )
        self.fields["income"].empty_label = "— Advance / on-account (no invoice) —"
        self.fields["income"].widget.attrs["data-invoices-url"] = reverse_lazy("finance:api_open_invoices")
        if self.instance.pk:
            self.fields["income"].widget.attrs["data-include"] = self.instance.income_id or ""
        self.fields["reference"].widget.attrs["placeholder"] = "UTR / cheque no."


class RejectForm(forms.Form):
    reason = forms.CharField(max_length=255, widget=forms.TextInput(attrs={"class": "form-input"}))
