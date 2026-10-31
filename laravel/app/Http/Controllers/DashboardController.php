<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\Finance;
use App\Support\Money;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $period = $this->period($request);
        $data = ['period' => $period, 'recent' => Finance::recentTransactions($user, 8)];

        if ($user->hasCapability('view_reports')) {
            $summary = Finance::financialSummary($period->startDate(), $period->endDate());
            $profitRows = Finance::vehicleProfitability($period->startDate(), $period->endDate());
            usort($profitRows, fn ($a, $b) => Money::cmp($b['profit'], $a['profit']));

            return view('dashboard.admin', $data + [
                'summary' => $summary,
                'profitRows' => array_slice($profitRows, 0, 8),
                'trend' => Finance::monthlyTrend($period->startDate(), $period->endDate()),
                'expenseChart' => [
                    'labels' => array_column($summary['expense_by_category'], 'label'),
                    'values' => array_map(fn ($r) => Money::toFloat($r['amount']), $summary['expense_by_category']),
                ],
                'categoryChart' => [
                    'labels' => array_column($summary['categories'], 'label'),
                    'income' => array_map(fn ($c) => Money::toFloat($c['income']), array_values($summary['categories'])),
                    'expense' => array_map(fn ($c) => Money::toFloat($c['expense']), array_values($summary['categories'])),
                ],
                'driverRows' => array_slice(Finance::driverExpenseSummary($period->startDate(), $period->endDate()), 0, 5),
                'loanReminders' => $user->isAdmin() ? $this->loanReminders() : null,
                'pending' => [
                    'income' => Income::pending()->count(),
                    'expense' => Expense::pending()->count(),
                    'payment' => Payment::pending()->count(),
                ],
            ]);
        }

        $mine = fn ($model) => $model::query()->where('created_by', $user->id);
        $counts = [];
        foreach (['PENDING', 'APPROVED', 'REJECTED'] as $status) {
            $counts[$status] = $mine(Income::class)->where('status', $status)->count()
                + $mine(Expense::class)->where('status', $status)->count()
                + $mine(Payment::class)->where('status', $status)->count();
        }
        $inPeriod = fn ($model) => $mine($model)->notRejected()->between($period->startDate(), null);
        $rejected = collect()
            ->concat($mine(Income::class)->where('status', 'REJECTED')->latest('date')->limit(5)->get())
            ->concat($mine(Expense::class)->where('status', 'REJECTED')->latest('date')->limit(5)->get())
            ->concat($mine(Payment::class)->where('status', 'REJECTED')->latest('date')->limit(5)->get());

        return view('dashboard.entry', $data + [
            'myCounts' => $counts,
            'myTotals' => [
                'income' => Income::totalOf($inPeriod(Income::class)),
                'expense' => Expense::totalOf($inPeriod(Expense::class)),
                'payment' => Payment::totalOf($inPeriod(Payment::class)),
            ],
            'rejected' => $rejected,
        ]);
    }

    /** Money lent that is overdue or due within a week (null until the loans tables exist). */
    private function loanReminders(): ?array
    {
        try {
            $open = Loan::query()->withRepaid()->unsettled()->get();
        } catch (\Illuminate\Database\QueryException) {
            return null;
        }
        $soon = now()->addDays(7)->startOfDay();
        $due = $open->filter(fn (Loan $l) => $l->due_date && $l->due_date->lte($soon))->sortBy('due_date')->values();

        return [
            'to_collect' => Money::sum($open->map->balance()->all()),
            'open_count' => $open->count(),
            'due' => $due->take(6),
            'due_count' => $due->count(),
        ];
    }
}
