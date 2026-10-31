"""Financial calculation rules: billing, double counting, balances."""
from datetime import date

from django.core.exceptions import ValidationError

from apps.finance import services as fin
from apps.finance.models import Expense, Income, Status, outstanding_for

from .helpers import D, ERPTestCase


class BillingTests(ERPTestCase):
    def test_hourly_amount_is_quantity_times_rate(self):
        inc = self.income(billing=Income.BillingType.HOURLY, qty=D("7.5"), rate=D("1200"))
        self.assertEqual(inc.amount, D("9000.00"))

    def test_daily_amount_is_days_times_rate(self):
        inc = self.income(billing=Income.BillingType.DAILY, qty=D("3"), rate=D("9000"))
        self.assertEqual(inc.amount, D("27000.00"))

    def test_hourly_ignores_submitted_amount(self):
        inc = self.income(amount="1", billing=Income.BillingType.HOURLY, qty=D("2"), rate=D("1000"))
        self.assertEqual(inc.amount, D("2000.00"))

    def test_fixed_and_manual_use_entered_amount_and_clear_qty(self):
        inc = self.income(amount="45000", billing=Income.BillingType.FIXED, qty=D("3"), rate=D("10"))
        self.assertEqual(inc.amount, D("45000.00"))
        self.assertIsNone(inc.quantity)
        self.assertIsNone(inc.rate)

    def test_hourly_requires_quantity_and_rate(self):
        with self.assertRaises(ValidationError) as ctx:
            self.income(billing=Income.BillingType.HOURLY, qty=None, rate=None)
        self.assertIn("quantity", ctx.exception.message_dict)
        self.assertIn("rate", ctx.exception.message_dict)

    def test_manual_requires_positive_amount(self):
        with self.assertRaises(ValidationError):
            self.income(amount="0", billing=Income.BillingType.MANUAL)

    def test_vehicle_must_match_selected_category(self):
        inc = Income(date=date(2026, 5, 1), vehicle_category="TRACTOR", vehicle=self.jcb1, customer=self.cust,
                     billing_type=Income.BillingType.MANUAL, amount=D("100"), created_by=self.admin)
        with self.assertRaises(ValidationError) as ctx:
            inc.full_clean()
        self.assertIn("vehicle", ctx.exception.message_dict)

    def test_invoice_number_generated_from_settings_prefix(self):
        inc = self.income(amount="100")
        self.assertEqual(inc.number, f"ME-INV-{inc.pk:05d}")

    def test_amount_rounding_is_half_up_to_paise(self):
        inc = self.income(billing=Income.BillingType.HOURLY, qty=D("0.33"), rate=D("1000.05"))
        self.assertEqual(inc.amount, D("330.02"))


class ExpenseRuleTests(ERPTestCase):
    def test_vehicle_expenses_require_vehicle(self):
        for cat in (Expense.Category.DIESEL, Expense.Category.MAINTENANCE, Expense.Category.REPAIR,
                    Expense.Category.SPARE_PARTS):
            with self.assertRaises(ValidationError, msg=cat):
                self.expense("100", category=cat, vehicle=None)

    def test_driver_expense_requires_driver_and_type(self):
        with self.assertRaises(ValidationError) as ctx:
            self.expense("100", category=Expense.Category.DRIVER, vehicle=None)
        self.assertIn("driver", ctx.exception.message_dict)
        self.assertIn("driver_expense_type", ctx.exception.message_dict)

    def test_other_expense_can_be_general(self):
        exp = self.expense("500", category=Expense.Category.OTHER, vehicle=None)
        self.assertIsNone(exp.vehicle)


class NoDoubleCountingTests(ERPTestCase):
    """A driver expense linked to a JCB must appear once in total expenses."""

    def setUp(self):
        self.expense("1000", category=Expense.Category.DIESEL, vehicle=self.jcb1)
        self.expense("2500", category=Expense.Category.DRIVER, vehicle=self.jcb1, driver=self.driver,
                     dtype=Expense.DriverExpenseType.SALARY)
        self.expense("300", category=Expense.Category.DRIVER, vehicle=None, driver=self.driver,
                     dtype=Expense.DriverExpenseType.FOOD)
        self.expense("700", category=Expense.Category.REPAIR, vehicle=self.tractor)
        self.expense("400", category=Expense.Category.OTHER, vehicle=None)
        self.income(amount="10000", vehicle=self.jcb1)

    def test_total_expenses_counts_each_row_once(self):
        s = fin.financial_summary()
        self.assertEqual(s["expense_total"], D("4900.00"))  # 1000 + 2500 + 300 + 700 + 400
        self.assertEqual(s["driver_expense_total"], D("2800.00"))
        self.assertEqual(s["net_profit"], D("5100.00"))

    def test_category_and_general_split_reconciles_to_total(self):
        s = fin.financial_summary()
        split = s["categories"]["JCB"]["expense"] + s["categories"]["TRACTOR"]["expense"] + s["general_expense"]
        self.assertEqual(split, s["expense_total"])
        self.assertEqual(s["categories"]["JCB"]["expense"], D("3500.00"))
        self.assertEqual(s["general_expense"], D("700.00"))

    def test_vehicle_profitability_includes_driver_expense_once(self):
        rows = {r["vehicle"].pk: r for r in fin.vehicle_profitability()}
        jcb = rows[self.jcb1.pk]
        self.assertEqual(jcb["expense"], D("3500.00"))
        self.assertEqual(jcb["driver_expense"], D("2500.00"))
        self.assertEqual(jcb["profit"], D("6500.00"))
        total_vehicle_expense = sum(r["expense"] for r in rows.values())
        self.assertEqual(total_vehicle_expense + fin.financial_summary()["general_expense"],
                         fin.financial_summary()["expense_total"])

    def test_expense_breakdown_sums_to_total(self):
        s = fin.financial_summary()
        self.assertEqual(sum(r["amount"] for r in s["expense_by_category"]), s["expense_total"])

    def test_driver_summary(self):
        rows = fin.driver_expense_summary()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["total"], D("2800.00"))
        self.assertEqual(rows[0]["by_type"]["SALARY"], D("2500.00"))

    def test_pending_and_rejected_are_excluded(self):
        self.expense("99999", user=self.entry, status=Status.PENDING)
        self.expense("88888", user=self.entry, status=Status.REJECTED)
        self.assertEqual(fin.financial_summary()["expense_total"], D("4900.00"))


class PaymentAndOutstandingTests(ERPTestCase):
    def setUp(self):
        self.inv = self.income(amount="10000")

    def test_partial_payments_reduce_invoice_balance(self):
        self.payment("4000", income=self.inv)
        self.payment("3000", income=self.inv)
        self.inv.refresh_from_db()
        self.assertEqual(self.inv.amount_received, D("7000.00"))
        self.assertEqual(self.inv.outstanding, D("3000.00"))
        self.assertEqual(self.inv.payment_state, "Partially paid")

    def test_full_payment_marks_paid(self):
        self.payment("10000", income=self.inv)
        self.assertEqual(self.inv.payment_state, "Paid")
        self.assertEqual(self.inv.outstanding, D("0.00"))

    def test_overpayment_against_invoice_rejected(self):
        self.payment("6000", income=self.inv)
        with self.assertRaises(ValidationError) as ctx:
            self.payment("4000.01", income=self.inv)
        self.assertIn("amount", ctx.exception.message_dict)

    def test_pending_payment_reserves_balance_but_is_not_counted(self):
        self.payment("6000", income=self.inv, user=self.entry, status=Status.PENDING)
        self.assertEqual(self.inv.amount_received, D("0.00"))
        with self.assertRaises(ValidationError):
            self.payment("5000", income=self.inv)

    def test_payment_customer_must_match_invoice(self):
        with self.assertRaises(ValidationError) as ctx:
            self.payment("100", income=self.inv, customer=self.cust2)
        self.assertIn("income", ctx.exception.message_dict)

    def test_customer_outstanding_includes_opening_and_advances(self):
        self.payment("2500", income=self.inv)
        self.payment("500", customer=self.cust)  # advance, no invoice
        # 1000 opening + 10000 billed - 3000 received
        self.assertEqual(outstanding_for(self.cust), D("8000.00"))
        rows = {r["customer"].pk: r for r in fin.customer_outstanding_rows()}
        self.assertEqual(rows[self.cust.pk]["balance"], D("8000.00"))
        self.assertEqual(fin.financial_summary()["outstanding_total"], D("8000.00"))

    def test_customer_in_credit_does_not_reduce_others_outstanding(self):
        self.payment("2000", customer=self.cust2)  # cust2 has paid an advance, owes nothing
        self.assertEqual(outstanding_for(self.cust2), D("-2000.00"))
        self.assertEqual(fin.financial_summary()["outstanding_total"], D("11000.00"))

    def test_billed_vs_received_are_distinct(self):
        self.payment("2500", income=self.inv)
        s = fin.financial_summary()
        self.assertEqual(s["income_total"], D("10000.00"))
        self.assertEqual(s["received_total"], D("2500.00"))

    def test_invoice_amount_cannot_drop_below_received(self):
        self.payment("6000", income=self.inv)
        self.inv.amount = D("5000")
        with self.assertRaises(ValidationError):
            self.inv.full_clean()

    def test_aggregation_does_not_multiply_with_many_payments(self):
        inv2 = self.income(amount="5000")
        for _ in range(3):
            self.payment("1000", income=inv2)
        self.payment("1000", income=self.inv)
        row = {r["customer"].pk: r for r in fin.customer_outstanding_rows()}[self.cust.pk]
        self.assertEqual(row["billed"], D("15000.00"))
        self.assertEqual(row["received"], D("4000.00"))


class CashBookTests(ERPTestCase):
    def test_running_balance_and_opening(self):
        from apps.core.models import BusinessSettings

        bs = BusinessSettings.load()
        bs.opening_cash_balance = D("5000")
        bs.save()
        inv = self.income(amount="10000", day=date(2026, 4, 1))
        self.payment("3000", income=inv, day=date(2026, 4, 2))  # before the period
        self.payment("2000", income=inv, day=date(2026, 5, 2))
        self.expense("500", day=date(2026, 4, 3))  # before the period
        self.expense("1500", day=date(2026, 5, 3))
        book = fin.cash_book(date(2026, 5, 1), date(2026, 5, 31))
        self.assertEqual(book.opening, D("7500.00"))  # 5000 + 3000 - 500
        self.assertEqual(book.total_receipts, D("2000.00"))
        self.assertEqual(book.total_payments, D("1500.00"))
        self.assertEqual(book.closing, D("8000.00"))
        self.assertEqual([e.balance for e in book.entries], [D("9500.00"), D("8000.00")])

    def test_billed_but_unpaid_income_not_in_cash_book(self):
        self.income(amount="10000")
        book = fin.cash_book()
        self.assertEqual(book.total_receipts, D("0"))
        self.assertEqual(book.entries, [])
