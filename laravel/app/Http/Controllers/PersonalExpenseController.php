<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PersonalExpense;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The owner's personal expenses, kept apart from every business figure. */
class PersonalExpenseController extends Controller
{
    private function query(Request $request, Period $period): Builder
    {
        $q = PersonalExpense::query();
        if ($period->startDate()) {
            $q->where('date', '>=', $period->startDate());
        }
        if ($period->endDate()) {
            $q->where('date', '<=', $period->endDate());
        }
        if (isset(PersonalExpense::CATEGORIES[(string) $request->query('category')])) {
            $q->where('category', $request->query('category'));
        }
        if (isset(Payment::MODES[(string) $request->query('mode')])) {
            $q->where('mode', $request->query('mode'));
        }

        return $this->search($q, $request, ['paid_to', 'description', 'notes'])->orderByDesc('date')->orderByDesc('id');
    }

    public function index(Request $request)
    {
        $period = $this->period($request, 'this_month');
        $export = $this->export($request, function () use ($request, $period) {
            $rows = $this->query($request, $period)->get();
            $filters = [['Period', $period->label]];
            if ($c = PersonalExpense::CATEGORIES[(string) $request->query('category')] ?? null) {
                $filters[] = ['Category', $c];
            }
            if ($m = Payment::MODES[(string) $request->query('mode')] ?? null) {
                $filters[] = ['Paid by', $m];
            }
            if ($request->filled('q')) {
                $filters[] = ['Search', (string) $request->query('q')];
            }

            return new ReportTable(
                title: 'Personal Expenses',
                columns: [['No.', 'number', 'text'], ['Date', 'date', 'date'], ['Category', 'category', 'text'],
                    ['Paid to', 'paid_to', 'text'], ['Description', 'description', 'text'], ['Paid by', 'mode', 'text'], ['Amount', 'amount', 'money']],
                rows: $rows->map(fn (PersonalExpense $e) => ['number' => $e->number(), 'date' => $e->date, 'category' => $e->categoryLabel(),
                    'paid_to' => $e->paid_to, 'description' => $e->description, 'mode' => $e->modeLabel(), 'amount' => $e->amount])->all(),
                totals: ['amount' => Money::sum($rows->pluck('amount')->all())],
                filters: $filters,
            );
        });
        if ($export) {
            return $export;
        }

        $all = $this->query($request, $period);
        $byCategory = (clone $all)->reorder()->selectRaw('category, SUM(amount) as t')->groupBy('category')->orderByDesc('t')->get()
            ->map(fn ($r) => ['key' => $r->category, 'label' => PersonalExpense::CATEGORIES[$r->category] ?? $r->category, 't' => $r->t]);
        $fyStart = Period::fyStart(now()->toImmutable(), $this->business()->financial_year_start_month)->toDateString();

        return view('personal-expenses.index', [
            'expenses' => (clone $all)->paginate($this->perPage())->withQueryString(),
            'period' => $period,
            'total' => Money::of((clone $all)->reorder()->sum('amount')),
            'count' => (clone $all)->reorder()->count(),
            'byCategory' => $byCategory,
            'thisMonth' => Money::of(PersonalExpense::where('date', '>=', now()->startOfMonth()->toDateString())->sum('amount')),
            'thisFy' => Money::of(PersonalExpense::where('date', '>=', $fyStart)->sum('amount')),
        ]);
    }

    public function show(PersonalExpense $personalExpense)
    {
        return view('personal-expenses.show', ['expense' => $personalExpense, 'history' => Audit::history($personalExpense)]);
    }

    public function create()
    {
        return view('personal-expenses.form', ['expense' => new PersonalExpense(['date' => now()->toDateString()])]);
    }

    public function store(Request $request)
    {
        $expense = new PersonalExpense;
        $expense->created_by = $request->user()->id;
        $this->save($request, $expense);
        Audit::created($expense);

        if ($request->input('action') === 'save_add') {
            return redirect()->route('personal-expenses.create')->with('success', "{$expense->number()} saved. Add the next one.");
        }

        return redirect()->route('personal-expenses.show', $expense)->with('success', "{$expense->number()} saved.");
    }

    public function edit(PersonalExpense $personalExpense)
    {
        return view('personal-expenses.form', ['expense' => $personalExpense]);
    }

    public function update(Request $request, PersonalExpense $personalExpense)
    {
        $before = Audit::snapshot($personalExpense);
        $this->save($request, $personalExpense);
        Audit::updated($personalExpense, $before);

        return redirect()->route('personal-expenses.show', $personalExpense)->with('success', "{$personalExpense->number()} updated.");
    }

    private function save(Request $request, PersonalExpense $expense): void
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', Rule::in(array_keys(PersonalExpense::CATEGORIES))],
            'amount' => ['required', ...self::MONEY],
            'mode' => ['required', Rule::in(array_keys(Payment::MODES))],
            'paid_to' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['date.before_or_equal' => 'The date cannot be in the future.']);
        $this->failIf(Money::isPositive($data['amount']) ? [] : ['amount' => 'Enter an amount greater than zero.']);

        $expense->fill($data)->save();
    }

    public function confirmDelete(PersonalExpense $personalExpense)
    {
        return view('partials.confirm-delete', ['label' => (string) $personalExpense, 'cancel' => route('personal-expenses.show', $personalExpense)]);
    }

    public function destroy(PersonalExpense $personalExpense)
    {
        return $this->auditedDelete($personalExpense, "{$personalExpense->number()} deleted.", 'personal-expenses.index',
            'This entry could not be deleted.', route('personal-expenses.show', $personalExpense));
    }
}
