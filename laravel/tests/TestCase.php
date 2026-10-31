<?php

namespace Tests;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Validation\ValidationException;

/**
 * Base fixture: one admin, entry user, viewer, two JCBs, one tractor, a
 * driver and two customers.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public const PASSWORD = 'Str0ng!Pass#2026';

    protected User $admin;

    protected User $entry;

    protected User $viewer;

    protected Vehicle $jcb1;

    protected Vehicle $jcb2;

    protected Vehicle $tractor;

    protected Driver $driver;

    protected Customer $cust;

    protected Customer $cust2;

    protected function setUp(): void
    {
        parent::setUp();
        BusinessSetting::forgetCache();

        $this->admin = $this->makeUser('boss', 'ADMIN', ['first_name' => 'Boss']);
        $this->entry = $this->makeUser('clerk', 'ENTRY', ['first_name' => 'Clerk', 'can_record_payment' => true]);
        $this->viewer = $this->makeUser('auditor', 'VIEWER', ['first_name' => 'Auditor', 'can_view_reports' => true, 'can_export_reports' => true]);

        $this->jcb1 = Vehicle::create(['category' => 'JCB', 'registration_number' => 'KA01JCB1', 'name' => 'JCB-1', 'hourly_rate' => '1200', 'daily_rate' => '9000']);
        $this->jcb2 = Vehicle::create(['category' => 'JCB', 'registration_number' => 'KA01JCB2', 'name' => 'JCB-2', 'hourly_rate' => '1300', 'daily_rate' => '9500']);
        $this->tractor = Vehicle::create(['category' => 'TRACTOR', 'registration_number' => 'KA01TR1', 'name' => 'Tractor-1', 'hourly_rate' => '700', 'daily_rate' => '4500']);
        $this->driver = Driver::create(['name' => 'Ramesh', 'phone' => '9845012345', 'assigned_vehicle_id' => $this->jcb1->id]);
        $this->cust = Customer::create(['name' => 'Sai Constructions', 'opening_balance' => '1000']);
        $this->cust2 = Customer::create(['name' => 'Green Farms']);
    }

    protected function makeUser(string $username, string $role, array $attrs = []): User
    {
        $user = new User(['username' => $username, 'role' => $role, 'is_active' => true] + $attrs);
        foreach ($attrs as $k => $v) {
            $user->setAttribute($k, $v);
        }
        $user->password = self::PASSWORD;
        $user->save();

        return $user;
    }

    protected function login(User $user): static
    {
        return $this->actingAs($user->fresh());
    }

    /** Stamp, validate (like the forms do) and save a record. Approved by the admin unless stated. */
    protected function stamp(Model $obj, ?User $user, string $status): Model
    {
        $obj->created_by = ($user ?? $this->admin)->id;
        $obj->status = $status;
        if ($status === 'APPROVED') {
            $obj->approved_by = $this->admin->id;
            $obj->approved_at = now();
        }
        $errors = $obj->businessErrors();
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $obj->save();

        return $obj->fresh();
    }

    protected function income(?string $amount = null, ?Vehicle $vehicle = null, ?Customer $customer = null, string $billing = 'MANUAL',
        ?string $qty = null, ?string $rate = null, ?string $day = null, ?User $user = null, string $status = 'APPROVED'): Income
    {
        $vehicle ??= $this->jcb1;
        $inc = new Income([
            'date' => $day ?? '2026-05-10', 'vehicle_category' => $vehicle->category, 'vehicle_id' => $vehicle->id,
            'customer_id' => ($customer ?? $this->cust)->id, 'billing_type' => $billing, 'quantity' => $qty, 'rate' => $rate,
            'amount' => $amount,
        ]);

        return $this->stamp($inc, $user, $status);
    }

    protected function expense(string $amount, string $category = 'DIESEL', Vehicle|false|null $vehicle = false, ?Driver $driver = null,
        ?string $dtype = null, ?string $day = null, ?User $user = null, string $status = 'APPROVED', string $mode = 'CASH'): Expense
    {
        $vehicle = $vehicle === false ? $this->jcb1 : $vehicle;
        $exp = new Expense([
            'date' => $day ?? '2026-05-10', 'category' => $category, 'vehicle_id' => $vehicle?->id,
            'vehicle_category' => $vehicle?->category, 'driver_id' => $driver?->id, 'driver_expense_type' => $dtype,
            'amount' => $amount, 'payment_mode' => $mode,
        ]);

        return $this->stamp($exp, $user, $status);
    }

    protected function payment(string $amount, ?Income $income = null, ?Customer $customer = null, ?string $day = null,
        ?User $user = null, string $status = 'APPROVED', string $mode = 'CASH'): Payment
    {
        $pay = new Payment([
            'date' => $day ?? '2026-05-12', 'customer_id' => ($customer ?? ($income ? $income->customer : $this->cust))->id,
            'income_id' => $income?->id, 'amount' => $amount, 'mode' => $mode,
        ]);

        return $this->stamp($pay, $user, $status);
    }

    protected function assertValidationKeys(callable $fn, array $keys): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $e->errors(), "Expected a validation error on {$key}");
            }

            return;
        }
        $this->fail('Expected a validation error.');
    }
}
