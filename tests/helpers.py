from datetime import date
from decimal import Decimal

from django.test import TestCase
from django.utils import timezone

from apps.accounts.models import User
from apps.customers.models import Customer
from apps.finance.models import Expense, Income, Payment, PaymentMode, Status
from apps.fleet.models import Driver, Vehicle, VehicleCategory

D = Decimal
PASSWORD = "Str0ng!Pass#2026"


class ERPTestCase(TestCase):
    """Base fixture: one admin, entry user, viewer, two JCBs, one tractor,
    a driver and two customers."""

    @classmethod
    def setUpTestData(cls):
        cls.admin = User.objects.create_user("boss", password=PASSWORD, first_name="Boss", role=User.Role.ADMIN)
        cls.entry = User.objects.create_user(
            "clerk", password=PASSWORD, first_name="Clerk", role=User.Role.ENTRY, can_record_payment=True
        )
        cls.viewer = User.objects.create_user(
            "auditor", password=PASSWORD, first_name="Auditor", role=User.Role.VIEWER,
            can_view_reports=True, can_export_reports=True,
        )
        cls.jcb1 = Vehicle.objects.create(category=VehicleCategory.JCB, registration_number="KA01JCB1", name="JCB-1",
                                          hourly_rate=D("1200"), daily_rate=D("9000"))
        cls.jcb2 = Vehicle.objects.create(category=VehicleCategory.JCB, registration_number="KA01JCB2", name="JCB-2",
                                          hourly_rate=D("1300"), daily_rate=D("9500"))
        cls.tractor = Vehicle.objects.create(category=VehicleCategory.TRACTOR, registration_number="KA01TR1",
                                             name="Tractor-1", hourly_rate=D("700"), daily_rate=D("4500"))
        cls.driver = Driver.objects.create(name="Ramesh", phone="9845012345", assigned_vehicle=cls.jcb1)
        cls.cust = Customer.objects.create(name="Sai Constructions", opening_balance=D("1000"))
        cls.cust2 = Customer.objects.create(name="Green Farms")

    def login(self, user):
        self.client.force_login(user)

    # --- record builders (approved by admin unless stated) -----------------
    def _stamp(self, obj, user=None, status=Status.APPROVED):
        obj.created_by = user or self.admin
        obj.status = status
        if status == Status.APPROVED:
            obj.approved_by = self.admin
            obj.approved_at = timezone.now()
        obj.full_clean()
        obj.save()
        return obj

    def income(self, amount=None, vehicle=None, customer=None, billing=Income.BillingType.MANUAL, qty=None,
               rate=None, day=None, user=None, status=Status.APPROVED):
        vehicle = vehicle or self.jcb1
        inc = Income(date=day or date(2026, 5, 10), vehicle_category=vehicle.category, vehicle=vehicle,
                     customer=customer or self.cust, billing_type=billing, quantity=qty, rate=rate,
                     amount=D(amount) if amount is not None else None)
        return self._stamp(inc, user, status)

    def expense(self, amount, category=Expense.Category.DIESEL, vehicle="default", driver=None, dtype="",
                day=None, user=None, status=Status.APPROVED, mode=PaymentMode.CASH):
        if vehicle == "default":
            vehicle = self.jcb1
        exp = Expense(date=day or date(2026, 5, 10), category=category, vehicle=vehicle,
                      vehicle_category=vehicle.category if vehicle else "", driver=driver,
                      driver_expense_type=dtype, amount=D(amount), payment_mode=mode)
        return self._stamp(exp, user, status)

    def payment(self, amount, income=None, customer=None, day=None, user=None, status=Status.APPROVED,
                mode=PaymentMode.CASH):
        pay = Payment(date=day or date(2026, 5, 12), customer=customer or (income.customer if income else self.cust),
                      income=income, amount=D(amount), mode=mode)
        return self._stamp(pay, user, status)
