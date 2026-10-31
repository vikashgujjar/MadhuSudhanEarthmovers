"""Approval workflow and server-side permission enforcement."""
from datetime import date

from django.core.exceptions import PermissionDenied, ValidationError
from django.urls import reverse

from apps.accounts.models import User
from apps.core.models import BusinessSettings
from apps.finance import services as fin
from apps.finance import workflow
from apps.finance.models import Expense, Income, Payment, Status

from .helpers import D, ERPTestCase


def income_post(vehicle, customer, **extra):
    data = {
        "date": "2026-05-10", "vehicle_category": vehicle.category, "vehicle": vehicle.pk, "customer": customer.pk,
        "billing_type": "HOURLY", "quantity": "5", "rate": "1200", "amount": "", "driver": "",
        "work_location": "Site A", "description": "", "received_now": "", "payment_mode": "CASH",
        "payment_reference": "",
    }
    data.update(extra)
    return data


class WorkflowTests(ERPTestCase):
    def test_entry_user_income_is_pending_and_not_counted(self):
        self.login(self.entry)
        resp = self.client.post(reverse("finance:income_create"), income_post(self.jcb1, self.cust))
        inc = Income.objects.get()
        self.assertRedirects(resp, inc.get_absolute_url())
        self.assertEqual(inc.status, Status.PENDING)
        self.assertEqual(inc.amount, D("6000.00"))
        self.assertEqual(inc.created_by, self.entry)
        self.assertEqual(fin.financial_summary()["income_total"], D("0.00"))

    def test_admin_income_is_approved_immediately(self):
        self.login(self.admin)
        self.client.post(reverse("finance:income_create"), income_post(self.jcb1, self.cust))
        inc = Income.objects.get()
        self.assertEqual(inc.status, Status.APPROVED)
        self.assertEqual(inc.approved_by, self.admin)

    def test_approval_not_required_setting(self):
        bs = BusinessSettings.load()
        bs.require_approval = False
        bs.save()
        self.login(self.entry)
        self.client.post(reverse("finance:income_create"), income_post(self.jcb1, self.cust))
        self.assertEqual(Income.objects.get().status, Status.APPROVED)

    def test_initial_payment_recorded_and_approved_with_invoice(self):
        self.login(self.entry)
        self.client.post(reverse("finance:income_create"),
                         income_post(self.jcb1, self.cust, received_now="2500", payment_mode="UPI"))
        inc = Income.objects.get()
        pay = Payment.objects.get()
        self.assertEqual((pay.income, pay.amount, pay.is_initial, pay.status), (inc, D("2500.00"), True,
                                                                                 Status.PENDING))
        self.login(self.admin)
        self.client.post(reverse("finance:approve", args=["income", inc.pk]))
        pay.refresh_from_db()
        self.assertEqual(pay.status, Status.APPROVED)
        inc.refresh_from_db()
        self.assertEqual(inc.outstanding, D("3500.00"))

    def test_received_now_cannot_exceed_billed(self):
        self.login(self.entry)
        resp = self.client.post(reverse("finance:income_create"),
                                income_post(self.jcb1, self.cust, received_now="9000"))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, "Cannot exceed the billed amount")
        self.assertFalse(Income.objects.exists())

    def test_reject_requires_reason_and_cascades_to_payments(self):
        inc = self.income(amount="5000", user=self.entry, status=Status.PENDING)
        pay = self.payment("1000", income=inc, user=self.entry, status=Status.PENDING)
        with self.assertRaises(ValidationError):
            workflow.reject(inc, self.admin, "  ")
        workflow.reject(inc, self.admin, "Wrong customer")
        inc.refresh_from_db()
        pay.refresh_from_db()
        self.assertEqual(inc.status, Status.REJECTED)
        self.assertEqual(inc.rejection_reason, "Wrong customer")
        self.assertEqual(pay.status, Status.REJECTED)

    def test_payment_cannot_be_approved_before_invoice(self):
        inc = self.income(amount="5000", user=self.entry, status=Status.PENDING)
        pay = self.payment("1000", income=inc, user=self.entry, status=Status.PENDING)
        with self.assertRaises(ValidationError):
            workflow.approve(pay, self.admin)

    def test_cannot_approve_twice(self):
        inc = self.income(amount="5000", user=self.entry, status=Status.PENDING)
        workflow.approve(inc, self.admin)
        with self.assertRaises(ValidationError):
            workflow.approve(inc, self.admin)

    def test_only_admin_can_approve(self):
        inc = self.income(amount="5000", user=self.entry, status=Status.PENDING)
        with self.assertRaises(PermissionDenied):
            workflow.approve(inc, self.entry)
        self.login(self.entry)
        resp = self.client.post(reverse("finance:approve", args=["income", inc.pk]))
        self.assertEqual(resp.status_code, 403)
        inc.refresh_from_db()
        self.assertEqual(inc.status, Status.PENDING)

    def test_editing_rejected_entry_resubmits_it(self):
        inc = self.income(amount="5000", user=self.entry, status=Status.PENDING)
        workflow.reject(inc, self.admin, "Hours wrong")
        self.login(self.entry)
        data = income_post(self.jcb1, self.cust, quantity="4")
        for k in ("received_now", "payment_mode", "payment_reference"):
            data.pop(k)
        resp = self.client.post(reverse("finance:income_update", args=[inc.pk]), data)
        self.assertEqual(resp.status_code, 302)
        inc.refresh_from_db()
        self.assertEqual((inc.status, inc.rejection_reason, inc.amount), (Status.PENDING, "", D("4800.00")))

    def test_entry_user_cannot_edit_approved_entry(self):
        inc = self.income(amount="5000", user=self.entry)
        self.login(self.entry)
        self.assertEqual(self.client.get(reverse("finance:income_update", args=[inc.pk])).status_code, 403)

    def test_bulk_approve_handles_invoices_before_payments(self):
        inc = self.income(amount="5000", user=self.entry, status=Status.PENDING)
        pay = self.payment("1000", income=inc, user=self.entry, status=Status.PENDING)
        exp = self.expense("300", user=self.entry, status=Status.PENDING)
        self.login(self.admin)
        self.client.post(reverse("finance:bulk_approve"),
                         {"income": [inc.pk], "payment": [pay.pk], "expense": [exp.pk]})
        for obj in (inc, pay, exp):
            obj.refresh_from_db()
            self.assertEqual(obj.status, Status.APPROVED)


class PermissionTests(ERPTestCase):
    admin_only = [
        ("accounts:user_list", []), ("accounts:user_create", []), ("core:settings", []), ("audit:list", []),
        ("finance:approvals", []),
    ]

    def test_admin_pages_forbidden_for_entry_and_viewer(self):
        for user in (self.entry, self.viewer):
            self.login(user)
            for name, args in self.admin_only:
                self.assertEqual(self.client.get(reverse(name, args=args)).status_code, 403, (user, name))

    def test_anonymous_redirected_to_login(self):
        for name in ("core:dashboard", "finance:income_list", "reports:cashbook", "fleet:jcb"):
            resp = self.client.get(reverse(name))
            self.assertEqual(resp.status_code, 302)
            self.assertIn(reverse("accounts:login"), resp["Location"])

    def test_viewer_cannot_write_even_with_flags(self):
        self.viewer.can_add_income = True
        self.viewer.can_manage_masters = True
        self.viewer.save()
        self.login(self.viewer)
        self.assertEqual(self.client.get(reverse("finance:income_create")).status_code, 403)
        resp = self.client.post(reverse("finance:income_create"), income_post(self.jcb1, self.cust))
        self.assertEqual(resp.status_code, 403)
        self.assertEqual(self.client.post(reverse("fleet:vehicle_create"), {}).status_code, 403)
        self.assertFalse(Income.objects.exists())

    def test_entry_user_without_capability_is_blocked(self):
        self.entry.can_add_income = False
        self.entry.save()
        self.login(self.entry)
        resp = self.client.post(reverse("finance:income_create"), income_post(self.jcb1, self.cust))
        self.assertEqual(resp.status_code, 403)
        self.assertFalse(Income.objects.exists())

    def test_driver_expense_capability_is_separate(self):
        self.entry.can_add_driver_expense = False
        self.entry.save()
        self.login(self.entry)
        self.assertEqual(self.client.get(reverse("finance:driver_expense_create")).status_code, 403)
        self.assertEqual(self.client.get(reverse("finance:expense_create")).status_code, 200)

    def test_entry_user_sees_only_own_transactions(self):
        mine = self.income(amount="100", user=self.entry, status=Status.PENDING)
        other = self.income(amount="200", user=self.admin)
        self.login(self.entry)
        resp = self.client.get(reverse("finance:income_list"))
        self.assertContains(resp, mine.number)
        self.assertNotContains(resp, other.number)
        self.assertEqual(self.client.get(other.get_absolute_url()).status_code, 404)

    def test_entry_user_without_reports_gets_entry_dashboard(self):
        self.login(self.entry)
        resp = self.client.get(reverse("core:dashboard"))
        self.assertTemplateUsed(resp, "core/dashboard_entry.html")
        self.assertEqual(self.client.get(reverse("reports:cashbook")).status_code, 403)
        self.assertEqual(self.client.get(reverse("reports:profitability")).status_code, 403)

    def test_viewer_with_reports_sees_financial_dashboard(self):
        self.login(self.viewer)
        self.assertTemplateUsed(self.client.get(reverse("core:dashboard")), "core/dashboard.html")

    def test_export_requires_capability(self):
        self.entry.can_view_reports = True
        self.entry.save()
        self.login(self.entry)
        self.assertEqual(self.client.get(reverse("reports:cashbook") + "?export=xlsx").status_code, 403)
        self.assertEqual(self.client.get(reverse("finance:income_list") + "?export=csv").status_code, 403)

    def test_only_admin_can_delete(self):
        inc = self.income(amount="100", user=self.entry, status=Status.PENDING)
        self.login(self.entry)
        self.assertEqual(self.client.post(reverse("finance:income_delete", args=[inc.pk])).status_code, 403)
        self.assertTrue(Income.objects.filter(pk=inc.pk).exists())

    def test_delete_blocked_when_dependants_exist(self):
        inc = self.income(amount="1000")
        self.payment("100", income=inc)
        self.login(self.admin)
        resp = self.client.post(reverse("finance:income_delete", args=[inc.pk]))
        self.assertRedirects(resp, inc.get_absolute_url())
        self.assertTrue(Income.objects.filter(pk=inc.pk).exists())
        resp = self.client.post(reverse("fleet:vehicle_delete", args=[self.jcb1.pk]))
        self.assertTrue(type(self.jcb1).objects.filter(pk=self.jcb1.pk).exists())

    def test_last_admin_cannot_be_demoted(self):
        self.login(self.admin)
        resp = self.client.post(reverse("accounts:user_update", args=[self.admin.pk]), {
            "username": "boss", "first_name": "Boss", "role": "ENTRY", "is_active": "on",
        })
        self.assertEqual(resp.status_code, 200)
        self.admin.refresh_from_db()
        self.assertEqual(self.admin.role, User.Role.ADMIN)

    def test_admin_creates_entry_user_with_permissions(self):
        self.login(self.admin)
        resp = self.client.post(reverse("accounts:user_create"), {
            "username": "newclerk", "first_name": "New", "last_name": "", "email": "", "phone": "", "role": "ENTRY",
            "password1": "Very$ecure123", "password2": "Very$ecure123", "can_add_income": "on",
        })
        self.assertRedirects(resp, reverse("accounts:user_list"))
        u = User.objects.get(username="newclerk")
        self.assertTrue(u.has_capability("add_income"))
        self.assertFalse(u.has_capability("add_expense"))
        self.assertFalse(u.has_capability("view_reports"))


class FormValidationTests(ERPTestCase):
    def test_vehicle_from_other_category_rejected_by_form(self):
        self.login(self.admin)
        data = income_post(self.jcb1, self.cust, vehicle_category="TRACTOR")
        resp = self.client.post(reverse("finance:income_create"), data)
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, "Select a valid choice")
        self.assertContains(resp, "is-invalid")
        self.assertFalse(Income.objects.exists())

    def test_missing_category_shows_error(self):
        self.login(self.admin)
        resp = self.client.post(reverse("finance:income_create"), income_post(self.jcb1, self.cust,
                                                                               vehicle_category=""))
        self.assertContains(resp, "This field is required")

    def test_diesel_expense_requires_vehicle_category(self):
        self.login(self.admin)
        resp = self.client.post(reverse("finance:expense_create"), {
            "date": "2026-05-01", "category": "DIESEL", "vehicle_category": "", "vehicle": "", "amount": "500",
            "payment_mode": "CASH",
        })
        self.assertContains(resp, "Select the vehicle category first")
        self.assertFalse(Expense.objects.exists())

    def test_driver_expense_form_saves_driver_and_vehicle(self):
        self.login(self.entry)
        resp = self.client.post(reverse("finance:driver_expense_create"), {
            "date": "2026-05-01", "category": "DIESEL",  # tampered: forced back to DRIVER
            "driver": self.driver.pk, "driver_expense_type": "BATA", "vehicle_category": "JCB",
            "vehicle": self.jcb1.pk, "amount": "350", "payment_mode": "CASH",
        })
        self.assertEqual(resp.status_code, 302)
        exp = Expense.objects.get()
        self.assertEqual((exp.category, exp.driver, exp.vehicle), (Expense.Category.DRIVER, self.driver, self.jcb1))

    def test_regular_expense_form_refuses_driver_category(self):
        self.login(self.admin)
        resp = self.client.post(reverse("finance:expense_create"), {
            "date": "2026-05-01", "category": "DRIVER", "amount": "500", "payment_mode": "CASH",
        })
        self.assertEqual(resp.status_code, 200)
        self.assertFalse(Expense.objects.exists())

    def test_payment_form_overpayment_error_shown(self):
        inc = self.income(amount="1000")
        self.login(self.admin)
        resp = self.client.post(reverse("finance:payment_create"), {
            "date": "2026-05-02", "customer": self.cust.pk, "income": inc.pk, "amount": "1500", "mode": "CASH",
        })
        self.assertContains(resp, "exceeds the balance due")
        self.assertFalse(Payment.objects.exists())

    def test_duplicate_registration_rejected(self):
        self.login(self.admin)
        resp = self.client.post(reverse("fleet:vehicle_create"), {
            "category": "JCB", "registration_number": "ka01 jcb1", "hourly_rate": "0", "daily_rate": "0",
            "status": "ACTIVE",
        })
        self.assertContains(resp, "already exists")

    def test_vehicle_api_returns_only_category(self):
        self.login(self.entry)
        data = self.client.get(reverse("fleet:api_vehicles") + "?category=JCB").json()
        self.assertEqual({v["id"] for v in data["vehicles"]}, {self.jcb1.pk, self.jcb2.pk})
        self.assertEqual(self.client.get(reverse("fleet:api_vehicles") + "?category=X").status_code, 400)

    def test_open_invoices_api(self):
        inc = self.income(amount="1000")
        self.payment("400", income=inc)
        paid = self.income(amount="500")
        self.payment("500", income=paid)
        self.login(self.admin)
        data = self.client.get(reverse("finance:api_open_invoices") + f"?customer={self.cust.pk}").json()
        self.assertEqual([(i["id"], i["due"]) for i in data["invoices"]], [(inc.pk, "600.00")])

    def test_list_search_filter_pagination(self):
        for i in range(30):
            self.income(amount=str(100 + i), vehicle=self.tractor if i % 2 else self.jcb1,
                        day=date(2026, 5, 1 + i % 28))
        self.login(self.admin)
        url = reverse("finance:income_list")
        resp = self.client.get(url + "?vehicle_category=TRACTOR")
        self.assertEqual(resp.context["paginator"].count, 15)
        resp = self.client.get(url)
        self.assertEqual(len(resp.context["incomes"]), 25)
        resp = self.client.get(url + "?page=2")
        self.assertEqual(len(resp.context["incomes"]), 5)
        target = Income.objects.order_by("pk").first()
        resp = self.client.get(url + f"?q={target.number}")
        self.assertEqual(resp.context["paginator"].count, 1)
        resp = self.client.get(url + "?period=custom&from=2026-05-01&to=2026-05-02")
        self.assertTrue(all(i.date <= date(2026, 5, 2) for i in resp.context["incomes"]))
