<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Financial aggregations shared by the dashboard, reports and exports.
 * All figures come from APPROVED database rows; this is the single place
 * where totals are computed.
 */
final class Finance
{
    /** @return array<string,string> key => summed amount */
    private static function sumBy(Builder $q, string $column): array
    {
        $out = [];
        foreach ((clone $q)->reorder()->selectRaw("{$column} as k, SUM(amount) as t")->groupByRaw($column)->get() as $row) {
            $out[(string) ($row->k ?? '')] = Money::of($row->t);
        }

        return $out;
    }

    public static function financialSummary(?string $start = null, ?string $end = null): array
    {
        $incomes = Income::query()->approved()->between($start, $end);
        $expenses = Expense::query()->approved()->between($start, $end);
        $payments = Payment::query()->approved()->between($start, $end);

        $incomeTotal = Income::totalOf($incomes);
        $expenseTotal = Expense::totalOf($expenses);
        $receivedTotal = Payment::totalOf($payments);

        $incomeByCat = self::sumBy($incomes, 'vehicle_category');
        // Expenses are split by the category of the vehicle they are linked to;
        // expenses without a vehicle are general overheads. The parts sum
        // exactly to the expense total.
        $vehicleExpenses = (clone $expenses)->join('vehicles', 'vehicles.id', '=', 'expenses.vehicle_id')
            ->selectRaw('vehicles.category as k, SUM(expenses.amount) as t')->groupBy('vehicles.category')->get();
        $expenseByVcat = [];
        foreach ($vehicleExpenses as $row) {
            $expenseByVcat[$row->k] = Money::of($row->t);
        }
        $generalExpense = Expense::totalOf((clone $expenses)->whereNull('vehicle_id'));

        $categories = [];
        foreach (Vehicle::CATEGORIES as $key => $label) {
            $inc = $incomeByCat[$key] ?? Money::ZERO;
            $exp = $expenseByVcat[$key] ?? Money::ZERO;
            $categories[$key] = ['label' => $label, 'income' => $inc, 'expense' => $exp, 'profit' => Money::sub($inc, $exp)];
        }
        $net = Money::sub($incomeTotal, $expenseTotal);

        return [
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net_profit' => $net,
            'received_total' => $receivedTotal,
            'outstanding_total' => self::outstandingTotal($end),
            'general_expense' => $generalExpense,
            'driver_expense_total' => Expense::totalOf((clone $expenses)->where('category', 'DRIVER')),
            'categories' => $categories,
            'expense_by_category' => self::expenseBreakdown($expenses),
            'income_count' => (clone $incomes)->count(),
            'expense_count' => (clone $expenses)->count(),
            'collection_rate' => Money::percent($receivedTotal, $incomeTotal),
            'margin' => Money::percent($net, $incomeTotal),
        ];
    }

    /**
     * What customers owe as of `end` (opening balances included). Customers in
     * credit (advance paid) are not netted against debtors.
     */
    public static function outstandingTotal(?string $end = null): string
    {
        $total = 0;
        foreach (Customer::query()->withBalances($end)->get() as $c) {
            $balance = Money::toPaise($c->balance());
            if ($balance > 0) {
                $total += $balance;
            }
        }

        return Money::fromPaise($total);
    }

    /** @return list<array{key:string,label:string,amount:string}> */
    public static function expenseBreakdown(Builder $expenses): array
    {
        $data = self::sumBy($expenses, 'category');
        $out = [];
        foreach (Expense::CATEGORIES as $key => $label) {
            if (isset($data[$key]) && ! Money::isZero($data[$key])) {
                $out[] = ['key' => $key, 'label' => $label, 'amount' => $data[$key]];
            }
        }

        return $out;
    }

    /** Income billed, payments received and expenses per month (for charts). */
    public static function monthlyTrend(?string $start = null, ?string $end = null, int $months = 12): array
    {
        $endDate = $end ? CarbonImmutable::parse($end) : CarbonImmutable::today();
        $startDate = $start ? CarbonImmutable::parse($start) : null;
        if (! $startDate || ($endDate->year - $startDate->year) * 12 + $endDate->month - $startDate->month >= 24) {
            $startDate = $endDate->startOfMonth()->subMonthsNoOverflow($months - 1);
        }
        $startDate = $startDate->startOfMonth();

        $series = function (Builder $q) use ($startDate, $endDate) {
            $rows = $q->approved()->between($startDate->toDateString(), $endDate->toDateString())
                ->selectRaw('YEAR(date) as y, MONTH(date) as m, SUM(amount) as t')
                ->groupByRaw('YEAR(date), MONTH(date)')->get();
            $out = [];
            foreach ($rows as $r) {
                $out[sprintf('%04d-%02d', $r->y, $r->m)] = Money::toFloat($r->t);
            }

            return $out;
        };
        $inc = $series(Income::query());
        $exp = $series(Expense::query());
        $rec = $series(Payment::query());

        $result = ['labels' => [], 'income' => [], 'expense' => [], 'received' => []];
        for ($cursor = $startDate; $cursor->lessThanOrEqualTo($endDate); $cursor = $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $result['labels'][] = $cursor->format('M Y');
            $result['income'][] = $inc[$key] ?? 0.0;
            $result['expense'][] = $exp[$key] ?? 0.0;
            $result['received'][] = $rec[$key] ?? 0.0;
        }

        return $result;
    }

    /** Correlated SUM(amount) subquery over approved rows of `table` in the period. */
    private static function subSum(string $table, string $link, ?string $start, ?string $end, ?callable $extra = null)
    {
        $q = DB::table($table)->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn($link, 'vehicles.id')
            ->where("{$table}.status", 'APPROVED');
        if ($start) {
            $q->where("{$table}.date", '>=', $start);
        }
        if ($end) {
            $q->where("{$table}.date", '<=', $end);
        }
        if ($extra) {
            $extra($q);
        }

        return $q;
    }

    /** @return list<array<string,mixed>> */
    public static function vehicleProfitability(?string $start = null, ?string $end = null, ?string $category = null, bool $includeIdle = true): array
    {
        $received = DB::table('payments')->join('incomes', 'incomes.id', '=', 'payments.income_id')
            ->selectRaw('COALESCE(SUM(payments.amount), 0)')->whereColumn('incomes.vehicle_id', 'vehicles.id')
            ->where('payments.status', 'APPROVED');
        if ($start) {
            $received->where('payments.date', '>=', $start);
        }
        if ($end) {
            $received->where('payments.date', '<=', $end);
        }
        $jobs = DB::table('incomes')->selectRaw('COUNT(*)')->whereColumn('incomes.vehicle_id', 'vehicles.id')
            ->where('incomes.status', 'APPROVED');
        if ($start) {
            $jobs->where('incomes.date', '>=', $start);
        }
        if ($end) {
            $jobs->where('incomes.date', '<=', $end);
        }

        $q = Vehicle::query()->select('vehicles.*')
            ->selectSub(self::subSum('incomes', 'incomes.vehicle_id', $start, $end), 'income_sum')
            ->selectSub(self::subSum('expenses', 'expenses.vehicle_id', $start, $end), 'expense_sum')
            ->selectSub($received, 'received_sum')
            ->selectSub(self::subSum('expenses', 'expenses.vehicle_id', $start, $end, fn ($s) => $s->where('category', 'DRIVER')), 'driver_sum')
            ->selectSub(self::subSum('expenses', 'expenses.vehicle_id', $start, $end, fn ($s) => $s->where('category', 'DIESEL')), 'diesel_sum')
            ->selectSub($jobs, 'jobs_count')
            ->orderBy('category')->orderBy('registration_number');
        if ($category) {
            $q->where('category', $category);
        }

        $rows = [];
        foreach ($q->get() as $v) {
            $income = Money::of($v->income_sum);
            $expense = Money::of($v->expense_sum);
            if (! $includeIdle && Money::isZero($income) && Money::isZero($expense)) {
                continue;
            }
            $profit = Money::sub($income, $expense);
            $rows[] = [
                'vehicle' => $v, 'income' => $income, 'expense' => $expense, 'profit' => $profit,
                'received' => Money::of($v->received_sum), 'driver_expense' => Money::of($v->driver_sum),
                'diesel_expense' => Money::of($v->diesel_sum), 'jobs' => (int) $v->jobs_count,
                'margin' => Money::percent($profit, $income),
            ];
        }

        return $rows;
    }

    /** @return list<array{driver:Driver,total:string,by_type:array<string,string>}> */
    public static function driverExpenseSummary(?string $start = null, ?string $end = null): array
    {
        $base = Expense::query()->approved()->between($start, $end)->where('category', 'DRIVER');
        $totals = (clone $base)->whereNotNull('driver_id')
            ->selectRaw('driver_id, SUM(amount) as t')->groupBy('driver_id')->get()
            ->filter(fn ($r) => Money::isPositive($r->t))
            ->sortByDesc(fn ($r) => Money::toPaise($r->t));
        $drivers = Driver::with('assignedVehicle')->whereIn('id', $totals->pluck('driver_id'))->get()->keyBy('id');
        $rows = [];
        foreach ($totals as $r) {
            $driver = $drivers[$r->driver_id] ?? null;
            if (! $driver) {
                continue;
            }
            $rows[] = [
                'driver' => $driver,
                'total' => Money::of($r->t),
                'by_type' => self::sumBy((clone $base)->where('driver_id', $r->driver_id), 'driver_expense_type'),
            ];
        }

        return $rows;
    }

    /**
     * Money actually received (approved payments) and actually paid out
     * (approved expenses), with a running balance.
     */
    public static function cashBook(?string $start = null, ?string $end = null, ?string $mode = null): array
    {
        $settings = BusinessSetting::current();
        $payments = Payment::query()->approved()->with(['customer', 'income']);
        $expenses = Expense::query()->approved()->with(['vehicle', 'driver']);
        if ($mode) {
            $payments->where('mode', $mode);
            $expenses->where('payment_mode', $mode);
        }

        $opening = $mode ? Money::ZERO : Money::of($settings->opening_cash_balance);
        if ($start) {
            $opening = Money::add($opening, Payment::totalOf((clone $payments)->where('date', '<', $start)));
            $opening = Money::sub($opening, Expense::totalOf((clone $expenses)->where('date', '<', $start)));
        }

        $entries = [];
        foreach ((clone $payments)->between($start, $end)->get() as $p) {
            $against = $p->income_id ? ' against '.$p->income->number : ' (advance / on account)';
            $entries[] = [
                'date' => $p->date, 'number' => (string) $p->number, 'particulars' => 'Received from '.$p->customer->name.$against,
                'mode' => $p->modeLabel(), 'receipt' => $p->amount, 'payment' => Money::ZERO, 'url' => route('payments.show', $p),
            ];
        }
        foreach ((clone $expenses)->between($start, $end)->get() as $e) {
            $parts = array_filter([$e->categoryLabel(), $e->driver?->name, $e->vehicle?->displayName(), $e->vendor]);
            $entries[] = [
                'date' => $e->date, 'number' => (string) $e->number, 'particulars' => implode(' · ', $parts),
                'mode' => $e->modeLabel(), 'receipt' => Money::ZERO, 'payment' => $e->amount, 'url' => route('expenses.show', $e),
            ];
        }
        usort($entries, fn ($a, $b) => [$a['date']->toDateString(), $a['number']] <=> [$b['date']->toDateString(), $b['number']]);

        $running = Money::toPaise($opening);
        $receipts = 0;
        $paid = 0;
        foreach ($entries as &$entry) {
            $running += Money::toPaise($entry['receipt']) - Money::toPaise($entry['payment']);
            $entry['balance'] = Money::fromPaise($running);
            $receipts += Money::toPaise($entry['receipt']);
            $paid += Money::toPaise($entry['payment']);
        }
        unset($entry);

        return [
            'opening' => $opening,
            'entries' => $entries,
            'total_receipts' => Money::fromPaise($receipts),
            'total_payments' => Money::fromPaise($paid),
            'closing' => Money::fromPaise(Money::toPaise($opening) + $receipts - $paid),
        ];
    }

    /** @return list<array{customer:Customer,opening:string,billed:string,received:string,balance:string}> */
    public static function customerOutstandingRows(string $search = '', bool $onlyDue = false): array
    {
        $q = Customer::query()->withBalances()->orderBy('name');
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }
        $rows = [];
        foreach ($q->get() as $c) {
            $balance = $c->balance();
            if ($onlyDue && ! Money::isPositive($balance)) {
                continue;
            }
            $rows[] = [
                'customer' => $c, 'opening' => Money::of($c->opening_balance), 'billed' => Money::of($c->billed),
                'received' => Money::of($c->received), 'balance' => $balance,
            ];
        }

        return $rows;
    }

    /** Latest income, expenses and payments the user may see, newest first. */
    public static function recentTransactions(User $user, int $limit = 10): array
    {
        $items = [];
        $latest = fn (Builder $q) => $q->visibleTo($user)->orderByDesc('date')->orderByDesc('id')->limit($limit)->get();
        foreach ($latest(Income::with(['customer', 'vehicle'])) as $inc) {
            $items[] = ['kind' => 'Income', 'obj' => $inc, 'date' => $inc->date, 'amount' => $inc->amount, 'sign' => 1,
                'title' => $inc->customer->name, 'subtitle' => $inc->vehicle->displayName(), 'url' => route('income.show', $inc),
                'created' => $inc->created_at];
        }
        foreach ($latest(Expense::with(['vehicle', 'driver'])) as $exp) {
            $sub = $exp->driver?->name ?? ($exp->vehicle ? $exp->vehicle->displayName() : 'General');
            $items[] = ['kind' => 'Expense', 'obj' => $exp, 'date' => $exp->date, 'amount' => $exp->amount, 'sign' => -1,
                'title' => $exp->categoryLabel(), 'subtitle' => $sub, 'url' => route('expenses.show', $exp), 'created' => $exp->created_at];
        }
        foreach ($latest(Payment::with('customer')) as $pay) {
            $items[] = ['kind' => 'Payment', 'obj' => $pay, 'date' => $pay->date, 'amount' => $pay->amount, 'sign' => 1,
                'title' => $pay->customer->name, 'subtitle' => $pay->modeLabel(), 'url' => route('payments.show', $pay),
                'created' => $pay->created_at];
        }
        usort($items, fn ($a, $b) => [$b['date']->toDateString(), (string) $b['created']] <=> [$a['date']->toDateString(), (string) $a['created']]);

        return array_slice($items, 0, $limit);
    }

    /** Ledger of approved invoices (debit) and payments (credit) with running balance. */
    public static function customerStatement(Customer $customer): array
    {
        $lines = [];
        foreach ($customer->incomes()->approved()->with('vehicle')->get() as $inc) {
            $lines[] = ['date' => $inc->date, 'number' => (string) $inc->number, 'obj' => $inc, 'kind' => 'Invoice',
                'particulars' => $inc->vehicle->displayName().' · '.$inc->billingLabel().($inc->work_location ? ' · '.$inc->work_location : ''),
                'debit' => $inc->amount, 'credit' => Money::ZERO, 'url' => route('income.show', $inc)];
        }
        foreach ($customer->payments()->approved()->with('income')->get() as $pay) {
            $lines[] = ['date' => $pay->date, 'number' => (string) $pay->number, 'obj' => $pay, 'kind' => 'Payment',
                'particulars' => $pay->modeLabel().($pay->income_id ? ' against '.$pay->income->number : ' · advance / on account')
                    .($pay->reference ? ' · Ref '.$pay->reference : ''),
                'debit' => Money::ZERO, 'credit' => $pay->amount, 'url' => route('payments.show', $pay)];
        }
        usort($lines, fn ($a, $b) => [$a['date']->toDateString(), $a['kind'] === 'Invoice' ? 0 : 1, $a['number']]
            <=> [$b['date']->toDateString(), $b['kind'] === 'Invoice' ? 0 : 1, $b['number']]);

        $balance = Money::toPaise($customer->opening_balance);
        $debit = 0;
        $credit = 0;
        foreach ($lines as &$line) {
            $balance += Money::toPaise($line['debit']) - Money::toPaise($line['credit']);
            $line['balance'] = Money::fromPaise($balance);
            $debit += Money::toPaise($line['debit']);
            $credit += Money::toPaise($line['credit']);
        }
        unset($line);

        return [$lines, [
            'opening' => Money::of($customer->opening_balance), 'debit' => Money::fromPaise($debit),
            'credit' => Money::fromPaise($credit), 'closing' => Money::fromPaise($balance),
        ]];
    }
}
