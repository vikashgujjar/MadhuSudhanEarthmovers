from decimal import Decimal

from django.core.validators import MinValueValidator
from django.db import models

DEFAULT_BUSINESS_NAME = "Madhusudhan Earthmover"


class TimeStampedModel(models.Model):
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        abstract = True


class BusinessSettings(models.Model):
    """Single-row organisation profile. Every screen, report and export reads
    the organisation name and logo from here, so it is changed in one place."""

    FY_MONTHS = [(m, m) for m in range(1, 13)]

    business_name = models.CharField(max_length=150, default=DEFAULT_BUSINESS_NAME)
    erp_title = models.CharField(max_length=150, default=f"{DEFAULT_BUSINESS_NAME} ERP")
    tagline = models.CharField(
        max_length=200, default="JCB, Tractor, Earthmoving & Equipment Services", blank=True
    )
    logo = models.ImageField(upload_to="branding/", blank=True, null=True)
    owner_name = models.CharField(max_length=120, blank=True)
    address = models.TextField(blank=True)
    phone = models.CharField(max_length=40, blank=True)
    email = models.EmailField(blank=True)
    gstin = models.CharField("GSTIN", max_length=20, blank=True)
    pan = models.CharField("PAN", max_length=12, blank=True)
    currency_symbol = models.CharField(max_length=5, default="₹")
    financial_year_start_month = models.PositiveSmallIntegerField(
        default=4, choices=FY_MONTHS, help_text="4 = April (Indian financial year)."
    )
    invoice_prefix = models.CharField(max_length=12, default="ME-INV")
    receipt_prefix = models.CharField(max_length=12, default="ME-RCT")
    expense_prefix = models.CharField(max_length=12, default="ME-EXP")
    opening_cash_balance = models.DecimalField(
        max_digits=14, decimal_places=2, default=Decimal("0.00"),
        help_text="Cash and bank balance at the start of record keeping; used by the Cash Book.",
    )
    require_approval = models.BooleanField(
        default=True,
        help_text="Transactions entered by Entry Users stay Pending until an Admin approves them. "
        "Only approved transactions are counted in dashboards and reports.",
    )
    report_footer = models.CharField(
        max_length=200, blank=True, default="This is a system-generated report."
    )
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        verbose_name = "business settings"
        verbose_name_plural = "business settings"

    def __str__(self):
        return self.business_name

    def save(self, *args, **kwargs):
        self.pk = 1
        super().save(*args, **kwargs)

    def delete(self, *args, **kwargs):
        raise models.ProtectedError("Business settings cannot be deleted.", {self})

    @classmethod
    def load(cls):
        obj, _ = cls.objects.get_or_create(pk=1)
        return obj

    @property
    def initials(self):
        words = [w for w in self.business_name.split() if w[:1].isalpha()]
        return "".join(w[0] for w in words[:2]).upper() or "ME"

    @property
    def name_primary(self):
        """First word of the name, used for the large line of the wordmark."""
        return self.business_name.split(" ", 1)[0]

    @property
    def name_secondary(self):
        parts = self.business_name.split(" ", 1)
        return parts[1] if len(parts) > 1 else ""

    @property
    def contact_line(self):
        return " | ".join(p for p in [self.phone, self.email, f"GSTIN: {self.gstin}" if self.gstin else ""] if p)


MONEY = dict(max_digits=14, decimal_places=2, validators=[MinValueValidator(Decimal("0.00"))])
