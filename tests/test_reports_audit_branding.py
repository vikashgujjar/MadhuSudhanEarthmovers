"""Exports, audit trail, business settings / branding, and a full link crawl."""
import csv
import io
import re
from datetime import date
from urllib.parse import urlsplit

from django.urls import reverse
from openpyxl import load_workbook

from apps.audit.models import AuditLog
from apps.core.models import BusinessSettings
from apps.finance import workflow
from apps.finance.models import Expense, Income, Status

from .helpers import D, PASSWORD, ERPTestCase


class ExportTests(ERPTestCase):
    def setUp(self):
        inv = self.income(amount="12345.50", day=date(2026, 5, 1))
        self.payment("2345.50", income=inv, day=date(2026, 5, 2))
        self.expense("1000", day=date(2026, 5, 3))
        self.login(self.admin)

    def test_csv_contains_business_name_and_records(self):
        resp = self.client.get(reverse("finance:income_list") + "?export=csv")
        self.assertEqual(resp["Content-Type"], "text/csv; charset=utf-8")
        text = resp.content.decode("utf-8-sig")
        rows = list(csv.reader(io.StringIO(text)))
        self.assertEqual(rows[0], ["Madhusudhan Earthmover"])
        flat = "\n".join(",".join(r) for r in rows)
        self.assertIn("12345.50", flat)
        self.assertIn("10000.00", flat)  # balance

    def test_xlsx_is_valid_and_branded(self):
        resp = self.client.get(reverse("reports:profitability") + "?period=all&export=xlsx")
        self.assertEqual(resp.status_code, 200)
        wb = load_workbook(io.BytesIO(resp.content))
        ws = wb.active
        self.assertEqual(ws["A1"].value, "MADHUSUDHAN EARTHMOVER")
        values = [c.value for row in ws.iter_rows() for c in row]
        self.assertIn("Vehicle-wise Profitability", values)
        self.assertIn(12345.5, values)

    def test_pdf_is_generated(self):
        for name in ("reports:cashbook", "reports:pnl", "customers:outstanding", "finance:expense_list",
                     "reports:driver_expenses", "reports:expense_categories", "audit:list"):
            resp = self.client.get(reverse(name) + "?period=all&export=pdf")
            self.assertEqual(resp.status_code, 200, name)
            self.assertEqual(resp["Content-Type"], "application/pdf")
            self.assertTrue(resp.content.startswith(b"%PDF"), name)

    def test_export_is_audited(self):
        self.client.get(reverse("customers:outstanding") + "?export=xlsx")
        log = AuditLog.objects.filter(action=AuditLog.Action.EXPORT).latest("timestamp")
        self.assertIn("Customer Outstanding Balances", log.object_repr)

    def test_export_uses_same_filters_as_screen(self):
        resp = self.client.get(reverse("finance:income_list") + "?vehicle_category=TRACTOR&export=csv")
        self.assertNotIn("12345.50", resp.content.decode("utf-8-sig"))

    def test_report_pages_show_database_values(self):
        resp = self.client.get(reverse("reports:pnl") + "?period=all")
        self.assertContains(resp, "12,345.50")
        self.assertContains(resp, "11,345.50")  # net profit
        resp = self.client.get(reverse("core:dashboard") + "?period=all")
        self.assertEqual(resp.context["summary"]["received_total"], D("2345.50"))
        self.assertContains(resp, "2,345.50")


class AuditTests(ERPTestCase):
    def test_create_update_delete_approve_logged(self):
        self.login(self.admin)
        self.client.post(reverse("customers:create"), {"name": "Audit Co", "opening_balance": "0", "is_active": "on"})
        cust = type(self.cust).objects.get(name="Audit Co")
        self.client.post(reverse("customers:update", args=[cust.pk]),
                         {"name": "Audit Co", "opening_balance": "500", "is_active": "on"})
        update = AuditLog.objects.get(action=AuditLog.Action.UPDATE, object_id=str(cust.pk))
        self.assertEqual(update.changes["fields"]["opening_balance"], ["0.00", "500.00"])
        self.client.post(reverse("customers:delete", args=[cust.pk]))
        deleted = AuditLog.objects.get(action=AuditLog.Action.DELETE, object_id=str(cust.pk))
        self.assertEqual(deleted.changes["snapshot"]["name"], "Audit Co")

        inc = self.income(amount="100", user=self.entry, status=Status.PENDING)
        workflow.approve(inc, self.admin)
        self.assertTrue(AuditLog.objects.filter(action=AuditLog.Action.APPROVE, object_id=str(inc.pk)).exists())

    def test_transaction_amount_change_preserved(self):
        inc = self.income(amount="1000")
        self.login(self.admin)
        self.client.post(reverse("finance:income_update", args=[inc.pk]), {
            "date": "2026-05-10", "vehicle_category": "JCB", "vehicle": self.jcb1.pk, "customer": self.cust.pk,
            "billing_type": "MANUAL", "amount": "1500",
        })
        log = AuditLog.objects.get(action=AuditLog.Action.UPDATE, model_name="Income Entry")
        self.assertEqual(log.changes["fields"]["amount"], ["1000.00", "1500.00"])

    def test_failed_delete_leaves_no_delete_log(self):
        inc = self.income(amount="1000")
        self.payment("10", income=inc)
        self.login(self.admin)
        self.client.post(reverse("finance:income_delete", args=[inc.pk]))
        self.assertFalse(AuditLog.objects.filter(action=AuditLog.Action.DELETE).exists())

    def test_logs_are_immutable(self):
        log = AuditLog.objects.create(action=AuditLog.Action.CREATE, object_repr="x")
        log.object_repr = "tampered"
        with self.assertRaises(PermissionError):
            log.save()
        with self.assertRaises(PermissionError):
            log.delete()

    def test_login_and_failed_login_logged_and_lockout(self):
        url = reverse("accounts:login")
        self.client.post(url, {"username": "clerk", "password": PASSWORD})
        self.assertTrue(AuditLog.objects.filter(action=AuditLog.Action.LOGIN, username="clerk").exists())
        self.client.post(reverse("accounts:logout"))
        for _ in range(5):
            resp = self.client.post(url, {"username": "clerk", "password": "wrong"})
            self.assertContains(resp, "Incorrect username or password")
        self.assertEqual(AuditLog.objects.filter(action=AuditLog.Action.LOGIN_FAILED).count(), 5)
        resp = self.client.post(url, {"username": "clerk", "password": PASSWORD})
        self.assertContains(resp, "Too many failed attempts")
        self.assertNotIn("_auth_user_id", self.client.session)

    def test_inactive_user_cannot_log_in(self):
        self.entry.is_active = False
        self.entry.save()
        resp = self.client.post(reverse("accounts:login"), {"username": "clerk", "password": PASSWORD})
        self.assertEqual(resp.status_code, 200)
        self.assertNotIn("_auth_user_id", self.client.session)


class BrandingTests(ERPTestCase):
    def test_default_organisation_from_migration(self):
        bs = BusinessSettings.load()
        self.assertEqual(bs.business_name, "Madhusudhan Earthmover")
        self.assertEqual(bs.erp_title, "Madhusudhan Earthmover ERP")

    def test_name_shown_on_login_sidebar_and_dashboard(self):
        resp = self.client.get(reverse("accounts:login"))
        self.assertContains(resp, "Madhusudhan Earthmover ERP")
        self.assertContains(resp, "Madhusudhan")
        self.login(self.admin)
        resp = self.client.get(reverse("core:dashboard"))
        self.assertContains(resp, "Earthmover")
        self.assertContains(resp, "Madhusudhan Earthmover")

    def test_admin_can_rename_and_change_applies_everywhere(self):
        self.login(self.admin)
        data = {f: v for f, v in {
            "business_name": "Madhusudhan Earthmovers Pvt Ltd", "erp_title": "ME Fleet ERP",
            "tagline": "JCB & Tractor Hire", "currency_symbol": "₹", "financial_year_start_month": "4",
            "invoice_prefix": "me-inv", "receipt_prefix": "ME-RCT", "expense_prefix": "ME-EXP",
            "opening_cash_balance": "0", "require_approval": "on", "report_footer": "Thank you",
        }.items()}
        resp = self.client.post(reverse("core:settings"), data)
        self.assertRedirects(resp, reverse("core:settings"))
        self.assertEqual(BusinessSettings.load().invoice_prefix, "ME-INV")
        self.assertTrue(AuditLog.objects.filter(action=AuditLog.Action.SETTINGS).exists())
        self.client.logout()
        self.assertContains(self.client.get(reverse("accounts:login")), "ME Fleet ERP")
        self.login(self.admin)
        csv_text = self.client.get(reverse("customers:list") + "?export=csv").content.decode("utf-8-sig")
        self.assertTrue(csv_text.startswith("Madhusudhan Earthmovers Pvt Ltd"))

    def test_settings_validation(self):
        self.login(self.admin)
        resp = self.client.post(reverse("core:settings"), {"business_name": "", "invoice_prefix": "bad prefix!"})
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, "This field is required")
        self.assertEqual(BusinessSettings.load().business_name, "Madhusudhan Earthmover")

    def test_settings_singleton(self):
        BusinessSettings(business_name="Other").save()
        self.assertEqual(BusinessSettings.objects.count(), 1)


HREF = re.compile(r'href="([/?][^"#]*)"')
SKIP = ("/media/", "/django-admin/", "/static/", "/accounts/logout/")


class CrawlTests(ERPTestCase):
    """Every link a role can see must open without error."""

    def setUp(self):
        inv = self.income(billing=Income.BillingType.HOURLY, qty=D("5"), rate=D("1200"))
        self.payment("2000", income=inv)
        self.payment("500", customer=self.cust2)
        self.income(amount="900", vehicle=self.tractor, customer=self.cust2, user=self.entry, status=Status.PENDING)
        self.expense("500")
        self.expense("800", category=Expense.Category.DRIVER, driver=self.driver,
                     dtype=Expense.DriverExpenseType.ADVANCE, user=self.entry, status=Status.PENDING)
        rejected = self.expense("50", category=Expense.Category.OTHER, vehicle=None, user=self.entry,
                                status=Status.PENDING)
        workflow.reject(rejected, self.admin, "Duplicate")

    def crawl(self, user, limit=600):
        self.login(user)
        seen, queue, problems, exports = set(), ["/"], [], 0
        while queue and len(seen) < limit:
            url = queue.pop(0)
            if url in seen:
                continue
            seen.add(url)
            resp = self.client.get(url)
            if "export=" in url:
                exports += 1
                if resp.status_code != 200 or not resp.content:
                    problems.append((url, resp.status_code))
                continue
            if resp.status_code not in (200, 302):
                problems.append((url, resp.status_code))
                continue
            if resp.status_code == 302 or "text/html" not in resp.get("Content-Type", ""):
                continue
            for link in HREF.findall(resp.content.decode()):
                link = link.replace("&amp;", "&")
                if link.startswith("?"):
                    link = urlsplit(url).path + link
                path = urlsplit(link).path
                if link not in seen and not path.startswith(SKIP) and not link.startswith("//"):
                    queue.append(link)
        return seen, problems, exports

    def test_admin_can_open_every_link(self):
        seen, problems, exports = self.crawl(self.admin)
        self.assertEqual(problems, [])
        self.assertGreater(len(seen), 60)
        self.assertGreater(exports, 20)

    def test_entry_user_never_sees_forbidden_links(self):
        seen, problems, _ = self.crawl(self.entry)
        self.assertEqual(problems, [])
        self.assertFalse(any(u.startswith(("/settings/", "/audit/", "/accounts/users/")) for u in seen))

    def test_viewer_never_sees_forbidden_links(self):
        seen, problems, _ = self.crawl(self.viewer)
        self.assertEqual(problems, [])
        self.assertFalse(any(u.endswith(("/new/", "/edit/")) for u in seen))
