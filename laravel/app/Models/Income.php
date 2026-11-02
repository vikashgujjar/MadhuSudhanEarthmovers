<?php

namespace App\Models;

use App\Models\Concerns\IsTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Billed work (an invoice). Billed income is never mixed with money received:
 * profit uses billed income, the Cash Book uses payments.
 */
class Income extends Model
{
    use IsTransaction;

    public const AUDIT_NAME = 'Income Entry';

    public const NUMBER_PREFIX_FIELD = 'invoice_prefix';

    public const BILLING_TYPES = [
        'HOURLY' => 'Hourly billing', 'DAILY' => 'Daily billing', 'FIXED' => 'Fixed contract', 'MANUAL' => 'Manual amount',
    ];

    public const QUANTITY_BILLING = ['HOURLY', 'DAILY'];

    protected $fillable = [
        'date', 'vehicle_category', 'vehicle_id', 'customer_id', 'driver_id', 'billing_type', 'quantity', 'rate',
        'amount', 'work_location', 'description',
    ];

    protected $attributes = ['billing_type' => 'HOURLY'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'rate' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Income $i) => $i->computeAmount());
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function __toString(): string
    {
        return ($this->number ?: 'Income').' · '.($this->customer?->name ?? '').' · '.$this->amount;
    }

    public function isQuantityBilling(): bool
    {
        return in_array($this->billing_type, self::QUANTITY_BILLING, true);
    }

    public function unitLabel(): string
    {
        return ['HOURLY' => 'hrs', 'DAILY' => 'days'][$this->billing_type] ?? '';
    }

    /** "1 hr 30 min" for hourly billing (quantity is hours.minutes), "3 days" for daily. */
    public function quantityLabel(): string
    {
        if ($this->quantity === null || $this->quantity === '') {
            return '';
        }
        if ($this->billing_type !== 'HOURLY') {
            return qty($this->quantity).' '.$this->unitLabel();
        }
        $minutes = Money::toMinutes($this->quantity);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? $h.' hr ' : '').($m ? $m.' min' : ''));
    }

    public function billingLabel(): string
    {
        return self::BILLING_TYPES[$this->billing_type] ?? (string) $this->billing_type;
    }

    public function categoryLabel(): string
    {
        return Vehicle::CATEGORIES[$this->vehicle_category] ?? (string) $this->vehicle_category;
    }

    public function computeAmount(): void
    {
        if ($this->isQuantityBilling() && Money::isPositive($this->quantity) && $this->rate !== null && $this->rate !== '') {
            $this->amount = $this->billing_type === 'HOURLY'
                ? Money::multiplyHours($this->quantity, $this->rate)
                : Money::multiply($this->quantity, $this->rate);
        }
    }

    /** Business-rule validation (the equivalent of a model clean()). @return array<string,string> */
    public function businessErrors(): array
    {
        $errors = [];
        if ($this->vehicle_id && $this->vehicle_category) {
            $vehicle = Vehicle::find($this->vehicle_id);
            if ($vehicle && $vehicle->category !== $this->vehicle_category) {
                $errors['vehicle_id'] = 'This vehicle does not belong to the selected category.';
            }
        }
        if ($this->isQuantityBilling()) {
            $unit = $this->billing_type === 'HOURLY' ? 'hour' : 'day';
            if (! Money::isPositive($this->quantity)) {
                $errors['quantity'] = "Enter the number of {$unit}s worked.";
            } elseif ($this->billing_type === 'HOURLY' && ! Money::isValidHoursMinutes($this->quantity)) {
                $errors['quantity'] = 'Enter hours.minutes with minutes from 00 to 59, e.g. 1.30 for 1 hr 30 min.';
            }
            if ($this->rate === null || $this->rate === '' || ! Money::isPositive($this->rate)) {
                $errors['rate'] = "Enter the rate per {$unit}.";
            }
        } else {
            $this->quantity = null;
            $this->rate = null;
            if ($this->amount === null || $this->amount === '' || ! Money::isPositive($this->amount)) {
                $errors['amount'] = 'Enter an amount greater than zero.';
            }
        }
        if (! $errors) {
            $this->computeAmount();
            if (! Money::isPositive($this->amount)) {
                $errors['amount'] = 'The billed amount must be greater than zero.';
            } elseif ($this->exists) {
                $received = Payment::totalOf($this->payments()->notRejected()->getQuery());
                if (Money::cmp($this->amount, $received) < 0) {
                    $errors['amount'] = "Amount cannot be less than the {$received} already recorded as received against it.";
                }
            }
        }

        return $errors;
    }

    /** Collection figures count approved payments only. */
    public function amountReceived(): string
    {
        return Payment::totalOf($this->payments()->approved()->getQuery());
    }

    public function outstanding(): string
    {
        return Money::sub($this->amount, $this->amountReceived());
    }

    public function paymentState(): string
    {
        $received = $this->amountReceived();
        if (! Money::isPositive($received)) {
            return 'Unpaid';
        }

        return Money::cmp($received, $this->amount) >= 0 ? 'Paid' : 'Partially paid';
    }

    public function url(): string
    {
        return route('income.show', $this);
    }
}
