<?php

use App\Support\Money;

if (! function_exists('inr')) {
    /** Indian digit grouping with 2 decimals: 12,34,567.00 */
    function inr(string|int|float|null $value): string
    {
        return Money::inr($value);
    }
}

if (! function_exists('inr0')) {
    function inr0(string|int|float|null $value): string
    {
        return Money::inr($value, 0);
    }
}

if (! function_exists('status_badge')) {
    function status_badge(?string $status): string
    {
        return [
            'APPROVED' => 'badge-green', 'PENDING' => 'badge-amber', 'REJECTED' => 'badge-red',
            'ACTIVE' => 'badge-green', 'MAINTENANCE' => 'badge-amber', 'INACTIVE' => 'badge-slate',
        ][$status] ?? 'badge-slate';
    }
}

if (! function_exists('query_with')) {
    /** Current URL with some query parameters replaced (null removes one). */
    function query_with(array $replace): string
    {
        return request()->fullUrlWithQuery($replace);
    }
}

if (! function_exists('nav_active')) {
    /** "active" when the current path starts with one of the prefixes. */
    function nav_active(string|array $prefixes, bool $exact = false, ?string $exclude = null): string
    {
        $path = '/'.ltrim(request()->path(), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/').'/';
        if ($exclude && str_starts_with($path, $exclude)) {
            return '';
        }
        foreach ((array) $prefixes as $prefix) {
            if (($exact && $path === $prefix) || (! $exact && str_starts_with($path, $prefix))) {
                return 'active';
            }
        }

        return '';
    }
}

if (! function_exists('plural')) {
    function plural(int $count, string $singular = '', string $plural = 's'): string
    {
        return $count === 1 ? $singular : $plural;
    }
}

if (! function_exists('qty')) {
    /** 7.50 -> "7.5", 3.00 -> "3" */
    function qty(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}

if (! function_exists('json_for_script')) {
    /** JSON safe to place inside <script type="application/json">. */
    function json_for_script(mixed $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}
