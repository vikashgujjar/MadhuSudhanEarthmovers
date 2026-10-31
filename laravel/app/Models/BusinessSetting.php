<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row organisation profile. Every screen, report and export reads the
 * organisation name and logo from here, so it is changed in one place.
 */
class BusinessSetting extends Model
{
    public const AUDIT_NAME = 'Business Settings';

    public const DEFAULT_NAME = 'Madhusudhan Earthmover';

    public const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    protected $fillable = [
        'business_name', 'erp_title', 'tagline', 'owner_name', 'address', 'phone', 'email', 'gstin', 'pan',
        'currency_symbol', 'financial_year_start_month', 'invoice_prefix', 'receipt_prefix', 'expense_prefix',
        'opening_cash_balance', 'require_approval', 'report_footer',
    ];

    protected $attributes = [
        'business_name' => self::DEFAULT_NAME,
        'erp_title' => self::DEFAULT_NAME.' ERP',
        'tagline' => 'JCB, Tractor, Earthmoving & Equipment Services',
        'currency_symbol' => '₹',
        'financial_year_start_month' => 4,
        'invoice_prefix' => 'ME-INV',
        'receipt_prefix' => 'ME-RCT',
        'expense_prefix' => 'ME-EXP',
        'opening_cash_balance' => '0.00',
        'require_approval' => true,
        'report_footer' => 'This is a system-generated report.',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash_balance' => 'decimal:2',
            'require_approval' => 'boolean',
            'financial_year_start_month' => 'integer',
        ];
    }

    private static ?self $cached = null;

    /** The settings row (created on first use). Cached per request. */
    public static function current(): self
    {
        if (self::$cached === null) {
            try {
                self::$cached = self::query()->find(1) ?? tap(new self, function (self $s) {
                    $s->id = 1;
                    $s->save();
                });
            } catch (\Illuminate\Database\QueryException) {
                // Database not installed yet (installer): use defaults without saving.
                return new self;
            }
        }

        return self::$cached;
    }

    public static function forgetCache(): void
    {
        self::$cached = null;
    }

    public function save(array $options = []): bool
    {
        // Single row: saving any instance updates row 1.
        if (! $this->exists && static::query()->whereKey(1)->exists()) {
            $this->exists = true;
        }
        $this->id = 1;
        $saved = parent::save($options);
        self::$cached = $this;

        return $saved;
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Business settings cannot be deleted.');
    }

    public function __toString(): string
    {
        return (string) $this->business_name;
    }

    public function initials(): string
    {
        $words = array_filter(preg_split('/\s+/', (string) $this->business_name), fn ($w) => ctype_alpha(mb_substr($w, 0, 1)));
        $initials = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(array_values($words), 0, 2)));

        return mb_strtoupper($initials) ?: 'ME';
    }

    /** First word of the name, used for the large line of the wordmark. */
    public function namePrimary(): string
    {
        return explode(' ', (string) $this->business_name, 2)[0];
    }

    public function nameSecondary(): string
    {
        return explode(' ', (string) $this->business_name, 2)[1] ?? '';
    }

    public function contactLine(): string
    {
        return implode(' | ', array_filter([$this->phone, $this->email, $this->gstin ? "GSTIN: {$this->gstin}" : '']));
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? route('media', ['path' => $this->logo]).'?v='.optional($this->updated_at)->timestamp : null;
    }
}
