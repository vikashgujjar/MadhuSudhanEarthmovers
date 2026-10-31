<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Reports\ReportTable;
use App\Services\Finance;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private function periodFilter(Period $period): array
    {
        return [['Period', $period->label], ['Basis', 'Approved transactions only']];
    }

    public function index()
    {
        return view('reports.index');
    }

    public function cashbook(Request $request)
    {
        $period = $this->period($request, 'this_month');
        $mode = isset(Payment::MODES[(string) $request->query('mode')]) ? $request->query('mode') : null;
        $book = Finance::cashBook($period->startDate(), $period->endDate(), $mode);

        $export = $this->export($request, function () use ($book, $period, $mode) {
            $rows = [['date' => null, 'number' => '', 'particulars' => 'Opening balance', 'mode' => '', 'receipt' => null,
                'payment' => null, 'balance' => $book['opening']]];
            foreach ($book['entries'] as $e) {
                $rows[] = ['date' => $e['date'], 'number' => $e['number'], 'particulars' => $e['particulars'], 'mode' => $e['mode'],
                    'receipt' => Money::isZero($e['receipt']) ? null : $e['receipt'],
                    'payment' => Money::isZero($e['payment']) ? null : $e['payment'], 'balance' => $e['balance']];
            }

            return new ReportTable(
                title: 'Cash Book',
                columns: [['Date', 'date', 'date'], ['Voucher', 'number', 'text'], ['Particulars', 'particulars', 'text'],
                    ['Mode', 'mode', 'text'], ['Receipts', 'receipt', 'money'], ['Payments', 'payment', 'money'],
                    ['Balance', 'balance', 'money']],
                rows: $rows,
                totals: ['receipt' => $book['total_receipts'], 'payment' => $book['total_payments'], 'balance' => $book['closing']],
                filters: [...$this->periodFilter($period), ['Mode', $mode ? Payment::MODES[$mode] : 'All modes']],
                totalsLabel: 'Closing balance',
            );
        });

        return $export ?? view('reports.cashbook', ['period' => $period, 'book' => $book, 'mode' => $mode]);
    }

    public function profitability(Request $request)
    {
        $period = $this->period($request);
        $category = isset(Vehicle::CATEGORIES[(string) $request->query('category')]) ? $request->query('category') : null;
        $rows = Finance::vehicleProfitability($period->startDate(), $period->endDate(), $category);
        $totals = [];
        foreach (['income', 'expense', 'profit', 'received', 'driver_expense', 'diesel_expense'] as $k) {
            $totals[$k] = Money::sum(array_column($rows, $k));
        }
        $totals['margin'] = Money::percent($totals['profit'], $totals['income']);

        $export = $this->export($request, fn () => new ReportTable(
            title: 'Vehicle-wise Profitability',
            columns: [['Vehicle', 'vehicle', 'text'], ['Registration', 'reg', 'text'], ['Category', 'category', 'text'],
                ['Jobs', 'jobs', 'number'], ['Income', 'income', 'money'], ['Diesel', 'diesel', 'money'],
                ['Driver', 'driver', 'money'], ['Total expense', 'expense', 'money'], ['Profit', 'profit', 'money'],
                ['Margin', 'margin', 'percent'], ['Collected', 'received', 'money']],
            rows: array_map(fn ($r) => [
                'vehicle' => $r['vehicle']->displayName(), 'reg' => $r['vehicle']->registration_number,
                'category' => $r['vehicle']->categoryLabel(), 'jobs' => $r['jobs'], 'income' => $r['income'],
                'diesel' => $r['diesel_expense'], 'driver' => $r['driver_expense'], 'expense' => $r['expense'],
                'profit' => $r['profit'], 'margin' => $r['margin'], 'received' => $r['received'],
            ], $rows),
            totals: ['income' => $totals['income'], 'diesel' => $totals['diesel_expense'], 'driver' => $totals['driver_expense'],
                'expense' => $totals['expense'], 'profit' => $totals['profit'], 'received' => $totals['received']],
            filters: [...$this->periodFilter($period), ['Category', $category ? Vehicle::CATEGORIES[$category] : 'JCB & Tractor']],
        ));
        if ($export) {
            return $export;
        }
        $active = array_values(array_filter($rows, fn ($r) => ! Money::isZero($r['income']) || ! Money::isZero($r['expense'])));

        return view('reports.profitability', [
            'period' => $period, 'rows' => $rows, 'totals' => $totals, 'category' => $category,
            'summary' => Finance::financialSummary($period->startDate(), $period->endDate()),
            'chart' => [
                'labels' => array_map(fn ($r) => $r['vehicle']->displayName(), $active),
                'income' => array_map(fn ($r) => Money::toFloat($r['income']), $active),
                'expense' => array_map(fn ($r) => Money::toFloat($r['expense']), $active),
                'profit' => array_map(fn ($r) => Money::toFloat($r['profit']), $active),
            ],
        ]);
    }

    public function pnl(Request $request)
    {
        $period = $this->period($request);
        $incomes = Income::approved()->between($period->startDate(), $period->endDate());
        $expenses = Expense::approved()->between($period->startDate(), $period->endDate());
        $incomeLines = (clone $incomes)->selectRaw('vehicle_category, billing_type, SUM(amount) as t')
            ->groupBy('vehicle_category', 'billing_type')->orderBy('vehicle_category')->orderBy('billing_type')->get()
            ->map(fn ($r) => ['label' => (Vehicle::CATEGORIES[$r->vehicle_category] ?? $r->vehicle_category).' — '
                .(Income::BILLING_TYPES[$r->billing_type] ?? $r->billing_type), 'amount' => Money::of($r->t)])->all();
        $expenseLines = (clone $expenses)->selectRaw('category, SUM(amount) as t')->groupBy('category')->get()
            ->sortByDesc(fn ($r) => Money::toPaise($r->t))
            ->map(fn ($r) => ['label' => Expense::CATEGORIES[$r->category] ?? $r->category, 'amount' => Money::of($r->t)])->values()->all();
        $totalIncome = Income::totalOf($incomes);
        $totalExpense = Expense::totalOf($expenses);
        $net = Money::sub($totalIncome, $totalExpense);
        $summary = Finance::financialSummary($period->startDate(), $period->endDate());

        $export = $this->export($request, function () use ($incomeLines, $expenseLines, $totalIncome, $totalExpense, $net, $summary, $period) {
            $rows = [['section' => 'INCOME', 'label' => '', 'amount' => null]];
            foreach ($incomeLines as $l) {
                $rows[] = ['section' => '', 'label' => $l['label'], 'amount' => $l['amount']];
            }
            $rows[] = ['section' => '', 'label' => 'Total income billed', 'amount' => $totalIncome];
            $rows[] = ['section' => 'EXPENSES', 'label' => '', 'amount' => null];
            foreach ($expenseLines as $l) {
                $rows[] = ['section' => '', 'label' => $l['label'], 'amount' => $l['amount']];
            }
            $rows[] = ['section' => '', 'label' => 'Total expenses', 'amount' => $totalExpense];

            return new ReportTable(
                title: 'Profit & Loss Summary',
                columns: [['Section', 'section', 'text'], ['Particulars', 'label', 'text'], ['Amount', 'amount', 'money']],
                rows: $rows, totals: ['amount' => $net], filters: $this->periodFilter($period),
                summary: [['Payments received (cash basis)', $summary['received_total']], ['Customer outstanding', $summary['outstanding_total']]],
                totalsLabel: 'Net operating profit',
            );
        });

        return $export ?? view('reports.pnl', compact('period', 'incomeLines', 'expenseLines', 'totalIncome', 'totalExpense', 'net', 'summary'));
    }

    public function driverExpenses(Request $request)
    {
        $period = $this->period($request);
        $rows = Finance::driverExpenseSummary($period->startDate(), $period->endDate());
        $totals = [];
        foreach (array_keys(Expense::DRIVER_TYPES) as $k) {
            $totals[$k] = Money::sum(array_map(fn ($r) => $r['by_type'][$k] ?? Money::ZERO, $rows));
        }
        $totals['total'] = Money::sum(array_column($rows, 'total'));

        $export = $this->export($request, function () use ($rows, $totals, $period) {
            $cols = [['Driver', 'driver', 'text'], ['Assigned vehicle', 'vehicle', 'text']];
            foreach (Expense::DRIVER_TYPES as $key => $label) {
                $cols[] = [$label, $key, 'money'];
            }
            $cols[] = ['Total', 'total', 'money'];
            $data = array_map(function ($r) {
                $row = ['driver' => $r['driver']->name, 'vehicle' => $r['driver']->assignedVehicle?->displayName() ?? '', 'total' => $r['total']];
                foreach (array_keys(Expense::DRIVER_TYPES) as $k) {
                    $row[$k] = $r['by_type'][$k] ?? null;
                }

                return $row;
            }, $rows);

            return new ReportTable(title: 'Driver Expense Summary', columns: $cols, rows: $data, totals: $totals,
                filters: $this->periodFilter($period));
        });
        if ($export) {
            return $export;
        }
        $overall = Finance::financialSummary($period->startDate(), $period->endDate());

        return view('reports.driver-expenses', [
            'period' => $period, 'rows' => $rows, 'totals' => $totals, 'overall' => $overall,
            'share' => Money::percent($totals['total'], $overall['expense_total']),
        ]);
    }

    public function expenseCategories(Request $request)
    {
        $period = $this->period($request);
        $grid = [];
        $q = Expense::approved()->between($period->startDate(), $period->endDate())
            ->leftJoin('vehicles', 'vehicles.id', '=', 'expenses.vehicle_id')
            ->selectRaw('expenses.category as cat, vehicles.category as vcat, SUM(expenses.amount) as t')
            ->groupBy('expenses.category', 'vehicles.category');
        foreach ($q->get() as $r) {
            $grid[$r->cat][$r->vcat ?? 'GENERAL'] = Money::of($r->t);
        }
        $rows = [];
        foreach (Expense::CATEGORIES as $key => $label) {
            $cells = $grid[$key] ?? [];
            $row = ['category' => $label, 'JCB' => $cells['JCB'] ?? Money::ZERO, 'TRACTOR' => $cells['TRACTOR'] ?? Money::ZERO,
                'GENERAL' => $cells['GENERAL'] ?? Money::ZERO];
            $row['total'] = Money::add($row['JCB'], $row['TRACTOR'], $row['GENERAL']);
            $rows[] = $row;
        }
        $totals = [];
        foreach (['JCB', 'TRACTOR', 'GENERAL', 'total'] as $k) {
            $totals[$k] = Money::sum(array_column($rows, $k));
        }

        $export = $this->export($request, fn () => new ReportTable(
            title: 'Expenses by Category',
            columns: [['Category', 'category', 'text'], ['JCB', 'JCB', 'money'], ['Tractor', 'TRACTOR', 'money'],
                ['General (no vehicle)', 'GENERAL', 'money'], ['Total', 'total', 'money']],
            rows: $rows, totals: $totals, filters: $this->periodFilter($period),
        ));

        return $export ?? view('reports.expense-categories', compact('period', 'rows', 'totals'));
    }
}
