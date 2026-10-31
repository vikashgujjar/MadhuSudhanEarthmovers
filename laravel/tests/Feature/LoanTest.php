<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Services\Finance;
use Tests\TestCase;

class LoanTest extends TestCase
{
    private function lend(array $attrs = []): Loan
    {
        $this->login($this->admin)->post(route('loans.create'), $attrs + [
            'borrower_name' => 'Suresh Kumar', 'phone' => '9876543210', 'amount' => '10000',
            'date' => '2026-05-01', 'due_date' => '2026-06-01', 'mode' => 'CASH',
        ])->assertRedirect();

        return Loan::latest('id')->firstOrFail();
    }

    public function test_admin_records_loan_and_partial_repayments(): void
    {
        $loan = $this->lend();
        $this->assertSame('10000.00', $loan->amount);
        $this->assertTrue(AuditLog::where('model_name', 'Money lent')->where('action', 'CREATE')->exists());

        $this->post(route('loans.repay', $loan), ['amount' => '4000', 'date' => '2026-05-10', 'mode' => 'UPI'])
            ->assertRedirect(route('loans.show', $loan))->assertSessionHas('success');
        $this->post(route('loans.repay', $loan), ['amount' => '2500.50', 'date' => '2026-05-20', 'mode' => 'CASH']);
        $loan->refresh();
        $this->assertSame('6500.50', $loan->repaidAmount());
        $this->assertSame('3499.50', $loan->balance());

        $this->get(route('loans.show', $loan))->assertOk()->assertSee('Suresh Kumar')->assertSee('3,499.50');
        $this->get(route('loans.index'))->assertOk()->assertSee('Suresh Kumar');
    }

    public function test_repayment_cannot_exceed_balance_or_predate_loan(): void
    {
        $loan = $this->lend();
        $this->post(route('loans.repay', $loan), ['amount' => '10000.01', 'date' => '2026-05-10', 'mode' => 'CASH'])
            ->assertSessionHasErrors('amount', null, 'repayment');
        $this->post(route('loans.repay', $loan), ['amount' => '100', 'date' => '2026-04-30', 'mode' => 'CASH'])
            ->assertSessionHasErrors('date', null, 'repayment');
        $this->post(route('loans.repay', $loan), ['amount' => '0', 'date' => '2026-05-10', 'mode' => 'CASH'])
            ->assertSessionHasErrors('amount', null, 'repayment');
        $this->assertSame(0, $loan->repayments()->count());

        $this->post(route('loans.repay', $loan), ['amount' => '10000', 'date' => '2026-05-10', 'mode' => 'CASH']);
        $this->assertSame('SETTLED', $loan->fresh()->status());
        $this->get(route('loans.show', $loan))->assertSee('fully repaid')->assertDontSee('Save repayment');
    }

    public function test_loan_validation(): void
    {
        $this->login($this->admin)->post(route('loans.create'), [
            'borrower_name' => '', 'amount' => '0', 'date' => '2026-05-01', 'due_date' => '2026-04-01', 'mode' => 'CASH', 'phone' => '123',
        ])->assertSessionHasErrors(['borrower_name', 'due_date', 'phone']);
        $this->post(route('loans.create'), [
            'borrower_name' => 'X', 'amount' => '0', 'date' => '2026-05-01', 'mode' => 'CASH',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, Loan::count());

        $loan = $this->lend();
        $this->post(route('loans.repay', $loan), ['amount' => '6000', 'date' => '2026-05-10', 'mode' => 'CASH']);
        $this->post(route('loans.edit', $loan), ['borrower_name' => 'Suresh Kumar', 'amount' => '5000', 'date' => '2026-05-01', 'mode' => 'CASH'])
            ->assertSessionHasErrors('amount');
        $this->post(route('loans.edit', $loan), ['borrower_name' => 'Suresh Kumar', 'amount' => '12000', 'date' => '2026-05-01', 'mode' => 'CASH'])
            ->assertSessionHasNoErrors();
        $this->assertSame('6000.00', $loan->fresh()->balance());
    }

    public function test_overdue_status_and_dashboard_reminder(): void
    {
        $overdue = $this->lend(['borrower_name' => 'Late Larry', 'date' => now()->subDays(40)->toDateString(), 'due_date' => now()->subDays(5)->toDateString()]);
        $soon = $this->lend(['borrower_name' => 'Soon Sunil', 'date' => now()->subDays(3)->toDateString(), 'due_date' => now()->addDays(3)->toDateString()]);
        $later = $this->lend(['borrower_name' => 'Later Lata', 'date' => now()->toDateString(), 'due_date' => now()->addDays(60)->toDateString()]);
        $this->assertSame('OVERDUE', $overdue->status());
        $this->assertSame('OPEN', $soon->status());
        $this->assertSame(1, Loan::overdue()->count());

        $this->get(route('loans.index')); // consume the "saved" message
        $this->get(route('dashboard'))->assertOk()->assertSee('Money to collect')
            ->assertSee('Late Larry')->assertSee('5 days overdue')->assertSee('Soon Sunil')->assertDontSee('Later Lata');
        $this->get(route('loans.index', ['status' => 'overdue']))->assertSee('Late Larry')->assertDontSee('Soon Sunil');
    }

    public function test_cash_book_includes_loans_and_repayments(): void
    {
        $before = Finance::cashBook('2026-05-01', '2026-05-31')['closing'];
        $loan = $this->lend();
        $this->post(route('loans.repay', $loan), ['amount' => '3000', 'date' => '2026-05-15', 'mode' => 'CASH']);
        $book = Finance::cashBook('2026-05-01', '2026-05-31');
        $this->assertSame(\App\Support\Money::sub($before, '7000.00'), $book['closing']);
        $particulars = array_column($book['entries'], 'particulars');
        $this->assertContains('Money lent to Suresh Kumar', $particulars);
        $this->assertContains('Loan repaid by Suresh Kumar', $particulars);
        // Opening balance of the next month carries the net effect.
        $this->assertSame($book['closing'], Finance::cashBook('2026-06-01', '2026-06-30')['opening']);
    }

    public function test_only_admin_can_use_money_lent(): void
    {
        $loan = $this->lend();
        foreach ([$this->entry, $this->viewer] as $user) {
            $this->login($user)->get(route('loans.index'))->assertForbidden();
            $this->get(route('loans.show', $loan))->assertForbidden();
            $this->post(route('loans.repay', $loan), ['amount' => '1', 'date' => '2026-05-10', 'mode' => 'CASH'])->assertForbidden();
            $this->get(route('dashboard'))->assertDontSee('Money Lent');
        }
        $this->assertSame(0, $loan->repayments()->count());
    }

    public function test_delete_rules_and_export(): void
    {
        $loan = $this->lend();
        $this->post(route('loans.repay', $loan), ['amount' => '1000', 'date' => '2026-05-10', 'mode' => 'CASH']);
        $this->post(route('loans.delete', $loan))->assertRedirect(route('loans.show', $loan))->assertSessionHas('error');

        $csv = $this->get(route('loans.index', ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('Suresh Kumar', $csv);
        $this->get(route('loans.index', ['export' => 'pdf']))->assertOk();

        $repayment = $loan->repayments()->sole();
        $this->post(route('loans.repayments.delete', [$loan, $repayment]))->assertRedirect(route('loans.show', $loan));
        $this->post(route('loans.delete', $loan))->assertRedirect(route('loans.index'));
        $this->assertSame(0, Loan::count());
        $this->assertTrue(AuditLog::where('model_name', 'Loan repayment')->where('action', 'DELETE')->exists());
    }
}
