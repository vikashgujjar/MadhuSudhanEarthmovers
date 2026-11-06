<?php

namespace Tests\Feature;

use App\Models\Driver;
use Tests\TestCase;

class DriverDetailsTest extends TestCase
{
    public function test_salary_and_joining_date_are_saved_and_shown(): void
    {
        $this->login($this->admin)->post(route('drivers.create'), [
            'name' => 'Mahesh Rao', 'monthly_salary' => '18500', 'daily_allowance' => '250',
            'joining_date' => '2025-03-15', 'status' => 'ACTIVE',
        ])->assertRedirect();

        $driver = Driver::where('name', 'Mahesh Rao')->sole();
        $this->assertSame('18500.00', $driver->monthly_salary);
        $this->assertSame('2025-03-15', $driver->joining_date->toDateString());

        $this->get(route('drivers.show', $driver))->assertOk()->assertSee('18,500.00')->assertSee('15 Mar 2025');
        $this->get(route('drivers.index'))->assertOk()->assertSee('Joined')->assertSee('18,500.00')->assertSee('15 Mar 2025');

        $csv = $this->get(route('drivers.index', ['export' => 'csv', 'q' => 'Mahesh']))->assertOk()->getContent();
        $this->assertStringContainsString('Joined', $csv);
        $this->assertStringContainsString('18500', str_replace(',', '', $csv));
    }

    public function test_joining_date_is_optional_and_must_be_a_date(): void
    {
        $base = ['name' => 'New Driver', 'monthly_salary' => '0', 'daily_allowance' => '0', 'status' => 'ACTIVE'];
        $this->login($this->admin)->post(route('drivers.create'), $base + ['joining_date' => 'soon'])->assertSessionHasErrors('joining_date');
        $this->post(route('drivers.create'), $base)->assertRedirect();
        $this->assertNull(Driver::where('name', 'New Driver')->sole()->joining_date);
        $this->get(route('drivers.index'))->assertOk();
    }
}
