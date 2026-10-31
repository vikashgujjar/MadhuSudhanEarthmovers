from django.contrib.auth.models import AbstractUser
from django.db import models


class User(AbstractUser):
    class Role(models.TextChoices):
        ADMIN = "ADMIN", "Admin"
        ENTRY = "ENTRY", "Entry User"
        VIEWER = "VIEWER", "Viewer"

    # Capability flags. Admins implicitly have every capability; Viewers can
    # never write, whatever their flags say. See `has_capability`.
    CAPABILITIES = {
        "add_income": "Add income entries",
        "add_expense": "Add business expenses",
        "add_driver_expense": "Add driver expenses",
        "record_payment": "Record customer payments",
        "manage_masters": "Add / edit vehicles, drivers and customers",
        "view_reports": "View financial reports and dashboard totals",
        "export_reports": "Export reports (PDF, Excel, CSV)",
    }
    WRITE_CAPABILITIES = {"add_income", "add_expense", "add_driver_expense", "record_payment", "manage_masters"}

    role = models.CharField(max_length=10, choices=Role.choices, default=Role.ENTRY)
    phone = models.CharField(max_length=20, blank=True)

    can_add_income = models.BooleanField(default=True)
    can_add_expense = models.BooleanField(default=True)
    can_add_driver_expense = models.BooleanField(default=True)
    can_record_payment = models.BooleanField(default=False)
    can_manage_masters = models.BooleanField(default=False)
    can_view_reports = models.BooleanField(default=False)
    can_export_reports = models.BooleanField(default=False)

    class Meta:
        ordering = ["first_name", "last_name", "username"]

    def __str__(self):
        return self.get_full_name() or self.username

    @property
    def is_admin(self):
        return self.is_active and (self.role == self.Role.ADMIN or self.is_superuser)

    @property
    def is_entry_user(self):
        return self.role == self.Role.ENTRY and not self.is_superuser

    @property
    def is_viewer(self):
        return self.role == self.Role.VIEWER and not self.is_superuser

    def has_capability(self, name):
        if name not in self.CAPABILITIES:
            raise ValueError(f"Unknown capability: {name}")
        if not self.is_active:
            return False
        if self.is_admin:
            return True
        if self.is_viewer and name in self.WRITE_CAPABILITIES:
            return False
        return bool(getattr(self, f"can_{name}"))

    @property
    def sees_all_records(self):
        """Admins and Viewers see every record; Entry Users see only their own."""
        return self.is_admin or self.is_viewer

    @property
    def capabilities(self):
        return {name: self.has_capability(name) for name in self.CAPABILITIES}
