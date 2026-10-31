<?php

namespace App\Models;

use App\Models\Concerns\IsTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every rupee spent is exactly one Expense row. A driver expense is an
 * Expense with category DRIVER that also references a driver (and optionally a
 * vehicle), so it is counted once in total expenses, once in that vehicle's
 * expenses and once in that driver's total — never twice in a single total.
 */
class Expense extends Model
{
    use IsTransaction;

    public const AUDIT_NAME = 'Expense';

    public const NUMBER_PREFIX_FIELD = 'expense_prefix';

    public const CATEGORIES = [
        'DIESEL' => 'Diesel', 'MAINTENANCE' => 'Maintenance', 'REPAIR' => 'Repairs', 'SPARE_PARTS' => 'Spare parts',
        'DRIVER' => 'Driver expense', 'OTHER' => 'Other business expense',
    ];

    public const DRIVER_TYPES = [
        'SALARY' => 'Salary', 'ADVANCE' => 'Advance', 'BATA' => 'Daily allowance (bata)', 'FOOD' => 'Food',
        'TRAVEL' => 'Travel', 'MEDICAL' => 'Medical', 'OTHER' => 'Other',
    ];

    public const VEHICLE_REQUIRED = ['DIESEL', 'MAINTENANCE', 'REPAIR', 'SPARE_PARTS'];

    protected $fillable = [
        'date', 'category', 'vehicle_category', 'vehicle_id', 'driver_id', 'driver_expense_type', 'litres', 'amount',
        'payment_mode', 'vendor', 'bill_number', 'description',
    ];

    protected $attributes = ['payment_mode' => 'CASH'];

    protected function casts(): array
    {
        return ['litres' => 'decimal:2'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function __toString(): string
    {
        return ($this->number ?: 'Expense').' · '.$this->categoryLabel().' · '.$this->amount;
    }

    public function isDriverExpense(): bool
    {
        return $this->category === 'DRIVER';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) $this->category;
    }

    public function driverTypeLabel(): string
    {
        return self::DRIVER_TYPES[$this->driver_expense_type] ?? '';
    }

    public function vehicleCategoryLabel(): string
    {
        return Vehicle::CATEGORIES[$this->vehicle_category] ?? '';
    }

    public function modeLabel(): string
    {
        return Payment::MODES[$this->payment_mode] ?? (string) $this->payment_mode;
    }

    /** @return array<string,string> */
    public function businessErrors(): array
    {
        $errors = [];
        if ($this->vehicle_id) {
            $vehicle = Vehicle::find($this->vehicle_id);
            if ($vehicle) {
                if (! $this->vehicle_category) {
                    $this->vehicle_category = $vehicle->category;
                } elseif ($vehicle->category !== $this->vehicle_category) {
                    $errors['vehicle_id'] = 'This vehicle does not belong to the selected category.';
                }
            }
        }
        if (in_array($this->category, self::VEHICLE_REQUIRED, true) && ! $this->vehicle_id) {
            $errors['vehicle_id'] = $this->categoryLabel().' expenses must be linked to a vehicle.';
        }
        if ($this->category === 'DRIVER') {
            if (! $this->driver_id) {
                $errors['driver_id'] = 'Select the driver this expense belongs to.';
            }
            if (! $this->driver_expense_type) {
                $errors['driver_expense_type'] = 'Select the type of driver expense.';
            }
        } else {
            $this->driver_expense_type = null;
        }
        if ($this->category !== 'DIESEL') {
            $this->litres = null;
        }
        if ($this->amount === null || $this->amount === '' || ! Money::isPositive($this->amount)) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        }

        return $errors;
    }

    public function url(): string
    {
        return route('expenses.show', $this);
    }
}
