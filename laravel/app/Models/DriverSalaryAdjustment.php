<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bonus or deduction on what a driver is owed. No money moves, so it is not a business expense. */
class DriverSalaryAdjustment extends Model
{
    public const AUDIT_NAME = 'Driver salary adjustment';

    public const KINDS = ['ADD' => 'Add to salary (bonus)', 'DEDUCT' => 'Deduct from salary'];

    protected $fillable = ['driver_id', 'date', 'kind', 'amount', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function __toString(): string
    {
        return ($this->driver?->name ?? 'Driver').' · '.$this->date?->format('d M Y').' · '.$this->kindLabel().' · '.$this->amount;
    }

    public function kindLabel(): string
    {
        return $this->kind === 'ADD' ? 'Bonus' : 'Deduction';
    }
}
