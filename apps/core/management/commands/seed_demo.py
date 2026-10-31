"""Optional sample data for evaluating the ERP. Never run on a live database.

Creates clearly labelled DEMO records through the normal models, so every
figure on the dashboard is still computed from database rows."""
import random
from datetime import timedelta
from decimal import Decimal

from django.core.management.base import BaseCommand, CommandError
from django.db import transaction
from django.utils import timezone

from apps.accounts.models import User
from apps.customers.models import Customer
from apps.finance.models import Expense, Income, Payment, PaymentMode, Status
from apps.fleet.models import Driver, Vehicle, VehicleCategory

DEMO_PASSWORD = "Demo@12345"


class Command(BaseCommand):
    help = "Load DEMO vehicles, drivers, customers and transactions (for evaluation only)."

    def add_arguments(self, parser):
        parser.add_argument("--yes", action="store_true", help="Confirm you want demo data in this database.")
        parser.add_argument("--months", type=int, default=6)

    @transaction.atomic
    def handle(self, *args, **opts):
        if not opts["yes"]:
            raise CommandError("Demo data is for evaluation only. Re-run with --yes to confirm.")
        if Vehicle.objects.filter(notes__startswith="DEMO").exists():
            raise CommandError("Demo data already loaded.")
        rng = random.Random(42)
        today = timezone.localdate()

        admin = User.objects.filter(role=User.Role.ADMIN).first()
        if admin is None:
            admin = User.objects.create_superuser("admin", password=DEMO_PASSWORD, first_name="Admin",
                                                  role=User.Role.ADMIN)
        entry, _ = User.objects.get_or_create(username="entry", defaults={
            "first_name": "Site", "last_name": "Supervisor", "role": User.Role.ENTRY, "can_record_payment": True})
        entry.set_password(DEMO_PASSWORD)
        entry.save()
        viewer, _ = User.objects.get_or_create(username="viewer", defaults={
            "first_name": "Accounts", "last_name": "Viewer", "role": User.Role.VIEWER, "can_view_reports": True,
            "can_export_reports": True})
        viewer.set_password(DEMO_PASSWORD)
        viewer.save()

        vehicles = [
            Vehicle.objects.create(category=VehicleCategory.JCB, registration_number="KA 36 M 1101", name="JCB-1",
                                   make="JCB", model="3DX Super", year=2021, hourly_rate=Decimal("1200"),
                                   daily_rate=Decimal("9000"), notes="DEMO record"),
            Vehicle.objects.create(category=VehicleCategory.JCB, registration_number="KA 36 M 1102", name="JCB-2",
                                   make="JCB", model="3DX Xtra", year=2023, hourly_rate=Decimal("1300"),
                                   daily_rate=Decimal("9800"), notes="DEMO record"),
            Vehicle.objects.create(category=VehicleCategory.TRACTOR, registration_number="KA 36 T 2201",
                                   name="Tractor-1", make="Mahindra", model="575 DI", year=2020,
                                   hourly_rate=Decimal("700"), daily_rate=Decimal("4500"), notes="DEMO record"),
            Vehicle.objects.create(category=VehicleCategory.TRACTOR, registration_number="KA 36 T 2202",
                                   name="Tractor-2", make="Swaraj", model="744 FE", year=2022,
                                   hourly_rate=Decimal("650"), daily_rate=Decimal("4200"), notes="DEMO record"),
        ]
        names = ["Ramesh Kumar", "Suresh Gowda", "Manjunath", "Basavaraj"]
        drivers = [
            Driver.objects.create(name=n, phone=f"98450{1000 + i:05d}", assigned_vehicle=vehicles[i],
                                  monthly_salary=Decimal("18000"), daily_allowance=Decimal("300"), notes="DEMO record")
            for i, n in enumerate(names)
        ]
        customers = [
            Customer.objects.create(name=n, phone=f"99000{2000 + i:05d}", opening_balance=Decimal(ob),
                                    notes="DEMO record")
            for i, (n, ob) in enumerate([("Sri Sai Constructions", "15000"), ("Green Fields Farms", "0"),
                                          ("Gram Panchayat Works", "0"), ("Lakshmi Builders", "5000"),
                                          ("Patil Agro", "0")])
        ]

        def stamp(obj, user=admin, status=Status.APPROVED):
            obj.created_by = user
            obj.status = status
            if status == Status.APPROVED:
                obj.approved_by, obj.approved_at = admin, timezone.now()

        start = today - timedelta(days=30 * opts["months"])
        day = start
        while day <= today:
            for v in vehicles:
                if rng.random() < 0.35:
                    billing = rng.choice([Income.BillingType.HOURLY, Income.BillingType.HOURLY,
                                          Income.BillingType.DAILY, Income.BillingType.FIXED])
                    inc = Income(date=day, vehicle_category=v.category, vehicle=v, customer=rng.choice(customers),
                                 driver=drivers[vehicles.index(v)], billing_type=billing,
                                 work_location=rng.choice(["Hosur Road site", "Village tank desilting",
                                                           "Farm levelling", "Layout road work"]))
                    if billing == Income.BillingType.HOURLY:
                        inc.quantity, inc.rate = Decimal(rng.choice([4, 6, 8, 10])), v.hourly_rate
                    elif billing == Income.BillingType.DAILY:
                        inc.quantity, inc.rate = Decimal(rng.choice([1, 2, 3])), v.daily_rate
                    else:
                        inc.amount = Decimal(rng.choice([15000, 25000, 40000]))
                    recent = (today - day).days < 10
                    stamp(inc, entry if recent else admin, Status.PENDING if recent and rng.random() < 0.5
                          else Status.APPROVED)
                    inc.full_clean()
                    inc.save()
                    if inc.status == Status.APPROVED and rng.random() < 0.75:
                        share = Decimal(rng.choice(["1", "1", "0.5", "0.6"]))
                        pay = Payment(date=day + timedelta(days=rng.randint(0, 12)), customer=inc.customer,
                                      income=inc, amount=(inc.amount * share).quantize(Decimal("1")),
                                      mode=rng.choice(PaymentMode.values))
                        if pay.date > today:
                            pay.date = today
                        stamp(pay)
                        pay.full_clean()
                        pay.save()
                if rng.random() < 0.25:
                    e = Expense(date=day, category=Expense.Category.DIESEL, vehicle_category=v.category, vehicle=v,
                                litres=Decimal(rng.choice([40, 60, 80])), vendor="HP Fuel Station",
                                payment_mode=PaymentMode.CASH)
                    e.amount = e.litres * Decimal("92.50")
                    stamp(e)
                    e.full_clean()
                    e.save()
            if rng.random() < 0.08:
                v = rng.choice(vehicles)
                e = Expense(date=day, category=rng.choice([Expense.Category.REPAIR, Expense.Category.SPARE_PARTS,
                                                           Expense.Category.MAINTENANCE]),
                            vehicle_category=v.category, vehicle=v, amount=Decimal(rng.choice([2500, 6000, 12000])),
                            vendor="Sri Ganesh Earthmover Works", payment_mode=PaymentMode.UPI)
                stamp(e)
                e.full_clean()
                e.save()
            if day.day == 1:
                for d in drivers:
                    e = Expense(date=day, category=Expense.Category.DRIVER, driver=d, vehicle=d.assigned_vehicle,
                                vehicle_category=d.assigned_vehicle.category,
                                driver_expense_type=Expense.DriverExpenseType.SALARY, amount=d.monthly_salary,
                                payment_mode=PaymentMode.BANK)
                    stamp(e)
                    e.full_clean()
                    e.save()
                e = Expense(date=day, category=Expense.Category.OTHER, amount=Decimal("8000"),
                            vendor="Office rent", payment_mode=PaymentMode.BANK, description="Yard and office rent")
                stamp(e)
                e.full_clean()
                e.save()
            day += timedelta(days=1)

        self.stdout.write(self.style.SUCCESS(
            f"Demo data loaded. Users: admin / entry / viewer (password for new demo users: {DEMO_PASSWORD})."
        ))
