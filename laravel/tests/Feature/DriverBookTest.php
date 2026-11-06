<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverSalaryAdjustment;
use App\Services\DriverLedger;
use App\Services\Finance;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DriverBookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->driver->update(['monthly_salary' => '15000']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function start(string $month = '2026-11', string $opening = '0', ?string $end = null): void
    {
        $this->login($this->admin)->post(route('driver-book.setup', $this->driver), ['ledger_start_month' => $month, 'opening_balance' => $opening, 'ledger_end_month' => $end])
            ->assertRedirect(route('driver-book.show', $this->driver))->assertSessionHasNoErrors();
        $this->driver->refresh();
    }

    private function pay(string $amount, string $day, string $type = 'SALARY', string $status = 'APPROVED')
    {
        return $this->expense($amount, 'DRIVER', vehicle: null, driver: $this->driver, dtype: $type, day: $day, user: $status === 'PENDING' ? $this->entry : null, status: $status);
    }

    private function balance(): int
    {
        return DriverLedger::build($this->driver->fresh())['balance'];
    }

    public function test_salary_minus_paid_gives_final_amount(): void
    {
        $this->start();
        $this->pay('5000', '2026-11-03');
        $this->assertSame(1000000, $this->balance());

        $this->get(route('driver-book.show', $this->driver))->assertOk()->assertSee('Final to pay today')->assertSee('10,000.00')
            ->assertSee('03 Nov 2026')->assertSee('5,000.00')->assertSee('Salary for November 2026');
        $this->get(route('driver-book.index'))->assertOk()->assertSee('Ramesh')->assertSee('10,000.00');
    }

    public function test_balance_carries_forward_and_payment_covers_previous_month(): void
    {
        $this->start('2026-09', '2000');
        $this->pay('10000', '2026-09-20');
        $this->pay('7000', '2026-10-05');
        $this->pay('18000', '2026-11-05');
        $months = DriverLedger::build($this->driver->fresh())['months'];
        $this->assertSame([200000, 700000], [$months['2026-09']['opening'], $months['2026-09']['closing']]);
        $this->assertSame([700000, 1500000], [$months['2026-10']['opening'], $months['2026-10']['closing']]);
        $this->assertSame([1500000, 1200000], [$months['2026-11']['opening'], $months['2026-11']['closing']]);
        $this->assertSame(1200000, $this->balance());

        $this->get(route('driver-book.show', ['driver' => $this->driver, 'month' => '2026-10']))->assertSee('October 2026')->assertSee('05 Oct 2026')->assertDontSee('05 Nov 2026');
        $this->get(route('driver-book.show', ['driver' => $this->driver, 'month' => 'all']))->assertSee('20 Sep 2026')->assertSee('05 Nov 2026');
    }

    public function test_only_approved_salary_and_advance_reduce_balance(): void
    {
        $this->start();
        $this->pay('1000', '2026-11-02', 'ADVANCE');
        $this->pay('300', '2026-11-03', 'BATA');
        $this->pay('200', '2026-11-03', 'MEDICAL');
        $this->pay('4000', '2026-11-04', 'SALARY', 'PENDING');
        $rejected = $this->pay('999', '2026-11-05', 'SALARY', 'PENDING');
        \App\Services\Workflow::reject($rejected, $this->admin, 'No');

        $this->assertSame(1400000, $this->balance());
        $month = DriverLedger::build($this->driver->fresh())['months']['2026-11'];
        $this->assertSame([100000, 50000, 400000], [$month['out'], $month['other'], $month['pending']]);
        $this->get(route('driver-book.show', $this->driver))->assertSee('Pending approval, not counted')->assertSee('Medical')->assertDontSee('999.00');
    }

    public function test_payment_before_start_month_is_left_out(): void
    {
        $this->pay('9000', '2026-10-31');
        $this->start();
        $this->assertSame(1500000, $this->balance());
    }

    public function test_salary_change_keeps_old_months(): void
    {
        $this->start('2026-09');
        $this->login($this->admin)->post(route('drivers.edit', $this->driver), ['name' => 'Ramesh', 'monthly_salary' => '20000', 'daily_allowance' => '0', 'status' => 'ACTIVE'])->assertRedirect();
        $months = DriverLedger::build($this->driver->fresh())['months'];
        $this->assertSame([1500000, 1500000, 2000000], [$months['2026-09']['in'], $months['2026-10']['in'], $months['2026-11']['in']]);

        // A second change in the same month replaces the figure.
        $this->post(route('drivers.edit', $this->driver), ['name' => 'Ramesh', 'monthly_salary' => '21000', 'daily_allowance' => '0', 'status' => 'ACTIVE']);
        $months = DriverLedger::build($this->driver->fresh())['months'];
        $this->assertSame([1500000, 2100000], [$months['2026-10']['in'], $months['2026-11']['in']]);
    }

    public function test_salary_corrected_before_setup_applies_to_all_months(): void
    {
        $this->driver->update(['monthly_salary' => '16000']);
        $this->start('2026-10');
        $months = DriverLedger::build($this->driver->fresh())['months'];
        $this->assertSame([1600000, 1600000], [$months['2026-10']['in'], $months['2026-11']['in']]);
    }

    public function test_inactive_driver_stops_salary_and_final_settlement_works(): void
    {
        $this->start('2026-09');
        $this->driver->update(['status' => 'INACTIVE']);
        $this->assertSame('2026-11-01', $this->driver->fresh()->ledger_end_month->toDateString());

        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->assertSame(4500000, $this->balance());
        $this->pay('45000', '2027-02-05');
        $this->assertSame(0, $this->balance());
        $this->get(route('driver-book.show', $this->driver))->assertOk()->assertSee('Salary stops after');
    }

    public function test_overpayment_shows_as_paid_extra(): void
    {
        $this->start();
        $this->pay('16000', '2026-11-05');
        $this->assertSame(-100000, $this->balance());
        $this->get(route('driver-book.show', $this->driver))->assertSee('Paid extra to driver')->assertSee('1,000.00');
        $this->get(route('driver-book.index'))->assertSee('paid extra');
    }

    public function test_adjustments_change_balance_with_audit_and_delete(): void
    {
        $this->start();
        $this->post(route('driver-book.adjustments', $this->driver), ['date' => '2026-11-10', 'kind' => 'DEDUCT', 'amount' => '500', 'reason' => '1 day absent'])->assertRedirect();
        $this->post(route('driver-book.adjustments', $this->driver), ['date' => '2026-11-11', 'kind' => 'ADD', 'amount' => '1000', 'reason' => 'Diwali bonus'])->assertRedirect();
        $this->assertSame(1550000, $this->balance());
        $this->get(route('driver-book.show', $this->driver))->assertSee('1 day absent')->assertSee('Diwali bonus');
        $this->assertSame(2, AuditLog::where('model_name', 'Driver salary adjustment')->where('action', 'CREATE')->count());

        $adj = DriverSalaryAdjustment::where('kind', 'DEDUCT')->sole();
        $this->get(route('driver-book.adjustments.delete', [$this->driver, $adj]))->assertOk();
        $this->post(route('driver-book.adjustments.delete', [$this->driver, $adj]))->assertRedirect();
        $this->assertSame(1600000, $this->balance());
        $this->assertTrue(AuditLog::where('model_name', 'Driver salary adjustment')->where('action', 'DELETE')->exists());
    }

    public function test_adjustment_validation_and_wrong_driver(): void
    {
        $this->start();
        $url = route('driver-book.adjustments', $this->driver);
        $this->post($url, ['date' => '2026-10-31', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x'])->assertSessionHasErrors('date', errorBag: 'adjustment');
        $this->post($url, ['date' => '2026-11-13', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x'])->assertSessionHasErrors('date', errorBag: 'adjustment');
        $this->post($url, ['date' => '2026-11-10', 'kind' => 'NOPE', 'amount' => '0', 'reason' => ''])->assertSessionHasErrors(['kind', 'amount', 'reason'], errorBag: 'adjustment');
        $this->assertSame(0, DriverSalaryAdjustment::count());

        $adj = new DriverSalaryAdjustment(['driver_id' => $this->driver->id, 'date' => '2026-11-10', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x']);
        $adj->save();
        $other = Driver::create(['name' => 'Other']);
        $this->post(route('driver-book.adjustments.delete', [$other, $adj]))->assertNotFound();
        $this->post(route('driver-book.adjustments', $other), ['date' => '2026-11-10', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x'])->assertNotFound();
    }

    public function test_setup_validation_and_not_set_up_page(): void
    {
        $this->login($this->admin);
        $this->get(route('driver-book.show', $this->driver))->assertOk()->assertSee('has not been set up')->assertSee('Save settings')->assertDontSee('Record payment');
        $this->get(route('driver-book.index'))->assertOk()->assertSee('Set up');
        $url = route('driver-book.setup', $this->driver);
        $this->post($url, ['ledger_start_month' => '2026-12', 'opening_balance' => '0'])->assertSessionHasErrors('ledger_start_month');
        $this->post($url, ['ledger_start_month' => 'x', 'opening_balance' => '0'])->assertSessionHasErrors('ledger_start_month');
        $this->post($url, ['ledger_start_month' => '2026-11', 'opening_balance' => ''])->assertSessionHasErrors('opening_balance');
        $this->post($url, ['ledger_start_month' => '2026-11', 'opening_balance' => '0', 'ledger_end_month' => '2026-10'])->assertSessionHasErrors('ledger_end_month');
        $this->assertNull(DriverLedger::build($this->driver->fresh()));

        // Negative opening: the driver had been paid extra.
        $this->start('2026-11', '-3000');
        $this->assertSame(1200000, $this->balance());
        $this->assertTrue(AuditLog::where('model_name', 'Driver')->where('action', 'UPDATE')->exists());
    }

    public function test_business_figures_are_unchanged_by_salary_owed_and_adjustments(): void
    {
        $this->pay('5000', '2026-11-03');
        $summary = Finance::financialSummary();
        $book = Finance::cashBook();
        $this->start('2026-01', '50000');
        $this->post(route('driver-book.adjustments', $this->driver), ['date' => '2026-11-10', 'kind' => 'ADD', 'amount' => '9000', 'reason' => 'Bonus']);
        $this->assertSame($summary, Finance::financialSummary());
        $this->assertSame($book['closing'], Finance::cashBook()['closing']);
    }

    public function test_access(): void
    {
        $this->start();
        $this->login($this->entry)->get(route('driver-book.index'))->assertForbidden();
        $this->get(route('driver-book.show', $this->driver))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee('Driver Cash Book');

        $this->login($this->viewer)->get(route('driver-book.index'))->assertOk();
        $this->get(route('driver-book.show', $this->driver))->assertOk()->assertDontSee('Save settings')->assertDontSee('Save adjustment')->assertDontSee('Record payment');
        $this->post(route('driver-book.setup', $this->driver), ['ledger_start_month' => '2026-01', 'opening_balance' => '1'])->assertForbidden();
        $this->post(route('driver-book.adjustments', $this->driver), ['date' => '2026-11-10', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x'])->assertForbidden();
        $this->assertSame('2026-11-01', $this->driver->fresh()->ledger_start_month->toDateString());
    }

    public function test_record_payment_opens_form_with_driver_and_salary_selected(): void
    {
        $this->start();
        $this->get(route('driver-book.show', $this->driver))->assertSee('Record payment');
        $html = $this->get(route('driver-expenses.create', ['driver' => $this->driver->id, 'driver_expense_type' => 'SALARY']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="SALARY"[^>]*selected/', $html);
    }

    public function test_exports(): void
    {
        $this->start();
        $this->pay('5000', '2026-11-03');
        $csv = $this->get(route('driver-book.show', ['driver' => $this->driver, 'export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('03-11-2026', $csv);
        $this->assertStringContainsString('Ramesh', $csv);
        $this->get(route('driver-book.show', ['driver' => $this->driver, 'export' => 'pdf', 'month' => 'all']))->assertOk();
        $this->get(route('driver-book.show', ['driver' => $this->driver, 'export' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('Ramesh', $this->get(route('driver-book.index', ['export' => 'csv']))->assertOk()->getContent());
    }

    public function test_driver_with_adjustment_cannot_be_deleted(): void
    {
        $this->start();
        $this->post(route('driver-book.adjustments', $this->driver), ['date' => '2026-11-10', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x']);
        $this->post(route('drivers.delete', $this->driver))->assertSessionHas('error');
        $this->assertNotNull($this->driver->fresh());
    }

    public function test_demo_remove_clears_adjustments(): void
    {
        (new DriverSalaryAdjustment(['driver_id' => $this->driver->id, 'date' => '2026-11-10', 'kind' => 'ADD', 'amount' => '1', 'reason' => 'x']))->save();
        $this->artisan('erp:demo-data --remove')->expectsConfirmation(
            'Delete ALL vehicles, drivers, attendance, customers, income, expenses, payments and loans in '.config('database.connections.mysql.database').'?', 'yes'
        )->assertSuccessful();
        $this->assertSame(0, Driver::count());
        $this->assertSame(0, DriverSalaryAdjustment::count());
    }
}
