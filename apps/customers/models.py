from decimal import Decimal

from django.conf import settings
from django.db import models
from django.urls import reverse

from apps.core.models import MONEY, TimeStampedModel


class Customer(TimeStampedModel):
    name = models.CharField(max_length=150)
    contact_person = models.CharField(max_length=120, blank=True)
    phone = models.CharField(max_length=20, blank=True)
    email = models.EmailField(blank=True)
    address = models.TextField(blank=True)
    gstin = models.CharField("GSTIN", max_length=20, blank=True)
    opening_balance = models.DecimalField(
        default=Decimal("0.00"),
        help_text="Amount the customer already owed before using this ERP.",
        **MONEY,
    )
    is_active = models.BooleanField(default=True)
    notes = models.TextField(blank=True)
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL, null=True, blank=True, on_delete=models.SET_NULL, related_name="+"
    )

    class Meta:
        ordering = ["name"]

    def get_absolute_url(self):
        return reverse("customers:detail", args=[self.pk])

    def __str__(self):
        return self.name
