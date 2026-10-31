<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    public const AUDIT_NAME = 'Customer';

    protected $fillable = [
        'name', 'contact_person', 'phone', 'email', 'gstin', 'opening_balance', 'is_active', 'address', 'notes',
    ];

    protected $attributes = ['opening_balance' => '0.00', 'is_active' => true];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function incomes(): HasMany
    {
        return $this->hasMany(Income::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    /**
     * Add `billed` and `received` (approved totals) as correlated subqueries, so
     * income and payment sums never multiply each other through a join.
     */
    public function scopeWithBalances(Builder $q, ?string $end = null): Builder
    {
        $sum = function (string $table) use ($end) {
            $sub = DB::table($table)->selectRaw('COALESCE(SUM(amount), 0)')
                ->whereColumn("{$table}.customer_id", 'customers.id')
                ->where("{$table}.status", 'APPROVED');
            if ($end) {
                $sub->where("{$table}.date", '<=', $end);
            }

            return $sub;
        };
        if ($q->getQuery()->columns === null) {
            $q->select('customers.*');
        }

        return $q->selectSub($sum('incomes'), 'billed')->selectSub($sum('payments'), 'received');
    }

    /** opening + billed − received (requires withBalances). */
    public function balance(): string
    {
        return \App\Support\Money::sub(\App\Support\Money::add($this->opening_balance, $this->billed), $this->received);
    }

    public function outstanding(): string
    {
        $billed = $this->incomes()->approved()->sum('amount');
        $received = $this->payments()->approved()->sum('amount');

        return \App\Support\Money::sub(\App\Support\Money::add($this->opening_balance, $billed), $received);
    }
}
