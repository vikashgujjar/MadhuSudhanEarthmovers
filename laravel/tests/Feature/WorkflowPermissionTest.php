<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Finance;
use App\Services\Workflow;
use App\Services\WorkflowException;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

/** Approval workflow, server-side permissions and form validation. */
class WorkflowPermissionTest extends TestCase
{
    private function incomePost(Vehicle $vehicle, $customer, array $extra = []): array
    {
        return array_merge([
            'date' => '2026-05-10', 'vehicle_category' => $vehicle->category, 'vehicle' => $vehicle->id, 'customer' => $customer->id,
            'billing_type' => 'HOURLY', 'quantity' => '5', 'rate' => '1200', 'amount' => '', 'driver' => '',
            'work_location' => 'Site A', 'description' => '', 'received_now' => '', 'payment_mode' => 'CASH', 'payment_reference' => '',
        ], $extra);
    }

    // --- workflow ------------------------------------------------------------
    public function test_entry_user_income_is_pending_and_not_counted(): void
    {
        $resp = $this->login($this->entry)->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust));
        $inc = Income::sole();
        $resp->assertRedirect($inc->url());
        $this->assertSame('PENDING', $inc->status);
        $this->assertSame('6000.00', $inc->amount);
        $this->assertSame($this->entry->id, (int) $inc->created_by);
        $this->assertSame('0.00', Finance::financialSummary()['income_total']);
    }

    public function test_admin_income_is_approved_immediately(): void
    {
        $this->login($this->admin)->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust));
        $inc = Income::sole();
        $this->assertSame('APPROVED', $inc->status);
        $this->assertSame($this->admin->id, (int) $inc->approved_by);
    }

    public function test_approval_not_required_setting(): void
    {
        $bs = BusinessSetting::current();
        $bs->require_approval = false;
        $bs->save();
        $this->login($this->entry)->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust));
        $this->assertSame('APPROVED', Income::sole()->status);
    }

    public function test_initial_payment_recorded_and_approved_with_invoice(): void
    {
        $this->login($this->entry)->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust, ['received_now' => '2500', 'payment_mode' => 'UPI']));
        $inc = Income::sole();
        $pay = Payment::sole();
        $this->assertSame([$inc->id, '2500.00', true, 'PENDING', 'UPI'], [(int) $pay->income_id, $pay->amount, $pay->is_initial, $pay->status, $pay->mode]);
        $this->login($this->admin)->post(route('approvals.approve', ['income', $inc->id]));
        $this->assertSame('APPROVED', $pay->fresh()->status);
        $this->assertSame('3500.00', $inc->fresh()->outstanding());
    }

    public function test_received_now_cannot_exceed_billed(): void
    {
        $resp = $this->login($this->entry)->from(route('income.create'))
            ->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust, ['received_now' => '9000']));
        $resp->assertRedirect(route('income.create'))->assertSessionHasErrors('received_now');
        $this->followRedirects($resp)->assertSee('Cannot exceed the billed amount');
        $this->assertSame(0, Income::count());
    }

    public function test_reject_requires_reason_and_cascades_to_payments(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry, status: 'PENDING');
        $pay = $this->payment('1000', income: $inc, user: $this->entry, status: 'PENDING');
        try {
            Workflow::reject($inc, $this->admin, '  ');
            $this->fail('A reason should be required.');
        } catch (WorkflowException) {
        }
        Workflow::reject($inc, $this->admin, 'Wrong customer');
        $this->assertSame(['REJECTED', 'Wrong customer'], [$inc->fresh()->status, $inc->fresh()->rejection_reason]);
        $this->assertSame('REJECTED', $pay->fresh()->status);
    }

    public function test_payment_cannot_be_approved_before_invoice(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry, status: 'PENDING');
        $pay = $this->payment('1000', income: $inc, user: $this->entry, status: 'PENDING');
        $this->expectException(WorkflowException::class);
        Workflow::approve($pay, $this->admin);
    }

    public function test_cannot_approve_twice(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry, status: 'PENDING');
        Workflow::approve($inc, $this->admin);
        $this->expectException(WorkflowException::class);
        Workflow::approve($inc, $this->admin);
    }

    public function test_only_admin_can_approve(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry, status: 'PENDING');
        try {
            Workflow::approve($inc, $this->entry);
            $this->fail('Entry user must not approve.');
        } catch (AuthorizationException) {
        }
        $this->login($this->entry)->post(route('approvals.approve', ['income', $inc->id]))->assertForbidden();
        $this->assertSame('PENDING', $inc->fresh()->status);
    }

    public function test_editing_rejected_entry_resubmits_it(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry, status: 'PENDING');
        Workflow::reject($inc, $this->admin, 'Hours wrong');
        $data = $this->incomePost($this->jcb1, $this->cust, ['quantity' => '4']);
        unset($data['received_now'], $data['payment_mode'], $data['payment_reference']);
        $this->login($this->entry)->post(route('income.edit', $inc->id), $data)->assertRedirect($inc->url());
        $inc = $inc->fresh();
        $this->assertSame(['PENDING', null, '4800.00'], [$inc->status, $inc->rejection_reason, $inc->amount]);
    }

    public function test_entry_user_cannot_edit_approved_entry(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry);
        $this->login($this->entry)->get(route('income.edit', $inc->id))->assertForbidden();
    }

    public function test_bulk_approve_handles_invoices_before_payments(): void
    {
        $inc = $this->income(amount: '5000', user: $this->entry, status: 'PENDING');
        $pay = $this->payment('1000', income: $inc, user: $this->entry, status: 'PENDING');
        $exp = $this->expense('300', user: $this->entry, status: 'PENDING');
        $this->login($this->admin)->post(route('approvals.bulk'), [
            'payment' => [(string) $pay->id], 'income' => [(string) $inc->id], 'expense' => [(string) $exp->id],
        ]);
        foreach ([$inc, $pay, $exp] as $obj) {
            $this->assertSame('APPROVED', $obj->fresh()->status);
        }
    }

    // --- permissions ---------------------------------------------------------
    public function test_admin_pages_forbidden_for_entry_and_viewer(): void
    {
        foreach ([$this->entry, $this->viewer] as $user) {
            foreach (['users.index', 'users.create', 'settings', 'audit.index', 'approvals.index'] as $name) {
                $this->login($user)->get(route($name))->assertForbidden();
            }
        }
    }

    public function test_anonymous_redirected_to_login(): void
    {
        foreach (['dashboard', 'income.index', 'reports.cashbook', 'fleet.jcb'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_viewer_cannot_write_even_with_flags(): void
    {
        $this->viewer->forceFill(['can_add_income' => true, 'can_manage_masters' => true])->save();
        $this->login($this->viewer)->get(route('income.create'))->assertForbidden();
        $this->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust))->assertForbidden();
        $this->post(route('vehicles.create'), [])->assertForbidden();
        $this->assertSame(0, Income::count());
    }

    public function test_entry_user_without_capability_is_blocked(): void
    {
        $this->entry->forceFill(['can_add_income' => false])->save();
        $this->login($this->entry)->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust))->assertForbidden();
        $this->assertSame(0, Income::count());
    }

    public function test_driver_expense_capability_is_separate(): void
    {
        $this->entry->forceFill(['can_add_driver_expense' => false])->save();
        $this->login($this->entry)->get(route('driver-expenses.create'))->assertForbidden();
        $this->get(route('expenses.create'))->assertOk();
    }

    public function test_entry_user_sees_only_own_transactions(): void
    {
        $mine = $this->income(amount: '100', user: $this->entry, status: 'PENDING');
        $other = $this->income(amount: '200', user: $this->admin);
        $this->login($this->entry)->get(route('income.index'))->assertSee($mine->number)->assertDontSee($other->number);
        $this->get($other->url())->assertNotFound();
    }

    public function test_entry_user_without_reports_gets_entry_dashboard(): void
    {
        $this->login($this->entry)->get(route('dashboard'))->assertViewIs('dashboard.entry');
        $this->get(route('reports.cashbook'))->assertForbidden();
        $this->get(route('reports.profitability'))->assertForbidden();
    }

    public function test_viewer_with_reports_sees_financial_dashboard(): void
    {
        $this->login($this->viewer)->get(route('dashboard'))->assertViewIs('dashboard.admin');
    }

    public function test_export_requires_capability(): void
    {
        $this->entry->forceFill(['can_view_reports' => true])->save();
        $this->login($this->entry)->get(route('reports.cashbook', ['export' => 'xlsx']))->assertForbidden();
        $this->get(route('income.index', ['export' => 'csv']))->assertForbidden();
    }

    public function test_only_admin_can_delete(): void
    {
        $inc = $this->income(amount: '100', user: $this->entry, status: 'PENDING');
        $this->login($this->entry)->post(route('income.delete', $inc))->assertForbidden();
        $this->assertTrue(Income::whereKey($inc->id)->exists());
    }

    public function test_delete_blocked_when_dependants_exist(): void
    {
        $inc = $this->income(amount: '1000');
        $this->payment('100', income: $inc);
        $this->login($this->admin)->post(route('income.delete', $inc))->assertRedirect($inc->url())->assertSessionHas('error');
        $this->assertTrue(Income::whereKey($inc->id)->exists());
        $this->post(route('vehicles.delete', $this->jcb1))->assertSessionHas('error');
        $this->assertTrue(Vehicle::whereKey($this->jcb1->id)->exists());
    }

    public function test_admin_can_delete_unused_record(): void
    {
        $this->login($this->admin)->post(route('vehicles.delete', $this->jcb2))->assertRedirect(route('vehicles.index'));
        $this->assertFalse(Vehicle::whereKey($this->jcb2->id)->exists());
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $this->login($this->admin)->post(route('users.edit', $this->admin), [
            'username' => 'boss', 'first_name' => 'Boss', 'role' => 'ENTRY', 'is_active' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertSame('ADMIN', $this->admin->fresh()->role);
    }

    public function test_admin_creates_entry_user_with_permissions(): void
    {
        $this->login($this->admin)->post(route('users.create'), [
            'username' => 'newclerk', 'first_name' => 'New', 'role' => 'ENTRY',
            'password' => 'Very$ecure123', 'password_confirmation' => 'Very$ecure123',
            'can_add_income' => '1', 'can_add_expense' => '0',
        ])->assertRedirect(route('users.index'));
        $u = User::where('username', 'newclerk')->sole();
        $this->assertTrue($u->hasCapability('add_income'));
        $this->assertFalse($u->hasCapability('add_expense'));
        $this->assertFalse($u->hasCapability('view_reports'));
    }

    public function test_weak_password_rejected(): void
    {
        $this->login($this->admin)->post(route('users.create'), [
            'username' => 'weak', 'first_name' => 'Weak', 'role' => 'ENTRY', 'password' => '12345678', 'password_confirmation' => '12345678',
        ])->assertSessionHasErrors('password');
        $this->assertFalse(User::where('username', 'weak')->exists());
    }

    public function test_deactivated_user_is_signed_out(): void
    {
        $this->login($this->entry)->get(route('dashboard'))->assertOk();
        $this->entry->forceFill(['is_active' => false])->save();
        $this->actingAs($this->entry->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // --- form validation -----------------------------------------------------
    public function test_vehicle_from_other_category_rejected_by_form(): void
    {
        $resp = $this->login($this->admin)->from(route('income.create'))
            ->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust, ['vehicle_category' => 'TRACTOR']));
        $resp->assertSessionHasErrors('vehicle');
        $this->followRedirects($resp)->assertSee('Select a valid vehicle for the chosen category.')->assertSee('is-invalid');
        $this->assertSame(0, Income::count());
    }

    public function test_missing_category_shows_error(): void
    {
        $this->login($this->admin)->post(route('income.create'), $this->incomePost($this->jcb1, $this->cust, ['vehicle_category' => '']))
            ->assertSessionHasErrors('vehicle_category');
    }

    public function test_diesel_expense_requires_vehicle_category(): void
    {
        $resp = $this->login($this->admin)->from(route('expenses.create'))->post(route('expenses.create'), [
            'date' => '2026-05-01', 'category' => 'DIESEL', 'vehicle_category' => '', 'vehicle' => '', 'amount' => '500', 'payment_mode' => 'CASH',
        ]);
        $this->followRedirects($resp)->assertSee('Select the vehicle category first');
        $this->assertSame(0, Expense::count());
    }

    public function test_driver_expense_form_saves_driver_and_vehicle(): void
    {
        $this->login($this->entry)->post(route('driver-expenses.create'), [
            'date' => '2026-05-01', 'category' => 'DIESEL', // tampered: forced back to DRIVER
            'driver' => $this->driver->id, 'driver_expense_type' => 'BATA', 'vehicle_category' => 'JCB',
            'vehicle' => $this->jcb1->id, 'amount' => '350', 'payment_mode' => 'CASH',
        ])->assertRedirect();
        $exp = Expense::sole();
        $this->assertSame(['DRIVER', $this->driver->id, $this->jcb1->id], [$exp->category, (int) $exp->driver_id, (int) $exp->vehicle_id]);
    }

    public function test_regular_expense_form_refuses_driver_category(): void
    {
        $this->login($this->admin)->post(route('expenses.create'), [
            'date' => '2026-05-01', 'category' => 'DRIVER', 'amount' => '500', 'payment_mode' => 'CASH',
        ])->assertSessionHasErrors('category');
        $this->assertSame(0, Expense::count());
    }

    public function test_payment_form_overpayment_error_shown(): void
    {
        $inc = $this->income(amount: '1000');
        $resp = $this->login($this->admin)->from(route('payments.create'))->post(route('payments.create'), [
            'date' => '2026-05-02', 'customer' => $this->cust->id, 'income' => $inc->id, 'amount' => '1500', 'mode' => 'CASH',
        ]);
        $this->followRedirects($resp)->assertSee('exceeds the balance due');
        $this->assertSame(0, Payment::count());
    }

    public function test_duplicate_registration_rejected(): void
    {
        $this->login($this->admin)->post(route('vehicles.create'), [
            'category' => 'JCB', 'registration_number' => 'ka01 jcb-1', 'hourly_rate' => '0', 'daily_rate' => '0', 'status' => 'ACTIVE',
        ])->assertSessionHasErrors(['registration_number' => 'A vehicle with this registration number already exists.']);
    }

    public function test_vehicle_api_returns_only_category(): void
    {
        $data = $this->login($this->entry)->getJson(route('api.vehicles', ['category' => 'JCB']))->json('vehicles');
        $this->assertEqualsCanonicalizing([$this->jcb1->id, $this->jcb2->id], array_column($data, 'id'));
        $this->getJson(route('api.vehicles', ['category' => 'X']))->assertStatus(400);
    }

    public function test_open_invoices_api(): void
    {
        $inc = $this->income(amount: '1000');
        $this->payment('400', income: $inc);
        $paid = $this->income(amount: '500');
        $this->payment('500', income: $paid);
        $data = $this->login($this->admin)->getJson(route('api.invoices', ['customer' => $this->cust->id]))->json('invoices');
        $this->assertSame([[$inc->id, '600.00']], array_map(fn ($i) => [$i['id'], $i['due']], $data));
    }

    public function test_list_search_filter_pagination(): void
    {
        foreach (range(0, 29) as $i) {
            $this->income(amount: (string) (100 + $i), vehicle: $i % 2 ? $this->tractor : $this->jcb1, day: sprintf('2026-05-%02d', 1 + $i % 28));
        }
        $this->login($this->admin);
        $this->assertSame(15, $this->get(route('income.index', ['vehicle_category' => 'TRACTOR']))->viewData('incomes')->total());
        $this->assertCount(25, $this->get(route('income.index'))->viewData('incomes')->items());
        $this->assertCount(5, $this->get(route('income.index', ['page' => 2]))->viewData('incomes')->items());
        $target = Income::orderBy('id')->first();
        $this->assertSame(1, $this->get(route('income.index', ['q' => $target->number]))->viewData('incomes')->total());
        $rows = $this->get(route('income.index', ['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-05-02']))->viewData('incomes');
        $this->assertTrue(collect($rows->items())->every(fn ($i) => $i->date->toDateString() <= '2026-05-02'));
        $this->assertSame(1, $this->get(route('income.index', ['payment' => 'unpaid', 'q' => $target->number]))->viewData('incomes')->total());
    }
}
