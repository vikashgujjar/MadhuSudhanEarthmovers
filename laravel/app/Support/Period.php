<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Reporting periods (financial-year aware). */
final class Period
{
    public const CHOICES = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_fy' => 'This financial year',
        'last_fy' => 'Last financial year',
        'all' => 'All time',
        'custom' => 'Custom range',
    ];

    public function __construct(
        public readonly string $key,
        public readonly ?CarbonImmutable $start,
        public readonly ?CarbonImmutable $end,
        public readonly string $label,
    ) {}

    public function startDate(): ?string
    {
        return $this->start?->toDateString();
    }

    public function endDate(): ?string
    {
        return $this->end?->toDateString();
    }

    public function querystring(): string
    {
        if ($this->key === 'custom') {
            return http_build_query(['period' => 'custom', 'from' => $this->startDate() ?? '', 'to' => $this->endDate() ?? '']);
        }

        return 'period='.$this->key;
    }

    public static function fyStart(CarbonImmutable $today, int $startMonth): CarbonImmutable
    {
        $year = $today->month >= $startMonth ? $today->year : $today->year - 1;

        return CarbonImmutable::create($year, $startMonth, 1)->startOfDay();
    }

    public static function resolve(Request|array $params, int $startMonth = 4, string $default = 'this_fy'): self
    {
        $get = fn (string $k) => $params instanceof Request ? $params->query($k) : ($params[$k] ?? null);
        $today = CarbonImmutable::today();
        $key = $get('period') ?: $default;
        if (! is_string($key) || ! array_key_exists($key, self::CHOICES)) {
            $key = $default;
        }

        switch ($key) {
            case 'this_month':
                $start = $today->startOfMonth();

                return new self($key, $start, $today, $start->format('F Y'));
            case 'last_month':
                $start = $today->startOfMonth()->subMonthNoOverflow();

                return new self($key, $start, $start->endOfMonth()->startOfDay(), $start->format('F Y'));
            case 'this_fy':
            case 'last_fy':
                $start = self::fyStart($today, $startMonth);
                if ($key === 'last_fy') {
                    $start = $start->subYear();
                }
                $end = $start->addYear()->subDay();
                $label = $startMonth !== 1 ? 'FY '.$start->year.'-'.substr((string) $end->year, -2) : 'FY '.$start->year;

                return new self($key, $start, $key === 'this_fy' && $end->greaterThan($today) ? $today : $end, $label);
            case 'custom':
                $start = self::parseDate($get('from'));
                $end = self::parseDate($get('to'));
                if ($start && $end && $start->greaterThan($end)) {
                    [$start, $end] = [$end, $start];
                }
                if ($start && $end) {
                    $label = $start->format('d M Y').' to '.$end->format('d M Y');
                } elseif ($start) {
                    $label = 'From '.$start->format('d M Y');
                } elseif ($end) {
                    $label = 'Up to '.$end->format('d M Y');
                } else {
                    $label = 'All time';
                }

                return new self($key, $start, $end, $label);
        }

        return new self('all', null, null, 'All time');
    }

    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
