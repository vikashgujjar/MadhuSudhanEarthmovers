from django import forms

from .models import BusinessSettings


class StyledFormMixin:
    """Applies the ERP's Tailwind classes and marks invalid fields."""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        for name, field in self.fields.items():
            widget = field.widget
            if isinstance(widget, (forms.CheckboxInput, forms.CheckboxSelectMultiple)):
                widget.attrs.setdefault("class", "form-check")
                continue
            classes = widget.attrs.get("class", "")
            if "form-input" not in classes:
                classes = f"form-input {classes}".strip()
            widget.attrs["class"] = classes
            if isinstance(widget, forms.Textarea):
                widget.attrs.setdefault("rows", 3)
            if isinstance(widget, forms.NumberInput):
                widget.attrs.setdefault("inputmode", "decimal")

    def full_clean(self):
        super().full_clean()
        # Re-apply invalid styling after validation (errors are known only now).
        for name in self.errors:
            if name in self.fields:
                widget = self.fields[name].widget
                if "is-invalid" not in widget.attrs.get("class", ""):
                    widget.attrs["class"] = widget.attrs.get("class", "") + " is-invalid"
                    widget.attrs["aria-invalid"] = "true"


class DateInput(forms.DateInput):
    input_type = "date"

    def __init__(self, attrs=None):
        super().__init__(attrs=attrs, format="%Y-%m-%d")


class BusinessSettingsForm(StyledFormMixin, forms.ModelForm):
    class Meta:
        model = BusinessSettings
        fields = [
            "business_name", "erp_title", "tagline", "logo", "owner_name", "address", "phone", "email",
            "gstin", "pan", "currency_symbol", "financial_year_start_month", "invoice_prefix",
            "receipt_prefix", "expense_prefix", "opening_cash_balance", "require_approval", "report_footer",
        ]
        widgets = {"address": forms.Textarea(attrs={"rows": 3})}

    def clean_logo(self):
        logo = self.cleaned_data.get("logo")
        if logo and hasattr(logo, "size") and logo.size > 2 * 1024 * 1024:
            raise forms.ValidationError("Logo must be smaller than 2 MB.")
        return logo

    def clean(self):
        cleaned = super().clean()
        for key in ("invoice_prefix", "receipt_prefix", "expense_prefix"):
            value = (cleaned.get(key) or "").strip().upper()
            if value and not all(ch.isalnum() or ch in "-/" for ch in value):
                self.add_error(key, "Use letters, numbers, '-' or '/' only.")
            cleaned[key] = value
        return cleaned
