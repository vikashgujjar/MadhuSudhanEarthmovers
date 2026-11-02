<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hourly quantity is now entered as hours.minutes (1.30 = 1 hr 30 min).
 * Rewrite existing decimal hours (1.50) into that form (1.30). Amounts are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert(fn (int $h, int $frac) => $h * 100 + min(59, (int) round($frac * 60 / 100)));
    }

    public function down(): void
    {
        $this->convert(fn (int $h, int $mins) => $h * 100 + (int) round($mins * 100 / 60));
    }

    /** @param callable(int, int): int $map whole hours and fractional hundredths → new hundredths */
    private function convert(callable $map): void
    {
        DB::table('incomes')->where('billing_type', 'HOURLY')->whereNotNull('quantity')
            ->whereRaw('quantity <> FLOOR(quantity)')->orderBy('id')
            ->each(function ($row) use ($map) {
                $hundredths = (int) round((float) $row->quantity * 100);
                $new = $map(intdiv($hundredths, 100), $hundredths % 100);
                DB::table('incomes')->where('id', $row->id)->update(['quantity' => number_format($new / 100, 2, '.', '')]);
            });
    }
};
