<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The owner's personal spending. Kept on record and audited, but never part of
 * business income, expenses, profit, the Cash Book or the dashboard.
 */
class PersonalExpense extends Model
{
    public const AUDIT_NAME = 'Personal expense';

    public const CATEGORIES = [
        'HOUSEHOLD' => 'Household & groceries', 'BILLS' => 'Bills & utilities', 'RENT_EMI' => 'Rent / EMI',
        'EDUCATION' => 'Education & fees', 'MEDICAL' => 'Medical', 'TRAVEL' => 'Travel & fuel',
        'SHOPPING' => 'Shopping & clothing', 'FAMILY' => 'Family & functions', 'INSURANCE' => 'Insurance',
        'OTHER' => 'Other',
    ];

    protected $fillable = ['date', 'category', 'amount', 'mode', 'paid_to', 'description', 'notes'];

    protected $attributes = ['mode' => 'CASH'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function __toString(): string
    {
        return $this->number().' · '.$this->categoryLabel().' · '.$this->amount;
    }

    public function number(): string
    {
        return sprintf('PE-%05d', $this->id);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? (string) $this->category;
    }

    public function modeLabel(): string
    {
        return Payment::MODES[$this->mode] ?? (string) $this->mode;
    }

    public function url(): string
    {
        return route('personal-expenses.show', $this);
    }
}
