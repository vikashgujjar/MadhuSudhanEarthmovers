<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverSalaryAdjustment;
use App\Models\Expense;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A driver's salary account. Payments are the driver's existing Expense rows, so
 * every rupee is still entered once. Salary owed and adjustments are not expenses
 * and never enter business figures.
 *
 *   balance = opening + salary per month ± adjustments − approved Salary and Advance paid
 */
final class DriverLedger
{
    /** Expense types that reduce the salary still owed. */
    public const SALARY_TYPES = ['SALARY', 'ADVANCE'];

    /** Row used for the salary in force before the first recorded change. */
    public const BEFORE_FIRST_CHANGE = '2000-01-01';

    /** Everything from the start month to the current month, or null until the cash book is set up. */
    public static function build(Driver $driver): ?array
    {
        if (! $driver->ledger_start_month) {
            return null;
        }
        $start = $driver->ledger_start_month->toImmutable()->startOfMonth();
        $current = now()->toImmutable()->startOfMonth();
        $last = $current->endOfMonth()->toDateString();

        $events = self::salaryEvents($driver, $start, $current);
        foreach (DriverSalaryAdjustment::where('driver_id', $driver->id)->whereBetween('date', [$start->toDateString(), $last])->get() as $a) {
            $paise = Money::toPaise($a->amount) * ($a->kind === 'ADD' ? 1 : -1);
            $events[] = ['date' => $a->date->toImmutable(), 'rank' => 1, 'id' => $a->id, 'kind' => 'adjustment', 'adjustment' => $a,
                'title' => $a->kindLabel(), 'detail' => $a->reason, 'mode' => '', 'url' => null,
                'in' => $paise > 0 ? $paise : 0, 'out' => $paise < 0 ? -$paise : 0, 'other' => 0, 'pending' => 0];
        }
        $expenses = Expense::where('driver_id', $driver->id)->where('category', 'DRIVER')->notRejected()
            ->whereBetween('date', [$start->toDateString(), $last])->get();
        foreach ($expenses as $e) {
            $paise = Money::toPaise($e->amount);
            $counts = in_array($e->driver_expense_type, self::SALARY_TYPES, true);
            $approved = $e->isApproved();
            $events[] = ['date' => $e->date->toImmutable(), 'rank' => 2, 'id' => $e->id, 'kind' => $approved ? ($counts ? 'paid' : 'other') : 'pending',
                'title' => $e->driverTypeLabel(), 'detail' => trim($e->number.($e->description ? ' · '.$e->description : '')), 'mode' => $e->modeLabel(),
                'url' => route('expenses.show', $e->id), 'in' => 0, 'out' => $approved && $counts ? $paise : 0,
                'other' => $approved && ! $counts ? $paise : 0, 'pending' => $approved ? 0 : $paise];
        }
        usort($events, fn ($a, $b) => [$a['date']->toDateString(), $a['rank'], $a['id']] <=> [$b['date']->toDateString(), $b['rank'], $b['id']]);

        $opening = Money::toPaise($driver->opening_balance);
        $months = [];
        for ($m = $start; $m->lte($current); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m, 'opening' => 0, 'in' => 0, 'out' => 0, 'other' => 0, 'pending' => 0, 'closing' => 0];
        }
        $balance = $opening;
        $entries = [];
        foreach ($events as $event) {
            $key = $event['date']->format('Y-m');
            $balance += $event['in'] - $event['out'];
            foreach (['in', 'out', 'other', 'pending'] as $field) {
                $months[$key][$field] += $event[$field];
            }
            $entries[] = $event + ['balance' => $balance];
        }
        $running = $opening;
        foreach ($months as $key => &$month) {
            $month['opening'] = $running;
            $running += $month['in'] - $month['out'];
            $month['closing'] = $running;
        }
        unset($month);

        return ['opening' => $opening, 'balance' => $balance, 'months' => $months, 'entries' => $entries];
    }

    /** One salary credit on the 1st of each month until the last salary month. */
    private static function salaryEvents(Driver $driver, CarbonImmutable $start, CarbonImmutable $current): array
    {
        $changes = DB::table('driver_salary_changes')->where('driver_id', $driver->id)->orderBy('effective_month')->get();
        $end = $driver->ledger_end_month?->toImmutable()->startOfMonth();
        $events = [];
        for ($m = $start, $n = 0; $m->lte($current) && (! $end || $m->lte($end)); $m = $m->addMonth(), $n++) {
            $row = $changes->last(fn ($c) => substr($c->effective_month, 0, 10) <= $m->toDateString());
            $paise = Money::toPaise($row->monthly_salary ?? $driver->monthly_salary);
            $events[] = ['date' => $m, 'rank' => 0, 'id' => $n, 'kind' => 'salary', 'title' => 'Salary for '.$m->format('F Y'), 'detail' => '', 'mode' => '',
                'url' => null, 'in' => $paise, 'out' => 0, 'other' => 0, 'pending' => 0];
        }

        return $events;
    }

    /** Called when a driver's salary is edited: the new figure applies from the current month. */
    public static function recordSalaryChange(Driver $driver, string|int|float|null $old, string|int|float|null $new): void
    {
        $exists = DB::table('driver_salary_changes')->where('driver_id', $driver->id)->exists();
        if (! $exists) {
            DB::table('driver_salary_changes')->insert(['driver_id' => $driver->id, 'effective_month' => self::BEFORE_FIRST_CHANGE, 'monthly_salary' => Money::of($old)]);
        }
        DB::table('driver_salary_changes')->updateOrInsert(
            ['driver_id' => $driver->id, 'effective_month' => now()->startOfMonth()->toDateString()],
            ['monthly_salary' => Money::of($new)],
        );
    }

    public static function money(int $paise): string
    {
        return Money::fromPaise($paise);
    }
}
