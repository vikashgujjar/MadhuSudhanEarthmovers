<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Money lent to a person, to be collected back through repayments. */
class Loan extends Model
{
    public const AUDIT_NAME = 'Money lent';

    public const STATUSES = ['OPEN' => 'Open', 'OVERDUE' => 'Overdue', 'SETTLED' => 'Settled'];

    protected $fillable = ['date', 'borrower_name', 'phone', 'amount', 'due_date', 'mode', 'address', 'notes'];

    protected $attributes = ['mode' => 'CASH'];

    protected function casts(): array
    {
        return ['date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function __toString(): string
    {
        return $this->number().' · '.$this->borrower_name.' · '.$this->amount;
    }

    public function number(): string
    {
        return sprintf('LN-%05d', $this->id);
    }

    public function modeLabel(): string
    {
        return Payment::MODES[$this->mode] ?? (string) $this->mode;
    }

    /** Adds `repaid` (sum of repayments) as a subquery. */
    public function scopeWithRepaid(Builder $q): Builder
    {
        if ($q->getQuery()->columns === null) {
            $q->select('loans.*');
        }

        return $q->selectSub(
            DB::table('loan_repayments')->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('loan_repayments.loan_id', 'loans.id'),
            'repaid'
        );
    }

    /** Loans with money still to collect (requires no prior select). */
    public function scopeUnsettled(Builder $q): Builder
    {
        return $q->whereRaw('loans.amount > (SELECT COALESCE(SUM(amount), 0) FROM loan_repayments WHERE loan_repayments.loan_id = loans.id)');
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->unsettled()->whereNotNull('loans.due_date')->where('loans.due_date', '<', now()->toDateString());
    }

    public function repaidAmount(): string
    {
        return Money::of($this->getAttribute('repaid') ?? $this->repayments()->sum('amount'));
    }

    public function balance(): string
    {
        return Money::sub($this->amount, $this->repaidAmount());
    }

    public function status(): string
    {
        if (! Money::isPositive($this->balance())) {
            return 'SETTLED';
        }

        return $this->due_date && $this->due_date->lt(now()->startOfDay()) ? 'OVERDUE' : 'OPEN';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status()];
    }

    public function statusBadge(): string
    {
        return ['OPEN' => 'badge-navy', 'OVERDUE' => 'badge-red', 'SETTLED' => 'badge-green'][$this->status()];
    }

    /** Days until due (negative = days overdue), or null without a due date. */
    public function daysToDue(): ?int
    {
        return $this->due_date ? (int) now()->startOfDay()->diffInDays($this->due_date, false) : null;
    }

    public function url(): string
    {
        return route('loans.show', $this);
    }
}
