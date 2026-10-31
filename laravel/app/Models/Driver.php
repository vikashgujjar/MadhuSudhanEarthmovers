<?php

namespace App\Models;

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

    protected $attributes = ['status' => 'ACTIVE', 'monthly_salary' => '0.00', 'daily_allowance' => '0.00'];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date', 'joining_date' => 'date',
            'monthly_salary' => 'decimal:2', 'daily_allowance' => 'decimal:2',
        ];
    }

    public function assignedVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'assigned_vehicle_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
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
