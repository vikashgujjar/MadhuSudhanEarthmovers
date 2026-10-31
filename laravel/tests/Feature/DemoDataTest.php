<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    public function test_refuses_on_production_and_on_a_database_with_records(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('erp:demo-data')->assertFailed();
        $this->app['env'] = 'testing';
        $this->artisan('erp:demo-data')->assertFailed(); // fixture vehicles exist
        $this->assertSame(3, Vehicle::count());
    }

    public function test_remove_then_fill_every_table(): void
    {
        $this->artisan('erp:demo-data --remove')->expectsConfirmation(
            'Delete ALL vehicles, drivers, customers, income, expenses, payments and loans in '.config('database.connections.mysql.database').'?', 'yes'
        )->assertSuccessful();
        $this->assertSame(0, Vehicle::count());

        $this->artisan('erp:demo-data')->assertSuccessful();
        $this->assertSame(5, Vehicle::count());
        $this->assertGreaterThan(30, Income::count());
        $this->assertGreaterThan(50, Expense::where('category', 'DIESEL')->count());
        $this->assertTrue(Expense::where('category', 'DRIVER')->exists());
        $this->assertTrue(Payment::whereNull('income_id')->exists());
        $this->assertTrue(Income::where('status', 'REJECTED')->exists());
        $this->assertSame(1, Loan::overdue()->count());
        $this->assertTrue(User::where('username', 'ramesh.entry')->where('role', 'ENTRY')->exists());

        // No invoice is over-collected.
        foreach (Income::with('payments')->get() as $inc) {
            $this->assertLessThanOrEqual((float) $inc->amount, (float) $inc->payments->where('status', '!=', 'REJECTED')->sum('amount'));
        }
        $this->login($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Money to collect');
    }
}
