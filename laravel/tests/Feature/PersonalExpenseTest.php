<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PersonalExpense;
use App\Models\User;
use App\Services\Finance;
use Tests\TestCase;

class PersonalExpenseTest extends TestCase
{
    private function spend(array $attrs = [], ?User $as = null): PersonalExpense
    {
        $this->login($as ?? $this->admin)->post(route('personal-expenses.create'), $attrs + [
            'date' => now()->toDateString(), 'category' => 'MEDICAL', 'amount' => '2500.50', 'mode' => 'UPI',
            'paid_to' => 'City Hospital', 'description' => 'Check-up', 'notes' => '',
        ])->assertRedirect();

        return PersonalExpense::latest('id')->firstOrFail();
    }

    public function test_admin_records_and_edits_personal_expense_with_audit(): void
    {
        $e = $this->spend();
        $this->assertSame(['MEDICAL', '2500.50', 'UPI', $this->admin->id], [$e->category, $e->amount, $e->mode, (int) $e->created_by]);
        $this->assertTrue(AuditLog::where('model_name', 'Personal expense')->where('action', 'CREATE')->exists());

        $this->post(route('personal-expenses.edit', $e), ['date' => $e->date->toDateString(), 'category' => 'MEDICAL',
            'amount' => '3000', 'mode' => 'CASH', 'paid_to' => 'City Hospital'])->assertRedirect(route('personal-expenses.show', $e));
        $this->assertSame('3000.00', $e->fresh()->amount);
        $this->assertTrue(AuditLog::where('model_name', 'Personal expense')->where('action', 'UPDATE')->exists());

        $this->get(route('personal-expenses.show', $e))->assertOk()->assertSee('City Hospital')->assertSee('3,000.00')->assertSee('Change history');
        $this->get(route('personal-expenses.index'))->assertOk()->assertSee('City Hospital')->assertSee('Medical · 3,000');
    }

    public function test_save_and_add_another_returns_to_form(): void
    {
        $this->login($this->admin)->post(route('personal-expenses.create'), ['date' => now()->toDateString(), 'category' => 'BILLS',
            'amount' => '900', 'mode' => 'CASH', 'action' => 'save_add'])->assertRedirect(route('personal-expenses.create'));
        $this->assertSame(1, PersonalExpense::count());
    }

    public function test_validation(): void
    {
        $this->login($this->admin)->post(route('personal-expenses.create'), ['date' => now()->addDay()->toDateString(),
            'category' => 'NOPE', 'amount' => '-5', 'mode' => 'GOLD'])->assertSessionHasErrors(['date', 'category', 'amount', 'mode']);
        $this->post(route('personal-expenses.create'), ['date' => now()->toDateString(), 'category' => 'OTHER', 'amount' => '0', 'mode' => 'CASH'])
            ->assertSessionHasErrors(['amount' => 'Enter an amount greater than zero.']);
        $this->post(route('personal-expenses.create'), [])->assertSessionHasErrors(['date', 'category', 'amount', 'mode']);
        $this->assertSame(0, PersonalExpense::count());
    }

    public function test_never_counted_in_business_figures(): void
    {
        $this->expense('500');
        $summary = Finance::financialSummary();
        $book = Finance::cashBook();
        $this->spend(['amount' => '99999']);
        $this->assertSame($summary, Finance::financialSummary());
        $this->assertSame($book['closing'], Finance::cashBook()['closing']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('99,999');
    }

    public function test_access_admin_and_chosen_users_only(): void
    {
        $e = $this->spend();
        foreach ([$this->entry, $this->viewer] as $user) {
            $this->login($user)->get(route('personal-expenses.index'))->assertForbidden();
            $this->get(route('personal-expenses.show', $e))->assertForbidden();
            $this->post(route('personal-expenses.create'), ['date' => now()->toDateString(), 'category' => 'OTHER', 'amount' => '1', 'mode' => 'CASH'])->assertForbidden();
            $this->get(route('dashboard'))->assertDontSee('Personal Expenses');
        }

        // An entry user given the permission can view, add and edit, but not delete.
        $this->entry->update(['can_manage_personal_expenses' => true]);
        $this->login($this->entry->fresh())->get(route('dashboard'))->assertSee('Personal Expenses');
        $this->get(route('personal-expenses.index'))->assertOk()->assertSee('City Hospital');
        $mine = $this->spend(['paid_to' => 'School'], $this->entry->fresh());
        $this->assertSame($this->entry->id, (int) $mine->created_by);
        $this->get(route('personal-expenses.show', $e))->assertOk()->assertDontSee(route('personal-expenses.delete', $e));
        $this->post(route('personal-expenses.delete', $e))->assertForbidden();

        // A viewer can never hold it, even if the flag is set.
        $this->viewer->update(['can_manage_personal_expenses' => true]);
        $this->assertFalse($this->viewer->fresh()->hasCapability('manage_personal_expenses'));
        $this->assertSame(2, PersonalExpense::count());
    }

    public function test_filters_totals_and_export(): void
    {
        $this->spend(['category' => 'HOUSEHOLD', 'amount' => '1200', 'paid_to' => 'Kirana Store']);
        $this->spend(['category' => 'EDUCATION', 'amount' => '15000', 'paid_to' => 'Sunrise School']);
        $this->spend(['category' => 'HOUSEHOLD', 'amount' => '800', 'paid_to' => 'Old Market', 'date' => now()->subYears(2)->toDateString()]);

        $this->get(route('personal-expenses.index'))->assertSee('Kirana Store')->assertSee('Sunrise School')->assertDontSee('Old Market')
            ->assertSee('16,200.00');
        $this->get(route('personal-expenses.index', ['period' => 'all', 'category' => 'HOUSEHOLD']))
            ->assertSee('Kirana Store')->assertSee('Old Market')->assertDontSee('Sunrise School')->assertSee('2,000.00');
        $this->get(route('personal-expenses.index', ['period' => 'all', 'q' => 'sunrise']))->assertSee('Sunrise School')->assertDontSee('Kirana Store');

        $csv = $this->get(route('personal-expenses.index', ['period' => 'all', 'export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('Sunrise School', $csv);
        $this->assertStringContainsString('Old Market', $csv);
        $this->get(route('personal-expenses.index', ['export' => 'pdf']))->assertOk();
        $this->get(route('personal-expenses.index', ['export' => 'xlsx']))->assertOk();
    }

    public function test_admin_delete_keeps_audit_copy(): void
    {
        $e = $this->spend();
        $this->get(route('personal-expenses.delete', $e))->assertOk();
        $this->post(route('personal-expenses.delete', $e))->assertRedirect(route('personal-expenses.index'));
        $this->assertSame(0, PersonalExpense::count());
        $log = AuditLog::where('model_name', 'Personal expense')->where('action', 'DELETE')->sole();
        $this->assertStringContainsString('City Hospital', json_encode($log->changes));
    }
}
