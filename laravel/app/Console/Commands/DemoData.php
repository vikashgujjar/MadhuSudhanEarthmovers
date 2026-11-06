<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Models\DriverSalaryAdjustment;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Workflow;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo records for trying the ERP on a local copy. Refuses to run on a
 * production site so made-up figures never mix with real accounts.
 *
 *   php artisan erp:demo-data            add demo data (empty database only)
 *   php artisan erp:demo-data --remove   delete all business records again
 */
class DemoData extends Command
{
    protected $signature = 'erp:demo-data {--remove : Delete all vehicles, drivers, customers, transactions, loans and demo users}';

    protected $description = 'Fill a local (non-production) database with demo data';

    private const DEMO_USERS = ['ramesh.entry', 'accountant'];

    public const DEMO_PASSWORD = 'Demo@12345';

    private CarbonImmutable $today;

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This is a production site (APP_ENV=production). Demo data is only for a local copy.');

            return self::FAILURE;
        }
        if ($this->option('remove')) {
            return $this->remove();
        }
        if (Vehicle::exists() || Customer::exists() || Income::exists() || Expense::exists()) {
            $this->error('The database already has records. Run with --remove first to start from an empty database.');

            return self::FAILURE;
        }
        $admin = User::where('role', 'ADMIN')->where('is_active', true)->orderBy('id')->first();
        if (! $admin) {
            $this->error('Create an administrator first: php artisan erp:create-admin admin "Password1"');

            return self::FAILURE;
        }

        mt_srand(2026);
        $this->today = CarbonImmutable::today();
        Auth::setUser($admin);
        DB::transaction(fn () => $this->seed($admin));

        $this->table(['Table', 'Rows'], collect([
            'users' => User::count(), 'vehicles' => Vehicle::count(), 'drivers' => Driver::count(),
            'customers' => Customer::count(), 'incomes' => Income::count(), 'expenses' => Expense::count(),
            'payments' => Payment::count(), 'loans' => Loan::count(), 'loan_repayments' => LoanRepayment::count(),
            'audit_logs' => AuditLog::count(),
        ])->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info('Demo logins: ramesh.entry (Entry User) and accountant (Viewer), password '.self::DEMO_PASSWORD);

        return self::SUCCESS;
    }

    private function remove(): int
    {
        if (! $this->confirm('Delete ALL vehicles, drivers, attendance, customers, income, expenses, payments and loans in '.config('database.connections.mysql.database').'?')) {
            return self::FAILURE;
        }
        DB::transaction(function () {
            LoanRepayment::query()->delete();
            Loan::query()->delete();
            Payment::query()->delete();
            Expense::query()->delete();
            Income::query()->delete();
            DriverAttendance::query()->delete();
            DriverSalaryAdjustment::query()->delete();
            Driver::query()->delete();
            Customer::query()->delete();
            Vehicle::query()->delete();
            User::whereIn('username', self::DEMO_USERS)->delete();
            AuditLog::query()->delete();
        });
        $this->info('All business records removed. Users (except the demo logins) and business settings were kept.');

        return self::SUCCESS;
    }

    private function seed(User $admin): void
    {
        $entry = $this->user('ramesh.entry', 'Ramesh', 'ENTRY', ['add_income', 'add_expense', 'add_driver_expense', 'record_payment']);
        $this->user('accountant', 'Suma', 'VIEWER', ['view_reports', 'export_reports']);

        $v = [];
        foreach ([
            ['JCB', 'KA-35-M-4521', 'JCB 3DX Super', 'JCB', '3DX Super', 2021, 3200000, 1300, 10000],
            ['JCB', 'KA-35-N-7810', 'JCB 3DX Eco', 'JCB', '3DX Eco', 2022, 3450000, 1250, 9500],
            ['JCB', 'KA-35-P-1209', 'JCB 4DX', 'JCB', '4DX', 2023, 3900000, 1500, 11500],
            ['TRACTOR', 'KA-35-T-3344', 'Mahindra 575 DI', 'Mahindra', '575 DI XP Plus', 2020, 780000, 750, 5000],
            ['TRACTOR', 'KA-35-T-9087', 'Swaraj 744 FE', 'Swaraj', '744 FE', 2022, 820000, 700, 4800],
        ] as [$cat, $reg, $name, $make, $model, $year, $cost, $hr, $dr]) {
            $v[] = $this->created(Vehicle::create([
                'category' => $cat, 'registration_number' => $reg, 'name' => $name, 'make' => $make, 'model' => $model,
                'year' => $year, 'purchase_date' => "{$year}-03-15", 'purchase_cost' => $cost, 'hourly_rate' => $hr,
                'daily_rate' => $dr, 'insurance_expiry' => $this->today->addMonths(mt_rand(1, 10))->toDateString(),
            ]));
        }
        $v[2]->update(['status' => 'MAINTENANCE', 'notes' => 'Hydraulic pump being replaced.']);

        $d = [];
        foreach ([
            ['Manjunath Gowda', '9845011223', 0, 18000, 300],
            ['Basavaraj Patil', '9900123456', 1, 17000, 300],
            ['Shivakumar H', '9886543210', 2, 19000, 350],
            ['Ravi Naik', '9741234567', 3, 14000, 250],
        ] as [$name, $phone, $vi, $salary, $bata]) {
            $d[] = $this->created(Driver::create([
                'name' => $name, 'phone' => $phone, 'license_number' => 'KA35'.mt_rand(2010, 2020).str_pad((string) mt_rand(1, 99999), 7, '0', STR_PAD_LEFT),
                'license_expiry' => $this->today->addYears(mt_rand(1, 6))->toDateString(), 'assigned_vehicle_id' => $v[$vi]->id,
                'monthly_salary' => $salary, 'daily_allowance' => $bata, 'joining_date' => $this->today->subMonths(mt_rand(8, 40))->toDateString(),
                'address' => 'Ballari, Karnataka',
            ]));
        }

        $c = [];
        foreach ([
            ['Sri Sai Constructions', 'Prakash Reddy', '9448012345', '29ABCDE1234F1Z5', 25000],
            ['Hampi Builders', 'Naveen Kumar', '9449023456', '29BCDEF2345G1Z6', 0],
            ['Green Valley Farms', 'Lakshmi Devi', '9480034567', null, 8000],
            ['Tungabhadra Infra Pvt Ltd', 'Anil Jain', '9535045678', '29CDEFG3456H1Z7', 0],
            ['Gram Panchayat Kurugodu', 'Secretary', '9611056789', null, 0],
            ['Mallikarjun (farmer)', null, '9902067890', null, 0],
        ] as [$name, $contact, $phone, $gstin, $opening]) {
            $c[] = $this->created(Customer::create([
                'name' => $name, 'contact_person' => $contact, 'phone' => $phone, 'gstin' => $gstin,
                'opening_balance' => $opening, 'address' => 'Ballari district, Karnataka',
            ]));
        }

        $sites = ['Kurugodu road widening', 'Hospet layout', 'Siruguppa canal', 'Ballari bypass', 'Farm pond, Kampli', 'Borewell site, Sandur'];
        $start = $this->today->subMonths(5)->startOfMonth();

        // Income and payments: about two jobs a week across the fleet.
        $incomes = [];
        for ($day = $start; $day->lte($this->today); $day = $day->addDays(mt_rand(2, 4))) {
            $vehicle = $v[mt_rand(0, 4)];
            $billing = ['HOURLY', 'HOURLY', 'HOURLY', 'DAILY', 'FIXED', 'MANUAL'][mt_rand(0, 5)];
            $recent = $day->gt($this->today->subDays(10));
            $user = $recent && mt_rand(0, 1) ? $entry : $admin;
            $inc = new Income([
                'date' => $day->toDateString(), 'vehicle_category' => $vehicle->category, 'vehicle_id' => $vehicle->id,
                'customer_id' => $c[mt_rand(0, 5)]->id, 'driver_id' => collect($d)->firstWhere('assigned_vehicle_id', $vehicle->id)?->id,
                'billing_type' => $billing, 'work_location' => $sites[mt_rand(0, 5)],
                'quantity' => match ($billing) { 'HOURLY' => mt_rand(4, 24) + (mt_rand(0, 1) ? 0.3 : 0), 'DAILY' => mt_rand(1, 6), default => null },
                'rate' => match ($billing) { 'HOURLY' => $vehicle->hourly_rate, 'DAILY' => $vehicle->daily_rate, default => null },
                'amount' => in_array($billing, ['FIXED', 'MANUAL'], true) ? mt_rand(8, 60) * 1000 : null,
                'description' => ['Earth excavation', 'Trench digging', 'Site levelling', 'Loading and shifting', 'Pond digging'][mt_rand(0, 4)],
            ]);
            $incomes[] = $this->transaction($inc, $user);
        }

        foreach ($incomes as $inc) {
            if ($inc->status !== 'APPROVED') {
                continue;
            }
            $age = $inc->date->diffInDays($this->today);
            $share = $age > 45 ? [100, 100, 100, 60][mt_rand(0, 3)] : ($age > 15 ? [100, 50, 0][mt_rand(0, 2)] : [0, 0, 40][mt_rand(0, 2)]);
            if ($share === 0) {
                continue;
            }
            $amount = Money::multiply($inc->amount, (string) ($share / 100));
            $this->transaction(new Payment([
                'date' => $inc->date->addDays(min(mt_rand(1, 20), max(1, $age)))->min($this->today)->toDateString(),
                'customer_id' => $inc->customer_id, 'income_id' => $inc->id, 'amount' => $amount,
                'mode' => ['CASH', 'UPI', 'BANK', 'CHEQUE'][mt_rand(0, 3)], 'reference' => mt_rand(0, 1) ? 'UTR'.mt_rand(100000, 999999) : null,
            ]), $admin);
        }
        // Opening balances collected, and one advance.
        $this->transaction(new Payment(['date' => $start->addDays(12)->toDateString(), 'customer_id' => $c[0]->id, 'amount' => 15000, 'mode' => 'BANK', 'notes' => 'Against old balance']), $admin);
        $this->transaction(new Payment(['date' => $this->today->subDays(6)->toDateString(), 'customer_id' => $c[3]->id, 'amount' => 20000, 'mode' => 'BANK', 'notes' => 'Advance for next month']), $admin);
        $this->transaction(new Payment(['date' => $this->today->subDays(2)->toDateString(), 'customer_id' => $c[2]->id, 'amount' => 3000, 'mode' => 'CASH']), $entry);

        // Expenses: diesel weekly per working vehicle, monthly driver pay, repairs and overheads.
        for ($month = $start; $month->lte($this->today); $month = $month->addMonth()) {
            foreach ($v as $vehicle) {
                for ($w = 0; $w < 4; $w++) {
                    $day = $month->addDays($w * 7 + mt_rand(0, 4));
                    if ($day->gt($this->today)) {
                        break;
                    }
                    $litres = mt_rand($vehicle->category === 'JCB' ? 60 : 30, $vehicle->category === 'JCB' ? 140 : 70);
                    $this->transaction(new Expense([
                        'date' => $day->toDateString(), 'category' => 'DIESEL', 'vehicle_id' => $vehicle->id, 'vehicle_category' => $vehicle->category,
                        'litres' => $litres, 'amount' => Money::multiply((string) $litres, '94.50'), 'payment_mode' => 'CASH',
                        'vendor' => ['HP Petrol Bunk, Kurugodu', 'Indian Oil, Ballari'][mt_rand(0, 1)],
                    ]), $day->gt($this->today->subDays(7)) && mt_rand(0, 1) ? $entry : $admin);
                }
            }
            $payday = $month->addDays(4);
            foreach ($d as $driver) {
                if ($payday->lte($this->today)) {
                    $this->driverExpense($driver, $payday, 'SALARY', $driver->monthly_salary, 'BANK', $admin);
                }
                $bataDay = $month->addDays(mt_rand(10, 25));
                if ($bataDay->lte($this->today)) {
                    $this->driverExpense($driver, $bataDay, 'BATA', Money::multiply($driver->daily_allowance, (string) mt_rand(8, 20)), 'CASH', $admin);
                }
            }
            $rentDay = $month->addDays(1);
            if ($rentDay->lte($this->today)) {
                $this->transaction(new Expense(['date' => $rentDay->toDateString(), 'category' => 'OTHER', 'amount' => 8000,
                    'payment_mode' => 'BANK', 'vendor' => 'Yard owner', 'description' => 'Yard and office rent']), $admin);
            }
            $repairDay = $month->addDays(mt_rand(5, 25));
            if ($repairDay->lte($this->today)) {
                $vehicle = $v[mt_rand(0, 4)];
                $cat = ['MAINTENANCE', 'REPAIR', 'SPARE_PARTS'][mt_rand(0, 2)];
                $this->transaction(new Expense([
                    'date' => $repairDay->toDateString(), 'category' => $cat, 'vehicle_id' => $vehicle->id, 'vehicle_category' => $vehicle->category,
                    'amount' => mt_rand(15, 180) * 100, 'payment_mode' => ['CASH', 'UPI'][mt_rand(0, 1)],
                    'vendor' => ['Sri Ganesh Auto Works', 'JCB Service Centre, Hospet', 'Basaveshwara Spares'][mt_rand(0, 2)],
                    'bill_number' => 'B-'.mt_rand(1000, 9999),
                    'description' => ['Engine oil and filter change', 'Bucket teeth replaced', 'Tyre puncture and tube', 'Hydraulic hose replaced'][mt_rand(0, 3)],
                ]), $admin);
            }
        }
        $this->driverExpense($d[1], $this->today->subDays(3), 'ADVANCE', 5000, 'CASH', $entry);
        $this->driverExpense($d[3], $this->today->subDays(1), 'MEDICAL', 1200, 'CASH', $entry);

        // One entry rejected so the Rejected status has an example.
        $pending = Income::where('status', 'PENDING')->orderBy('id')->first();
        if ($pending) {
            Workflow::reject($pending, $admin, 'Hours do not match the site register. Please check and resubmit.');
        }

        // Money lent: overdue, due soon, settled, and one without a due date.
        // [name, phone, amount, given N days ago, due in N days (negative = overdue), repayments [days after given, amount]]
        foreach ([
            ['Nagaraj (neighbour)', '9845098765', 50000, 75, -10, [[20, 10000], [45, 10000]]],
            ['Venkatesh, tractor dealer', '9900876543', 30000, 40, 5, [[25, 5000]]],
            ['Shivakumar H (driver)', '9886543210', 15000, 90, -30, [[30, 5000], [60, 5000], [85, 5000]]],
            ['Mahesh Hardware', '9741987654', 100000, 20, null, []],
        ] as [$name, $phone, $amount, $ago, $dueIn, $repaid]) {
            $given = $this->today->subDays($ago);
            $loan = new Loan([
                'borrower_name' => $name, 'phone' => $phone, 'amount' => $amount, 'date' => $given->toDateString(),
                'due_date' => $dueIn === null ? null : $this->today->addDays($dueIn)->toDateString(),
                'mode' => 'CASH', 'notes' => 'Demo entry',
            ]);
            $loan->created_by = $admin->id;
            $loan->save();
            $this->created($loan);
            foreach ($repaid as [$afterDays, $part]) {
                $r = new LoanRepayment(['date' => $given->addDays($afterDays)->min($this->today)->toDateString(), 'amount' => $part, 'mode' => 'CASH']);
                $r->loan_id = $loan->id;
                $r->created_by = $admin->id;
                $r->save();
                $this->created($r);
            }
        }
    }

    private function user(string $username, string $name, string $role, array $capabilities): User
    {
        $user = User::where('username', $username)->first() ?? new User(['username' => $username]);
        $user->fill(['first_name' => $name, 'role' => $role, 'is_active' => true]);
        foreach ($capabilities as $cap) {
            $user->setAttribute("can_{$cap}", true);
        }
        $user->password = self::DEMO_PASSWORD;
        $user->save();

        return $this->created($user);
    }

    private function driverExpense(Driver $driver, CarbonImmutable $day, string $type, mixed $amount, string $mode, User $user): void
    {
        $vehicle = Vehicle::find($driver->assigned_vehicle_id);
        $this->transaction(new Expense([
            'date' => $day->toDateString(), 'category' => 'DRIVER', 'driver_id' => $driver->id, 'driver_expense_type' => $type,
            'vehicle_id' => $vehicle?->id, 'vehicle_category' => $vehicle?->category, 'amount' => $amount, 'payment_mode' => $mode,
        ]), $user);
    }

    /** Stamp like the forms do (status, approver), validate, save and audit. */
    private function transaction(Model $obj, User $user): Model
    {
        Workflow::stampNew($obj, $user);
        $errors = $obj->businessErrors();
        if ($errors) {
            throw new RuntimeException(class_basename($obj).': '.implode(' ', $errors));
        }
        $obj->save();

        return $this->created($obj->fresh());
    }

    private function created(Model $model): Model
    {
        Audit::created($model);

        return $model;
    }
}
