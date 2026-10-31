<?php

namespace App\Models;

use App\Models\Concerns\IsTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received from a customer: against an invoice, or as an advance. */
class Payment extends Model
{
    use IsTransaction;

    public const AUDIT_NAME = 'Payment';

    public const NUMBER_PREFIX_FIELD = 'receipt_prefix';

    public const MODES = ['CASH' => 'Cash', 'BANK' => 'Bank transfer', 'UPI' => 'UPI', 'CHEQUE' => 'Cheque'];

    protected $fillable = ['date', 'customer_id', 'income_id', 'amount', 'mode', 'reference', 'notes'];

    protected $attributes = ['mode' => 'CASH', 'is_initial' => false];

    protected function casts(): array
    {
        return ['is_initial' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function income(): BelongsTo
    {
        return $this->belongsTo(Income::class);
    }

    public function __toString(): string
    {
        return ($this->number ?: 'Payment').' · '.($this->customer?->name ?? '').' · '.$this->amount;
    }

    public function modeLabel(): string
    {
        return self::MODES[$this->mode] ?? (string) $this->mode;
    }

    /** @return array<string,string> */
    public function businessErrors(): array
    {
        $errors = [];
        if ($this->amount === null || $this->amount === '' || ! Money::isPositive($this->amount)) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        }
        if ($this->income_id) {
            $income = Income::find($this->income_id);
            if (! $income) {
                $errors['income_id'] = 'Select a valid invoice.';
            } elseif ($income->status === 'REJECTED') {
                $errors['income_id'] = 'Payments cannot be recorded against a rejected income entry.';
            } elseif ($this->customer_id && (int) $income->customer_id !== (int) $this->customer_id) {
                $errors['income_id'] = 'This invoice belongs to a different customer.';
            } elseif (! isset($errors['amount'])) {
                $others = $income->payments()->notRejected();
                if ($this->exists) {
                    $others->whereKeyNot($this->id);
                }
                $remaining = Money::sub($income->amount, self::totalOf($others->getQuery()));
                if (Money::cmp($this->amount, $remaining) > 0) {
                    $errors['amount'] = "Amount exceeds the balance due on {$income->number} ({$remaining}).";
                }
            }
        }

        return $errors;
    }

    public function url(): string
    {
        return route('payments.show', $this);
    }
}
