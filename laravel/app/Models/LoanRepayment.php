<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received back against a loan. */
class LoanRepayment extends Model
{
    public const AUDIT_NAME = 'Loan repayment';

    protected $fillable = ['date', 'amount', 'mode', 'notes'];

    protected $attributes = ['mode' => 'CASH'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function __toString(): string
    {
        return 'Repayment · '.($this->loan?->borrower_name ?? '').' · '.$this->amount;
    }

    public function modeLabel(): string
    {
        return Payment::MODES[$this->mode] ?? (string) $this->mode;
    }
}
