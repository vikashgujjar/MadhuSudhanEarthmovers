<?php

namespace App\Models\Concerns;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared behaviour of approvable financial records (income, expense, payment).
 *
 * Only APPROVED records count toward dashboards, balances and reports.
 */
trait IsTransaction
{
    public const STATUSES = ['PENDING' => 'Pending approval', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'];

    public static function bootIsTransaction(): void
    {
        static::saving(function ($model) {
            $model->amount = Money::of($model->amount);
        });
        static::created(function ($model) {
            if (! $model->number) {
                $prefix = BusinessSetting::current()->getAttribute(static::NUMBER_PREFIX_FIELD);
                $model->number = sprintf('%s-%05d', $prefix, $model->id);
                $model->newQuery()->whereKey($model->id)->update(['number' => $model->number]);
                $model->syncOriginalAttribute('number');
            }
        });
    }

    public function initializeIsTransaction(): void
    {
        $this->mergeCasts(['date' => 'date', 'amount' => 'decimal:2', 'approved_at' => 'datetime']);
        if (! isset($this->attributes['status'])) {
            $this->attributes['status'] = 'PENDING';
        }
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where($this->qualifyColumn('status'), 'APPROVED');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where($this->qualifyColumn('status'), 'PENDING');
    }

    public function scopeNotRejected(Builder $q): Builder
    {
        return $q->where($this->qualifyColumn('status'), '!=', 'REJECTED');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->seesAllRecords() ? $q : $q->where($this->qualifyColumn('created_by'), $user->id);
    }

    public function scopeBetween(Builder $q, ?string $start, ?string $end): Builder
    {
        if ($start) {
            $q->where($this->qualifyColumn('date'), '>=', $start);
        }
        if ($end) {
            $q->where($this->qualifyColumn('date'), '<=', $end);
        }

        return $q;
    }

    /** Sum of `amount` for a query, as an exact 2-decimal string. */
    public static function totalOf(Builder $q): string
    {
        return Money::of((clone $q)->sum($q->getModel()->qualifyColumn('amount')));
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function canEdit(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return (int) $this->created_by === (int) $user->id && $this->status !== 'APPROVED' && ! $user->isViewer();
    }

    /** Can `user` open this record's detail page? (Entry Users: own records only.) */
    public function isVisibleTo(User $user): bool
    {
        return $user->seesAllRecords() || (int) $this->created_by === (int) $user->id;
    }
}
