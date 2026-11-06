<?php

namespace App\Models;

use App\Services\DriverLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    public const AUDIT_NAME = 'Driver';

    public const STATUSES = ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'];

    protected $fillable = [
        'name', 'phone', 'alternate_phone', 'license_number', 'license_expiry', 'assigned_vehicle_id',
        'monthly_salary', 'daily_allowance', 'joining_date', 'status', 'address', 'notes',
    ];

    protected $hidden = ['attendance_token'];

    protected $attributes = ['status' => 'ACTIVE', 'monthly_salary' => '0.00', 'daily_allowance' => '0.00'];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date', 'joining_date' => 'date',
            'monthly_salary' => 'decimal:2', 'daily_allowance' => 'decimal:2',
            'ledger_start_month' => 'date', 'ledger_end_month' => 'date', 'opening_balance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Driver $d) {
            // Before the cash book starts there are no months to protect, so a corrected salary applies throughout.
            if ($d->isDirty('monthly_salary') && $d->ledger_start_month) {
                DriverLedger::recordSalaryChange($d, $d->getOriginal('monthly_salary'), $d->monthly_salary);
            }
            // An inactive driver stops earning; a final settlement can then be paid.
            if ($d->isDirty('status') && $d->status === 'INACTIVE' && $d->ledger_start_month && ! $d->ledger_end_month) {
                $d->ledger_end_month = now()->startOfMonth()->toDateString();
            }
        });
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(DriverAttendance::class);
    }

    public function __toString(): string
    {
        return $this->phone ? "{$this->name} ({$this->phone})" : (string) $this->name;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }
}
