from django import forms
from django.db.models import Value
from django.db.models.functions import Replace, Upper

from apps.core.forms import DateInput, StyledFormMixin

from .models import Driver, Vehicle


class VehicleForm(StyledFormMixin, forms.ModelForm):
    class Meta:
        model = Vehicle
        fields = [
            "category", "registration_number", "name", "make", "model", "year", "chassis_number", "engine_number",
            "purchase_date", "purchase_cost", "hourly_rate", "daily_rate", "insurance_expiry", "status", "notes",
        ]
        widgets = {"purchase_date": DateInput(), "insurance_expiry": DateInput()}

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["registration_number"].widget.attrs["placeholder"] = "e.g. KA 01 AB 1234"
        self.fields["name"].widget.attrs["placeholder"] = "e.g. JCB-1"
        self.fields["make"].widget.attrs["placeholder"] = "e.g. JCB, Mahindra, Swaraj"

    def clean_registration_number(self):
        value = " ".join(self.cleaned_data["registration_number"].upper().split())
        if not value:
            raise forms.ValidationError("Registration number is required.")
        # "KA 01 AB 1234", "KA01AB1234" and "ka-01-ab-1234" are the same plate.
        compact = "".join(ch for ch in value if ch.isalnum())
        plates = Vehicle.objects.exclude(pk=self.instance.pk).annotate(
            compact=Replace(Replace(Upper("registration_number"), Value(" "), Value("")), Value("-"), Value(""))
        )
        if plates.filter(compact=compact).exists():
            raise forms.ValidationError("A vehicle with this registration number already exists.")
        return value

    def clean_category(self):
        category = self.cleaned_data["category"]
        if self.instance.pk and category != self.instance.category and (
            self.instance.incomes.exists() or self.instance.expenses.exists()
        ):
            raise forms.ValidationError(
                "Category cannot be changed because transactions are already recorded against this vehicle."
            )
        return category


class DriverForm(StyledFormMixin, forms.ModelForm):
    class Meta:
        model = Driver
        fields = [
            "name", "phone", "alternate_phone", "license_number", "license_expiry", "assigned_vehicle",
            "monthly_salary", "daily_allowance", "joining_date", "status", "address", "notes",
        ]
        widgets = {"license_expiry": DateInput(), "joining_date": DateInput()}

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["assigned_vehicle"].queryset = Vehicle.objects.exclude(status=Vehicle.Status.INACTIVE)
        self.fields["assigned_vehicle"].empty_label = "— Not assigned —"

    def clean_phone(self):
        phone = self.cleaned_data.get("phone", "").strip()
        digits = "".join(ch for ch in phone if ch.isdigit())
        if phone and len(digits) < 10:
            raise forms.ValidationError("Enter a valid phone number (at least 10 digits).")
        return phone
