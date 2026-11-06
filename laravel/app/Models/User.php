<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public const AUDIT_NAME = 'User';

    public const ROLES = ['ADMIN' => 'Admin', 'ENTRY' => 'Entry User', 'VIEWER' => 'Viewer'];

    /** Capability flags. Admins implicitly have every capability; Viewers can never write. */
    public const CAPABILITIES = [
        'add_income' => 'Add income entries',
        'add_expense' => 'Add business expenses',
        'add_driver_expense' => 'Add driver expenses',
        'record_payment' => 'Record customer payments',
        'manage_masters' => 'Add / edit vehicles, drivers and customers',
        'view_reports' => 'View financial reports and dashboard totals',
        'export_reports' => 'Export reports (PDF, Excel, CSV)',
        'manage_personal_expenses' => "View and add the owner's personal expenses",
        'mark_attendance' => 'Mark driver attendance and punch times',
    ];

    public const WRITE_CAPABILITIES = ['add_income', 'add_expense', 'add_driver_expense', 'record_payment', 'manage_masters', 'manage_personal_expenses', 'mark_attendance'];

    protected $fillable = [
        'username', 'first_name', 'last_name', 'email', 'phone', 'role', 'is_active',
        'can_add_income', 'can_add_expense', 'can_add_driver_expense', 'can_record_payment',
        'can_manage_masters', 'can_view_reports', 'can_export_reports', 'can_manage_personal_expenses', 'can_mark_attendance',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        $casts = ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
        foreach (array_keys(self::CAPABILITIES) as $name) {
            $casts["can_{$name}"] = 'boolean';
        }

        return $casts;
    }

    public static function capabilityFields(): array
    {
        return array_map(fn ($n) => "can_{$n}", array_keys(self::CAPABILITIES));
    }

    public function __toString(): string
    {
        return $this->fullName() ?: (string) $this->username;
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === 'ADMIN';
    }

    public function isEntryUser(): bool
    {
        return $this->role === 'ENTRY';
    }

    public function isViewer(): bool
    {
        return $this->role === 'VIEWER';
    }

    public function hasCapability(string $name): bool
    {
        if (! array_key_exists($name, self::CAPABILITIES)) {
            throw new \InvalidArgumentException("Unknown capability: {$name}");
        }
        if (! $this->is_active) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if ($this->isViewer() && in_array($name, self::WRITE_CAPABILITIES, true)) {
            return false;
        }

        return (bool) $this->getAttribute("can_{$name}");
    }

    /** Every role sees every record; editing rights are checked separately in canEdit(). */
    public function seesAllRecords(): bool
    {
        return true;
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        $out = [];
        foreach (array_keys(self::CAPABILITIES) as $name) {
            $out[$name] = $this->hasCapability($name);
        }

        return $out;
    }

    public function scopeActiveAdmins(Builder $q): Builder
    {
        return $q->where('role', 'ADMIN')->where('is_active', true);
    }
}
