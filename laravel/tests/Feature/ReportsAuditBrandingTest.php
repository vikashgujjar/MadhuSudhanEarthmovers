<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Services\Workflow;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Exports, audit trail and business settings / branding. */
class ReportsAuditBrandingTest extends TestCase
{
    private function exportFixture(): void
    {
        $inv = $this->income(amount: '12345.50', day: '2026-05-01');
        $this->payment('2345.50', income: $inv, day: '2026-05-02');
        $this->expense('1000', day: '2026-05-03');
        $this->login($this->admin);
    }

    // --- exports -------------------------------------------------------------
    public function test_csv_contains_business_name_and_records(): void
    {
        $this->exportFixture();
        $resp = $this->get(route('income.index', ['export' => 'csv']));
        $resp->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $text = substr($resp->getContent(), 3); // strip BOM
        $rows = array_map('str_getcsv', explode("\n", trim($text)));
        $this->assertSame(['Madhusudhan Earthmover'], $rows[0]);
        $this->assertStringContainsString('12345.50', $text);
        $this->assertStringContainsString('10000.00', $text); // balance
    }

    public function test_xlsx_is_valid_and_branded(): void
    {
        $this->exportFixture();
        $resp = $this->get(route('reports.profitability', ['period' => 'all', 'export' => 'xlsx']));
        $resp->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($tmp, $resp->getContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $this->assertSame('MADHUSUDHAN EARTHMOVER', $sheet->getCell('A1')->getValue());
        $values = [];
        foreach ($sheet->toArray(null, false, false) as $row) {
            foreach ($row as $v) {
                $values[] = $v;
            }
        }
        $this->assertContains('Vehicle-wise Profitability', $values);
        $this->assertContains(12345.5, $values);
    }

    public function test_pdf_is_generated(): void
    {
        $this->exportFixture();
        foreach (['reports.cashbook', 'reports.pnl', 'customers.outstanding', 'expenses.index', 'reports.driver-expenses',
            'reports.expense-categories', 'audit.index', 'reports.profitability'] as $name) {
            $resp = $this->get(route($name, ['period' => 'all', 'export' => 'pdf']));
            $resp->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $resp->getContent(), $name);
        }
    }

    public function test_export_is_audited(): void
    {
        $this->exportFixture();
        $this->get(route('customers.outstanding', ['export' => 'xlsx']))->assertOk();
        $log = AuditLog::where('action', 'EXPORT')->latest('id')->first();
        $this->assertStringContainsString('Customer Outstanding Balances', $log->object_repr);
    }

    public function test_export_uses_same_filters_as_screen(): void
    {
        $this->exportFixture();
        $csv = $this->get(route('income.index', ['vehicle_category' => 'TRACTOR', 'export' => 'csv']))->getContent();
        $this->assertStringNotContainsString('12345.50', $csv);
    }

    public function test_report_pages_show_database_values(): void
    {
        $this->exportFixture();
        $this->get(route('reports.pnl', ['period' => 'all']))->assertSee('12,345.50')->assertSee('11,345.50'); // net profit
        $resp = $this->get(route('dashboard', ['period' => 'all']));
        $this->assertSame('2345.50', $resp->viewData('summary')['received_total']);
        $resp->assertSee('2,345.50');
    }

    // --- audit ---------------------------------------------------------------
    public function test_create_update_delete_approve_logged(): void
    {
        $this->login($this->admin);
        $this->post(route('customers.create'), ['name' => 'Audit Co', 'opening_balance' => '0', 'is_active' => '1']);
        $cust = Customer::where('name', 'Audit Co')->sole();
        $this->post(route('customers.edit', $cust), ['name' => 'Audit Co', 'opening_balance' => '500', 'is_active' => '1']);
        $update = AuditLog::where('action', 'UPDATE')->where('object_id', (string) $cust->id)->sole();
        $this->assertSame(['0.00', '500.00'], $update->changes['fields']['opening_balance']);
        $this->post(route('customers.delete', $cust));
        $deleted = AuditLog::where('action', 'DELETE')->where('object_id', (string) $cust->id)->sole();
        $this->assertSame('Audit Co', $deleted->changes['snapshot']['name']);

        $inc = $this->income(amount: '100', user: $this->entry, status: 'PENDING');
        Workflow::approve($inc, $this->admin);
        $this->assertTrue(AuditLog::where('action', 'APPROVE')->where('object_id', (string) $inc->id)->exists());
    }

    public function test_transaction_amount_change_preserved(): void
    {
        $inc = $this->income(amount: '1000');
        $this->login($this->admin)->post(route('income.edit', $inc->id), [
            'date' => '2026-05-10', 'vehicle_category' => 'JCB', 'vehicle' => $this->jcb1->id, 'customer' => $this->cust->id,
            'billing_type' => 'MANUAL', 'amount' => '1500',
        ])->assertRedirect($inc->url());
        $log = AuditLog::where('action', 'UPDATE')->where('model_name', 'Income Entry')->sole();
        $this->assertSame(['1000.00', '1500.00'], $log->changes['fields']['amount']);
        $this->assertArrayNotHasKey('date', $log->changes['fields']);
    }

    public function test_failed_delete_leaves_no_delete_log(): void
    {
        $inc = $this->income(amount: '1000');
        $this->payment('10', income: $inc);
        $this->login($this->admin)->post(route('income.delete', $inc));
        $this->assertFalse(AuditLog::where('action', 'DELETE')->exists());
    }

    public function test_logs_are_immutable(): void
    {
        $log = AuditLog::create(['action' => 'CREATE', 'object_repr' => 'x']);
        $log->object_repr = 'tampered';
        try {
            $log->save();
            $this->fail('Update should be refused.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_login_and_failed_login_logged_and_lockout(): void
    {
        $this->post(route('login'), ['username' => 'clerk', 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertTrue(AuditLog::where('action', 'LOGIN')->where('username', 'clerk')->exists());
        $this->post(route('logout'));
        $this->assertGuest();
        foreach (range(1, 5) as $_) {
            $resp = $this->from(route('login'))->post(route('login'), ['username' => 'clerk', 'password' => 'wrong']);
            $this->followRedirects($resp)->assertSee('Incorrect username or password');
        }
        $this->assertSame(5, AuditLog::where('action', 'LOGIN_FAILED')->count());
        $resp = $this->from(route('login'))->post(route('login'), ['username' => 'clerk', 'password' => self::PASSWORD]);
        $this->followRedirects($resp)->assertSee('Too many failed attempts');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->entry->forceFill(['is_active' => false])->save();
        $this->post(route('login'), ['username' => 'clerk', 'password' => self::PASSWORD])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    // --- branding / settings -----------------------------------------------
    public function test_default_organisation_from_migration(): void
    {
        $bs = BusinessSetting::current();
        $this->assertSame('Madhusudhan Earthmover', $bs->business_name);
        $this->assertSame('Madhusudhan Earthmover ERP', $bs->erp_title);
    }

    public function test_name_shown_on_login_sidebar_and_dashboard(): void
    {
        $this->get(route('login'))->assertSee('Madhusudhan Earthmover ERP')->assertSee('Madhusudhan');
        $this->login($this->admin)->get(route('dashboard'))->assertSee('Earthmover')->assertSee('Madhusudhan Earthmover');
    }

    public function test_admin_can_rename_and_change_applies_everywhere(): void
    {
        $this->login($this->admin)->post(route('settings'), [
            'business_name' => 'Madhusudhan Earthmovers Pvt Ltd', 'erp_title' => 'ME Fleet ERP', 'tagline' => 'JCB & Tractor Hire',
            'currency_symbol' => '₹', 'financial_year_start_month' => '4', 'invoice_prefix' => 'me-inv', 'receipt_prefix' => 'ME-RCT',
            'expense_prefix' => 'ME-EXP', 'opening_cash_balance' => '0', 'require_approval' => '1', 'report_footer' => 'Thank you',
        ])->assertRedirect(route('settings'));
        BusinessSetting::forgetCache();
        $this->assertSame('ME-INV', BusinessSetting::current()->invoice_prefix);
        $this->assertTrue(AuditLog::where('action', 'SETTINGS')->exists());
        $this->post(route('logout'));
        $this->get(route('login'))->assertSee('ME Fleet ERP');
        $csv = $this->login($this->admin)->get(route('customers.index', ['export' => 'csv']))->getContent();
        $this->assertSame(['Madhusudhan Earthmovers Pvt Ltd'], str_getcsv(strtok(substr($csv, 3), "\n")));
        $this->assertStringStartsWith('ME-INV-', $this->income(amount: '10')->number);
    }

    public function test_settings_validation(): void
    {
        $this->login($this->admin)->post(route('settings'), ['business_name' => '', 'invoice_prefix' => 'bad prefix!'])
            ->assertSessionHasErrors(['business_name', 'invoice_prefix']);
        BusinessSetting::forgetCache();
        $this->assertSame('Madhusudhan Earthmover', BusinessSetting::current()->business_name);
    }

    public function test_settings_singleton(): void
    {
        (new BusinessSetting(['business_name' => 'Other', 'erp_title' => 'Other ERP']))->save();
        $this->assertSame(1, BusinessSetting::query()->count());
        $this->assertSame('Other', BusinessSetting::query()->find(1)->business_name);
    }

    public function test_logo_upload_served_publicly_and_bills_protected(): void
    {
        $this->login($this->admin);
        $png = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 64, 64);
        $this->post(route('settings'), [
            'business_name' => 'Madhusudhan Earthmover', 'erp_title' => 'Madhusudhan Earthmover ERP', 'currency_symbol' => '₹',
            'financial_year_start_month' => '4', 'invoice_prefix' => 'ME-INV', 'receipt_prefix' => 'ME-RCT', 'expense_prefix' => 'ME-EXP',
            'opening_cash_balance' => '0', 'logo' => $png,
        ])->assertRedirect(route('settings'));
        BusinessSetting::forgetCache();
        $logo = BusinessSetting::current()->logo;
        $this->assertNotNull($logo);
        $this->post(route('logout'));
        $this->get(route('media', ['path' => $logo]))->assertOk();
        $this->get(route('login'))->assertSee('/media/'.$logo, false);

        $bill = \Illuminate\Http\UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf');
        $this->login($this->entry)->post(route('expenses.create'), [
            'date' => '2026-05-01', 'category' => 'OTHER', 'vehicle_category' => '', 'amount' => '75', 'payment_mode' => 'CASH', 'attachment' => $bill,
        ])->assertRedirect();
        $path = \App\Models\Expense::sole()->attachment;
        $this->get(route('media', ['path' => $path]))->assertOk();
        $other = $this->makeUser('clerk2', 'ENTRY');
        $this->login($other)->get(route('media', ['path' => $path]))->assertOk();
        $this->post(route('logout'));
        $this->get(route('media', ['path' => $path]))->assertRedirect(route('login'));
        \Illuminate\Support\Facades\Storage::disk('local')->delete([$logo, $path]);
    }

    public function test_database_update_button_is_admin_only_and_safe_when_current(): void
    {
        $this->login($this->entry)->post(route('settings.migrate'))->assertForbidden();
        $this->login($this->admin)->get(route('settings'))->assertOk()->assertDontSee('Apply database updates');
        $this->post(route('settings.migrate'))->assertRedirect(route('settings'))->assertSessionHas('info');
    }

    public function test_installer_is_disabled_once_an_admin_exists(): void
    {
        $this->get(route('install'))->assertNotFound();
        $this->post(route('install.run'), [])->assertNotFound();
    }
}
