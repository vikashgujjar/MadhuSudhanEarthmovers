<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A driver's attendance for one day. A record only; it does not change salary or expense figures. */
class DriverAttendance extends Model
{
    public const AUDIT_NAME = 'Driver attendance';

    public const STATUSES = ['PRESENT' => 'Present', 'HALF_DAY' => 'Half day', 'ABSENT' => 'Absent', 'LEAVE' => 'On leave'];

    /** Statuses where the driver did not work, so punch times do not apply. */
    public const NOT_WORKING = ['ABSENT', 'LEAVE'];

    protected $table = 'driver_attendances';

    protected $fillable = ['driver_id', 'date', 'status', 'punch_in', 'punch_out', 'notes'];

    protected function casts(): array
    {
        return ['date' => 'date', 'self_marked' => 'boolean'];
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
        return ($this->driver?->name ?? 'Driver').' · '.$this->date?->format('d M Y').' · '.$this->statusLabel();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function statusBadge(): string
    {
        return ['PRESENT' => 'badge-green', 'HALF_DAY' => 'badge-orange', 'ABSENT' => 'badge-red', 'LEAVE' => 'badge-slate'][$this->status] ?? 'badge-slate';
    }

    /** Minutes between punch in and punch out, or null until both are recorded. */
    public function workedMinutes(): ?int
    {
        if (! $this->punch_in || ! $this->punch_out) {
            return null;
        }

        return (int) ((strtotime($this->punch_out) - strtotime($this->punch_in)) / 60);
    }

    public function workedLabel(): string
    {
        $minutes = $this->workedMinutes();

        return $minutes === null ? '' : self::durationLabel($minutes);
    }

    public static function timeLabel(?string $time): string
    {
        return $time ? date('h:i A', strtotime($time)) : '';
    }

    /** 510 → "8 hr 30 min". */
    public static function durationLabel(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? $h.' hr ' : '').($m || ! $h ? $m.' min' : ''));
    }

    /** First day of the `YYYY-MM` month given, or of the current month. */
    public static function monthFrom(mixed $value): CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
            ? CarbonImmutable::createFromFormat('!Y-m', $value)
            : now()->toImmutable()->startOfMonth();
    }

    /** Day counts per status plus total worked minutes. @param iterable<self> $records */
    public static function summarise(iterable $records): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0) + ['minutes' => 0];
        foreach ($records as $record) {
            $out[$record->status] = ($out[$record->status] ?? 0) + 1;
            $out['minutes'] += $record->workedMinutes() ?? 0;
        }

        return $out;
    }
}
