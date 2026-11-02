<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Income;
use App\Services\Finance;
use App\Support\Money;
use Tests\TestCase;

/** Financial calculation rules: billing, double counting, balances, cash book. */
class CalculationTest extends TestCase
{
    // --- billing -----------------------------------------------------------
    public function test_hourly_amount_is_quantity_times_rate(): void
    {
        $this->assertSame('9000.00', $this->income(billing: 'HOURLY', qty: '7.30', rate: '1200')->amount);
    }

    public function test_hourly_quantity_is_hours_dot_minutes(): void
    {
        $this->assertSame('1800.00', $this->income(billing: 'HOURLY', qty: '1.30', rate: '1200')->amount);
        $this->assertSame('1300.00', $this->income(billing: 'HOURLY', qty: '1.05', rate: '1200')->amount);
        $this->assertSame('750.00', $this->income(billing: 'HOURLY', qty: '0.45', rate: '1000')->amount);
        $this->assertSame('3180.00', $this->income(billing: 'HOURLY', qty: '2.39', rate: '1200')->amount);
        $this->assertSame('3580.00', $this->income(billing: 'HOURLY', qty: '2.59', rate: '1200')->amount);
        $this->assertSame('1 hr 30 min', $this->income(billing: 'HOURLY', qty: '1.30', rate: '1200')->quantityLabel());
        $this->assertSame('2 hr', $this->income(billing: 'HOURLY', qty: '2', rate: '1200')->quantityLabel());
        $this->assertSame('45 min', $this->income(billing: 'HOURLY', qty: '0.45', rate: '1200')->quantityLabel());
        $this->assertSame('1.5 days', $this->income(billing: 'DAILY', qty: '1.5', rate: '9000')->quantityLabel());
    }

    public function test_hourly_minutes_must_be_below_sixty(): void
    {
        $this->assertValidationKeys(fn () => $this->income(billing: 'HOURLY', qty: '1.60', rate: '1200'), ['quantity']);
        $this->assertValidationKeys(fn () => $this->income(billing: 'HOURLY', qty: '1.75', rate: '1200'), ['quantity']);
    }

    public function test_daily_quantity_stays_decimal(): void
    {
        $this->assertSame('13500.00', $this->income(billing: 'DAILY', qty: '1.5', rate: '9000')->amount);
    }

    public function test_daily_amount_is_days_times_rate(): void
    {
        $this->assertSame('27000.00', $this->income(billing: 'DAILY', qty: '3', rate: '9000')->amount);
    }

    public function test_hourly_ignores_submitted_amount(): void
    {
        $this->assertSame('2000.00', $this->income(amount: '1', billing: 'HOURLY', qty: '2', rate: '1000')->amount);
    }

    public function test_fixed_and_manual_use_entered_amount_and_clear_qty(): void
    {
        $inc = $this->income(amount: '45000', billing: 'FIXED', qty: '3', rate: '10');
        $this->assertSame('45000.00', $inc->amount);
        $this->assertNull($inc->quantity);
        $this->assertNull($inc->rate);
    }

    public function test_hourly_requires_quantity_and_rate(): void
    {
        $this->assertValidationKeys(fn () => $this->income(billing: 'HOURLY'), ['quantity', 'rate']);
    }

    public function test_manual_requires_positive_amount(): void
    {
        $this->assertValidationKeys(fn () => $this->income(amount: '0', billing: 'MANUAL'), ['amount']);
    }

    public function test_vehicle_must_match_selected_category(): void
    {
        $inc = new Income(['date' => '2026-05-01', 'vehicle_category' => 'TRACTOR', 'vehicle_id' => $this->jcb1->id,
            'customer_id' => $this->cust->id, 'billing_type' => 'MANUAL', 'amount' => '100']);
        $this->assertArrayHasKey('vehicle_id', $inc->businessErrors());
    }

    public function test_invoice_number_generated_from_settings_prefix(): void
    {
        $inc = $this->income(amount: '100');
        $this->assertSame(sprintf('ME-INV-%05d', $inc->id), $inc->number);
    }

    public function test_amount_rounding_is_half_up_to_paise(): void
    {
        $this->assertSame('330.02', $this->income(billing: 'DAILY', qty: '0.33', rate: '1000.05')->amount);
        // 33 min × 1000.05/hr = 550.0275 → 550.03
        $this->assertSame('550.03', $this->income(billing: 'HOURLY', qty: '0.33', rate: '1000.05')->amount);
    }

    public function test_money_helpers(): void
    {
        $this->assertSame('12,34,567.00', Money::inr('1234567'));
        $this->assertSame('-1,000.50', Money::inr('-1000.5'));
        $this->assertSame('1,235', Money::inr('1234.5', 0));
        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('12.5', Money::percent('125', '1000'));
        $this->assertNull(Money::percent('5', '0'));
    }

    // --- expense rules -----------------------------------------------------
    public function test_vehicle_expenses_require_vehicle(): void
    {
        foreach (['DIESEL', 'MAINTENANCE', 'REPAIR', 'SPARE_PARTS'] as $cat) {
            $this->assertValidationKeys(fn () => $this->expense('100', category: $cat, vehicle: null), ['vehicle_id']);
        }
    }

    public function test_driver_expense_requires_driver_and_type(): void
    {
        $this->assertValidationKeys(fn () => $this->expense('100', category: 'DRIVER', vehicle: null), ['driver_id', 'driver_expense_type']);
    }

    public function test_other_expense_can_be_general(): void
    {
        $this->assertNull($this->expense('500', category: 'OTHER', vehicle: null)->vehicle_id);
    }

    // --- no double counting ------------------------------------------------
    private function doubleCountingFixture(): void
    {
        $this->expense('1000', category: 'DIESEL', vehicle: $this->jcb1);
        $this->expense('2500', category: 'DRIVER', vehicle: $this->jcb1, driver: $this->driver, dtype: 'SALARY');
        $this->expense('300', category: 'DRIVER', vehicle: null, driver: $this->driver, dtype: 'FOOD');
        $this->expense('700', category: 'REPAIR', vehicle: $this->tractor);
        $this->expense('400', category: 'OTHER', vehicle: null);
        $this->income(amount: '10000', vehicle: $this->jcb1);
    }

    public function test_total_expenses_counts_each_row_once(): void
    {
        $this->doubleCountingFixture();
        $s = Finance::financialSummary();
        $this->assertSame('4900.00', $s['expense_total']); // 1000 + 2500 + 300 + 700 + 400
        $this->assertSame('2800.00', $s['driver_expense_total']);
        $this->assertSame('5100.00', $s['net_profit']);
    }

    public function test_category_and_general_split_reconciles_to_total(): void
    {
        $this->doubleCountingFixture();
        $s = Finance::financialSummary();
        $this->assertSame($s['expense_total'], Money::add($s['categories']['JCB']['expense'], $s['categories']['TRACTOR']['expense'], $s['general_expense']));
        $this->assertSame('3500.00', $s['categories']['JCB']['expense']);
        $this->assertSame('700.00', $s['general_expense']);
    }

    public function test_vehicle_profitability_includes_driver_expense_once(): void
    {
        $this->doubleCountingFixture();
        $rows = collect(Finance::vehicleProfitability())->keyBy(fn ($r) => $r['vehicle']->id);
        $jcb = $rows[$this->jcb1->id];
        $this->assertSame('3500.00', $jcb['expense']);
        $this->assertSame('2500.00', $jcb['driver_expense']);
        $this->assertSame('6500.00', $jcb['profit']);
        $this->assertSame('1', (string) $jcb['jobs']);
        $vehicleTotal = Money::sum($rows->pluck('expense'));
        $s = Finance::financialSummary();
        $this->assertSame($s['expense_total'], Money::add($vehicleTotal, $s['general_expense']));
    }

    public function test_expense_breakdown_sums_to_total(): void
    {
        $this->doubleCountingFixture();
        $s = Finance::financialSummary();
        $this->assertSame($s['expense_total'], Money::sum(array_column($s['expense_by_category'], 'amount')));
    }

    public function test_driver_summary(): void
    {
        $this->doubleCountingFixture();
        $rows = Finance::driverExpenseSummary();
        $this->assertCount(1, $rows);
        $this->assertSame('2800.00', $rows[0]['total']);
        $this->assertSame('2500.00', $rows[0]['by_type']['SALARY']);
    }

    public function test_pending_and_rejected_are_excluded(): void
    {
        $this->doubleCountingFixture();
        $this->expense('99999', user: $this->entry, status: 'PENDING');
        $this->expense('88888', user: $this->entry, status: 'REJECTED');
        $this->assertSame('4900.00', Finance::financialSummary()['expense_total']);
    }

    // --- payments & outstanding --------------------------------------------
    public function test_partial_payments_reduce_invoice_balance(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('4000', income: $inv);
        $this->payment('3000', income: $inv);
        $this->assertSame('7000.00', $inv->amountReceived());
        $this->assertSame('3000.00', $inv->outstanding());
        $this->assertSame('Partially paid', $inv->paymentState());
    }

    public function test_full_payment_marks_paid(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('10000', income: $inv);
        $this->assertSame('Paid', $inv->paymentState());
        $this->assertSame('0.00', $inv->outstanding());
    }

    public function test_overpayment_against_invoice_rejected(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('6000', income: $inv);
        $this->assertValidationKeys(fn () => $this->payment('4000.01', income: $inv), ['amount']);
    }

    public function test_pending_payment_reserves_balance_but_is_not_counted(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('6000', income: $inv, user: $this->entry, status: 'PENDING');
        $this->assertSame('0.00', $inv->amountReceived());
        $this->assertValidationKeys(fn () => $this->payment('5000', income: $inv), ['amount']);
    }

    public function test_payment_customer_must_match_invoice(): void
    {
        $inv = $this->income(amount: '10000');
        $this->assertValidationKeys(fn () => $this->payment('100', income: $inv, customer: $this->cust2), ['income_id']);
    }

    public function test_customer_outstanding_includes_opening_and_advances(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('2500', income: $inv);
        $this->payment('500', customer: $this->cust); // advance, no invoice
        // 1000 opening + 10000 billed - 3000 received
        $this->assertSame('8000.00', $this->cust->outstanding());
        $rows = collect(Finance::customerOutstandingRows())->keyBy(fn ($r) => $r['customer']->id);
        $this->assertSame('8000.00', $rows[$this->cust->id]['balance']);
        $this->assertSame('8000.00', Finance::financialSummary()['outstanding_total']);
    }

    public function test_customer_in_credit_does_not_reduce_others_outstanding(): void
    {
        $this->income(amount: '10000');
        $this->payment('2000', customer: $this->cust2);
        $this->assertSame('-2000.00', $this->cust2->outstanding());
        $this->assertSame('11000.00', Finance::financialSummary()['outstanding_total']);
    }

    public function test_billed_vs_received_are_distinct(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('2500', income: $inv);
        $s = Finance::financialSummary();
        $this->assertSame('10000.00', $s['income_total']);
        $this->assertSame('2500.00', $s['received_total']);
    }

    public function test_invoice_amount_cannot_drop_below_received(): void
    {
        $inv = $this->income(amount: '10000');
        $this->payment('6000', income: $inv);
        $inv->amount = '5000';
        $this->assertArrayHasKey('amount', $inv->businessErrors());
    }

    public function test_aggregation_does_not_multiply_with_many_payments(): void
    {
        $inv = $this->income(amount: '10000');
        $inv2 = $this->income(amount: '5000');
        foreach (range(1, 3) as $_) {
            $this->payment('1000', income: $inv2);
        }
        $this->payment('1000', income: $inv);
        $row = collect(Finance::customerOutstandingRows())->firstWhere(fn ($r) => $r['customer']->id === $this->cust->id);
        $this->assertSame('15000.00', $row['billed']);
        $this->assertSame('4000.00', $row['received']);
    }

    // --- cash book -----------------------------------------------------------
    public function test_running_balance_and_opening(): void
    {
        $bs = BusinessSetting::current();
        $bs->opening_cash_balance = '5000';
        $bs->save();
        $inv = $this->income(amount: '10000', day: '2026-04-01');
        $this->payment('3000', income: $inv, day: '2026-04-02'); // before the period
        $this->payment('2000', income: $inv, day: '2026-05-02');
        $this->expense('500', day: '2026-04-03'); // before the period
        $this->expense('1500', day: '2026-05-03');
        $book = Finance::cashBook('2026-05-01', '2026-05-31');
        $this->assertSame('7500.00', $book['opening']); // 5000 + 3000 - 500
        $this->assertSame('2000.00', $book['total_receipts']);
        $this->assertSame('1500.00', $book['total_payments']);
        $this->assertSame('8000.00', $book['closing']);
        $this->assertSame(['9500.00', '8000.00'], array_column($book['entries'], 'balance'));
    }

    public function test_billed_but_unpaid_income_not_in_cash_book(): void
    {
        $this->income(amount: '10000');
        $book = Finance::cashBook();
        $this->assertSame('0.00', $book['total_receipts']);
        $this->assertSame([], $book['entries']);
    }
}
