<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    public const AUDIT_NAME = 'Vehicle';

    public const CATEGORIES = ['JCB' => 'JCB', 'TRACTOR' => 'Tractor'];

    public const STATUSES = ['ACTIVE' => 'Active', 'MAINTENANCE' => 'Under maintenance', 'INACTIVE' => 'Inactive / sold'];

    protected $fillable = [
        'category', 'registration_number', 'name', 'make', 'model', 'year', 'chassis_number', 'engine_number',
        'purchase_date', 'purchase_cost', 'hourly_rate', 'daily_rate', 'insurance_expiry', 'status', 'notes',
    ];

    protected $attributes = ['status' => 'ACTIVE', 'hourly_rate' => '0.00', 'daily_rate' => '0.00'];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date', 'insurance_expiry' => 'date', 'purchase_cost' => 'decimal:2',
            'hourly_rate' => 'decimal:2', 'daily_rate' => 'decimal:2', 'year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vehicle $v) {
            $v->registration_number = self::normalisePlate((string) $v->registration_number);
        });
    }

    public static function normalisePlate(string $plate): string
    {
        return implode(' ', preg_split('/\s+/', mb_strtoupper(trim($plate)), -1, PREG_SPLIT_NO_EMPTY));
    }

    /** "KA 01 AB 1234", "KA01AB1234" and "ka-01-ab-1234" are the same plate. */
    public static function compactPlate(string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($plate));
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class, 'assigned_vehicle_id');
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function __toString(): string
    {
        $label = $this->name ? "{$this->name} ({$this->registration_number})" : (string) $this->registration_number;

        return $this->categoryLabel().' · '.$label;
    }

    public function displayName(): string
    {
        return $this->name ?: (string) $this->registration_number;
    }

    /** Label used in drop-downs. */
    public function optionLabel(): string
    {
        return $this->name ? "{$this->name} · {$this->registration_number}" : (string) $this->registration_number;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) $this->category;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function isUsable(): bool
    {
        return $this->status !== 'INACTIVE';
    }
}
