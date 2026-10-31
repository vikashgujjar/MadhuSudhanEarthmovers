"""
Financial records.

Calculation rules (applied consistently everywhere):

* Only APPROVED records count toward dashboards, balances and reports.
* Billed income = Income.amount. Money received = Payment.amount. They are
  never mixed: profit uses billed income, the Cash Book uses money received.
* Every rupee spent is exactly one Expense row. A driver expense is an
  Expense with category DRIVER that additionally references a driver (and,
  optionally, a vehicle). There is no separate driver-expense table, so a
  driver expense on a JCB is counted once in total expenses, once in that
  JCB's expenses, and once in that driver's expenses -- never twice in a
  single total.
* Customer outstanding = opening balance + approved income - approved payments.
"""
from decimal import ROUND_HALF_UP, Decimal

from django.conf import settings
from django.core.exceptions import ValidationError
from django.core.validators import FileExtensionValidator, MinValueValidator
from django.db import models
from django.urls import reverse
from django.db.models import Sum
from django.utils import timezone

from apps.core.models import MONEY, BusinessSettings
from apps.customers.models import Customer
from apps.fleet.models import Driver, Vehicle, VehicleCategory

ZERO = Decimal("0.00")
CENT = Decimal("0.01")


def money(value):
    return (value or ZERO).quantize(CENT, rounding=ROUND_HALF_UP)


class Status(models.TextChoices):
    PENDING = "PENDING", "Pending approval"
    APPROVED = "APPROVED", "Approved"
    REJECTED = "REJECTED", "Rejected"


class PaymentMode(models.TextChoices):
    CASH = "CASH", "Cash"
    BANK = "BANK", "Bank transfer"
    UPI = "UPI", "UPI"
    CHEQUE = "CHEQUE", "Cheque"


class TransactionQuerySet(models.QuerySet):
    def approved(self):
        return self.filter(status=Status.APPROVED)

    def pending(self):
        return self.filter(status=Status.PENDING)

    def visible_to(self, user):
        if user.sees_all_records:
            return self
        return self.filter(created_by=user)

    def between(self, start=None, end=None):
        qs = self
        if start:
            qs = qs.filter(date__gte=start)
        if end:
            qs = qs.filter(date__lte=end)
        return qs

    def total(self):
        return money(self.aggregate(total=Sum("amount"))["total"])


class Transaction(models.Model):
    """Shared fields for approvable financial records."""

    number = models.CharField(max_length=30, blank=True, db_index=True)
    date = models.DateField(default=timezone.localdate, db_index=True)
    amount = models.DecimalField(**MONEY)
    status = models.CharField(max_length=10, choices=Status.choices, default=Status.PENDING, db_index=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, on_delete=models.SET_NULL, related_name="+"
    )
    approved_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+"
    )
    approved_at = models.DateTimeField(null=True, blank=True)
    rejection_reason = models.CharField(max_length=255, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    objects = TransactionQuerySet.as_manager()
    number_prefix_field = None

    class Meta:
        abstract = True
        ordering = ["-date", "-id"]

    @property
    def is_pending(self):
        return self.status == Status.PENDING

    @property
    def is_approved(self):
        return self.status == Status.APPROVED

    def can_edit(self, user):
        if user.is_admin:
            return True
        return self.created_by_id == user.pk and self.status != Status.APPROVED and not user.is_viewer

    def save(self, *args, **kwargs):
        self.amount = money(self.amount)
        super().save(*args, **kwargs)
        if not self.number and self.number_prefix_field:
            prefix = getattr(BusinessSettings.load(), self.number_prefix_field)
            self.number = f"{prefix}-{self.pk:05d}"
            type(self).objects.filter(pk=self.pk).update(number=self.number)


class Income(Transaction):
    class BillingType(models.TextChoices):
        HOURLY = "HOURLY", "Hourly billing"
        DAILY = "DAILY", "Daily billing"
        FIXED = "FIXED", "Fixed contract"
        MANUAL = "MANUAL", "Manual amount"

    QUANTITY_BILLING = {BillingType.HOURLY, BillingType.DAILY}

    vehicle_category = models.CharField(max_length=10, choices=VehicleCategory.choices)
    vehicle = models.ForeignKey(Vehicle, on_delete=models.PROTECT, related_name="incomes")
    customer = models.ForeignKey(Customer, on_delete=models.PROTECT, related_name="incomes")
    driver = models.ForeignKey(Driver, null=True, blank=True, on_delete=models.SET_NULL, related_name="incomes")
    billing_type = models.CharField(max_length=10, choices=BillingType.choices, default=BillingType.HOURLY)
    quantity = models.DecimalField(
        max_digits=10, decimal_places=2, null=True, blank=True,
        validators=[MinValueValidator(Decimal("0.01"))], help_text="Hours or days worked.",
    )
    rate = models.DecimalField(null=True, blank=True, **MONEY)
    work_location = models.CharField(max_length=200, blank=True)
    description = models.TextField(blank=True)

    number_prefix_field = "invoice_prefix"

    class Meta(Transaction.Meta):
        verbose_name = "income entry"
        verbose_name_plural = "income entries"

    def get_absolute_url(self):
        return reverse("finance:income_detail", args=[self.pk])

    def __str__(self):
        return f"{self.number or 'Income'} · {self.customer} · {self.amount}"

    @property
    def unit_label(self):
        return {"HOURLY": "hrs", "DAILY": "days"}.get(self.billing_type, "")

    def compute_amount(self):
        if self.billing_type in self.QUANTITY_BILLING and self.quantity and self.rate is not None:
            self.amount = money(self.quantity * self.rate)
        return self.amount

    def clean_fields(self, exclude=None):
        # Hourly/daily amounts are derived, so compute before "amount" is validated.
        self.compute_amount()
        super().clean_fields(exclude=exclude)

    def clean(self):
        errors = {}
        if self.vehicle_id and self.vehicle_category and self.vehicle.category != self.vehicle_category:
            errors["vehicle"] = "This vehicle does not belong to the selected category."
        if self.billing_type in self.QUANTITY_BILLING:
            unit = "hours" if self.billing_type == self.BillingType.HOURLY else "days"
            if not self.quantity:
                errors["quantity"] = f"Enter the number of {unit} worked."
            if self.rate is None or self.rate <= 0:
                errors["rate"] = f"Enter the rate per {unit[:-1]}."
        else:
            self.quantity = None
            self.rate = None
            if self.amount is None or self.amount <= 0:
                errors["amount"] = "Enter an amount greater than zero."
        if not errors:
            self.compute_amount()
            if self.amount <= 0:
                errors["amount"] = "The billed amount must be greater than zero."
            elif self.pk:
                received = self.payments.exclude(status=Status.REJECTED).total()
                if self.amount < received:
                    errors["amount"] = (
                        f"Amount cannot be less than the {received} already recorded as received against it."
                    )
        if errors:
            raise ValidationError(errors)

    def save(self, *args, **kwargs):
        self.compute_amount()
        super().save(*args, **kwargs)

    # Collection figures count approved payments only.
    @property
    def amount_received(self):
        return self.payments.approved().total()

    @property
    def outstanding(self):
        return money(self.amount - self.amount_received)

    @property
    def payment_state(self):
        received = self.amount_received
        if received <= 0:
            return "Unpaid"
        if received >= self.amount:
            return "Paid"
        return "Partially paid"


class Expense(Transaction):
    class Category(models.TextChoices):
        DIESEL = "DIESEL", "Diesel"
        MAINTENANCE = "MAINTENANCE", "Maintenance"
        REPAIR = "REPAIR", "Repairs"
        SPARE_PARTS = "SPARE_PARTS", "Spare parts"
        DRIVER = "DRIVER", "Driver expense"
        OTHER = "OTHER", "Other business expense"

    class DriverExpenseType(models.TextChoices):
        SALARY = "SALARY", "Salary"
        ADVANCE = "ADVANCE", "Advance"
        BATA = "BATA", "Daily allowance (bata)"
        FOOD = "FOOD", "Food"
        TRAVEL = "TRAVEL", "Travel"
        MEDICAL = "MEDICAL", "Medical"
        OTHER = "OTHER", "Other"

    VEHICLE_REQUIRED = {Category.DIESEL, Category.MAINTENANCE, Category.REPAIR, Category.SPARE_PARTS}

    category = models.CharField(max_length=15, choices=Category.choices, db_index=True)
    vehicle_category = models.CharField(max_length=10, choices=VehicleCategory.choices, blank=True)
    vehicle = models.ForeignKey(
        Vehicle, null=True, blank=True, on_delete=models.PROTECT, related_name="expenses"
    )
    driver = models.ForeignKey(
        Driver, null=True, blank=True, on_delete=models.PROTECT, related_name="expenses"
    )
    driver_expense_type = models.CharField(max_length=10, choices=DriverExpenseType.choices, blank=True)
    litres = models.DecimalField(
        max_digits=9, decimal_places=2, null=True, blank=True, validators=[MinValueValidator(Decimal("0.01"))]
    )
    vendor = models.CharField(max_length=150, blank=True)
    bill_number = models.CharField(max_length=60, blank=True)
    payment_mode = models.CharField(max_length=10, choices=PaymentMode.choices, default=PaymentMode.CASH)
    description = models.TextField(blank=True)
    attachment = models.FileField(
        upload_to="expense_bills/%Y/%m/", blank=True,
        validators=[FileExtensionValidator(["pdf", "jpg", "jpeg", "png", "webp"])],
    )

    number_prefix_field = "expense_prefix"

    class Meta(Transaction.Meta):
        verbose_name = "expense"

    def get_absolute_url(self):
        return reverse("finance:expense_detail", args=[self.pk])

    def __str__(self):
        return f"{self.number or 'Expense'} · {self.get_category_display()} · {self.amount}"

    @property
    def is_driver_expense(self):
        return self.category == self.Category.DRIVER

    def clean(self):
        errors = {}
        if self.vehicle_id:
            if not self.vehicle_category:
                self.vehicle_category = self.vehicle.category
            elif self.vehicle.category != self.vehicle_category:
                errors["vehicle"] = "This vehicle does not belong to the selected category."
        if self.category in self.VEHICLE_REQUIRED and not self.vehicle_id:
            errors["vehicle"] = f"{self.get_category_display()} expenses must be linked to a vehicle."
        if self.category == self.Category.DRIVER:
            if not self.driver_id:
                errors["driver"] = "Select the driver this expense belongs to."
            if not self.driver_expense_type:
                errors["driver_expense_type"] = "Select the type of driver expense."
        else:
            self.driver_expense_type = ""
        if self.category != self.Category.DIESEL:
            self.litres = None
        if self.amount is None or self.amount <= 0:
            errors["amount"] = "Enter an amount greater than zero."
        if errors:
            raise ValidationError(errors)


class Payment(Transaction):
    customer = models.ForeignKey(Customer, on_delete=models.PROTECT, related_name="payments")
    income = models.ForeignKey(
        Income, null=True, blank=True, on_delete=models.PROTECT, related_name="payments",
        help_text="Invoice this payment settles. Leave empty for an advance / on-account payment.",
    )
    mode = models.CharField(max_length=10, choices=PaymentMode.choices, default=PaymentMode.CASH)
    reference = models.CharField(max_length=80, blank=True, help_text="UTR, cheque or transaction number.")
    notes = models.TextField(blank=True)
    is_initial = models.BooleanField(
        default=False, help_text="Recorded together with the income entry (amount received on the spot)."
    )

    number_prefix_field = "receipt_prefix"

    class Meta(Transaction.Meta):
        verbose_name = "payment"

    def get_absolute_url(self):
        return reverse("finance:payment_detail", args=[self.pk])

    def __str__(self):
        return f"{self.number or 'Payment'} · {self.customer} · {self.amount}"

    def clean(self):
        errors = {}
        if self.amount is None or self.amount <= 0:
            errors["amount"] = "Enter an amount greater than zero."
        if self.income_id:
            if self.income.status == Status.REJECTED:
                errors["income"] = "Payments cannot be recorded against a rejected income entry."
            elif self.customer_id and self.income.customer_id != self.customer_id:
                errors["income"] = "This invoice belongs to a different customer."
            elif self.amount and self.amount > 0:
                others = self.income.payments.exclude(status=Status.REJECTED)
                if self.pk:
                    others = others.exclude(pk=self.pk)
                remaining = money(self.income.amount - others.total())
                if self.amount > remaining:
                    errors["amount"] = (
                        f"Amount exceeds the balance due on {self.income.number} ({remaining})."
                    )
        if errors:
            raise ValidationError(errors)


def customer_balance_rows(customers=None, end=None):
    """Return customers annotated with billed, received and outstanding totals.

    Uses subqueries so that income and payment sums never multiply each other
    (a common double-counting bug with joined aggregates)."""
    from django.db.models import DecimalField, OuterRef, Subquery, Value
    from django.db.models.functions import Coalesce

    customers = customers if customers is not None else Customer.objects.all()
    money_field = DecimalField(max_digits=14, decimal_places=2)
    income_qs = Income.objects.approved().filter(customer=OuterRef("pk"))
    pay_qs = Payment.objects.approved().filter(customer=OuterRef("pk"))
    if end:
        income_qs = income_qs.filter(date__lte=end)
        pay_qs = pay_qs.filter(date__lte=end)

    def summed(qs):
        return Coalesce(
            Subquery(qs.values("customer").annotate(s=Sum("amount")).values("s")[:1], output_field=money_field),
            Value(ZERO),
            output_field=money_field,
        )

    return customers.annotate(billed=summed(income_qs), received=summed(pay_qs))


def outstanding_for(customer):
    billed = Income.objects.approved().filter(customer=customer).total()
    received = Payment.objects.approved().filter(customer=customer).total()
    return money(customer.opening_balance + billed - received)

