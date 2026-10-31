from decimal import Decimal

from django.conf import settings
from django.core.validators import MaxValueValidator, MinValueValidator
from django.db import models
from django.urls import reverse

from apps.core.models import MONEY, TimeStampedModel


class VehicleCategory(models.TextChoices):
    JCB = "JCB", "JCB"
    TRACTOR = "TRACTOR", "Tractor"


class Vehicle(TimeStampedModel):
    class Status(models.TextChoices):
        ACTIVE = "ACTIVE", "Active"
        MAINTENANCE = "MAINTENANCE", "Under maintenance"
        INACTIVE = "INACTIVE", "Inactive / sold"

    category = models.CharField(max_length=10, choices=VehicleCategory.choices, db_index=True)
    registration_number = models.CharField(max_length=20, unique=True)
    name = models.CharField(max_length=80, blank=True, help_text="Short name used on site, e.g. 'JCB-1'.")
    make = models.CharField(max_length=60, blank=True)
    model = models.CharField(max_length=60, blank=True)
    year = models.PositiveSmallIntegerField(
        null=True, blank=True, validators=[MinValueValidator(1950), MaxValueValidator(2100)]
    )
    chassis_number = models.CharField(max_length=40, blank=True)
    engine_number = models.CharField(max_length=40, blank=True)
    purchase_date = models.DateField(null=True, blank=True)
    purchase_cost = models.DecimalField(null=True, blank=True, **MONEY)
    hourly_rate = models.DecimalField(
        default=Decimal("0.00"), help_text="Default billing rate per hour.", **MONEY
    )
    daily_rate = models.DecimalField(default=Decimal("0.00"), help_text="Default billing rate per day.", **MONEY)
    insurance_expiry = models.DateField(null=True, blank=True)
    status = models.CharField(max_length=12, choices=Status.choices, default=Status.ACTIVE)
    notes = models.TextField(blank=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+"
    )

    class Meta:
        ordering = ["category", "registration_number"]

    def get_absolute_url(self):
        return reverse("fleet:vehicle_detail", args=[self.pk])

    def __str__(self):
        label = self.registration_number
        if self.name:
            label = f"{self.name} ({self.registration_number})"
        return f"{self.get_category_display()} · {label}"

    def save(self, *args, **kwargs):
        self.registration_number = " ".join(self.registration_number.upper().split())
        super().save(*args, **kwargs)

    @property
    def display_name(self):
        return self.name or self.registration_number

    @property
    def is_usable(self):
        return self.status != self.Status.INACTIVE


class Driver(TimeStampedModel):
    class Status(models.TextChoices):
        ACTIVE = "ACTIVE", "Active"
        INACTIVE = "INACTIVE", "Inactive"

    name = models.CharField(max_length=120)
    phone = models.CharField(max_length=20, blank=True)
    alternate_phone = models.CharField(max_length=20, blank=True)
    license_number = models.CharField(max_length=40, blank=True)
    license_expiry = models.DateField(null=True, blank=True)
    address = models.TextField(blank=True)
    assigned_vehicle = models.ForeignKey(
        Vehicle, null=True, blank=True, on_delete=models.SET_NULL, related_name="drivers"
    )
    monthly_salary = models.DecimalField(default=Decimal("0.00"), **MONEY)
    daily_allowance = models.DecimalField(
        "Daily allowance (bata)", default=Decimal("0.00"), **MONEY
    )
    joining_date = models.DateField(null=True, blank=True)
    status = models.CharField(max_length=10, choices=Status.choices, default=Status.ACTIVE)
    notes = models.TextField(blank=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+"
    )

    class Meta:
        ordering = ["name"]

    def get_absolute_url(self):
        return reverse("fleet:driver_detail", args=[self.pk])

    def __str__(self):
        return f"{self.name} ({self.phone})" if self.phone else self.name
