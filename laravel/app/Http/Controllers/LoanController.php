<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\Payment;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Money lent to people and the repayments collected back (administrators only). */
class LoanController extends Controller
{
    private function query(Request $request): Builder
    {
        $q = Loan::query()->withRepaid();
        match ($request->query('status')) {
            'open' => $q->unsettled(),
            'overdue' => $q->overdue(),
            'settled' => $q->whereRaw('loans.amount <= (SELECT COALESCE(SUM(amount), 0) FROM loan_repayments WHERE loan_repayments.loan_id = loans.id)'),
            default => null,
        };

        // Overdue and soonest-due first; loans without a due date after those.
        return $this->search($q, $request, ['borrower_name', 'phone', 'notes'])
            ->orderByRaw('loans.due_date IS NULL')->orderBy('loans.due_date')->orderByDesc('loans.date')->orderByDesc('loans.id');
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $export = $this->export($request, function () use ($request, $status) {
            $loans = $this->query($request)->get();

            return new ReportTable(
                title: 'Money Lent',
                columns: [['No.', 'number', 'text'], ['Date', 'date', 'date'], ['Borrower', 'name', 'text'], ['Phone', 'phone', 'text'],
                    ['Due date', 'due', 'date'], ['Lent', 'amount', 'money'], ['Repaid', 'repaid', 'money'], ['Balance', 'balance', 'money'],
                    ['Status', 'status', 'text']],
                rows: $loans->map(fn (Loan $l) => ['number' => $l->number(), 'date' => $l->date, 'name' => $l->borrower_name,
                    'phone' => $l->phone, 'due' => $l->due_date, 'amount' => $l->amount, 'repaid' => $l->repaidAmount(),
                    'balance' => $l->balance(), 'status' => $l->statusLabel()])->all(),
                totals: [
                    'amount' => Money::sum($loans->pluck('amount')->all()),
                    'repaid' => Money::sum($loans->map->repaidAmount()->all()),
                    'balance' => Money::sum($loans->map->balance()->all()),
                ],
                filters: array_filter([['Status', ['open' => 'To collect', 'overdue' => 'Overdue', 'settled' => 'Settled'][$status] ?? 'All'],
                    $request->filled('q') ? ['Search', (string) $request->query('q')] : null]),
            );
        });
        if ($export) {
            return $export;
        }

        $open = Loan::query()->withRepaid()->unsettled()->get();
        $overdue = $open->filter(fn (Loan $l) => $l->status() === 'OVERDUE');

        return view('loans.index', [
            'loans' => $this->query($request)->paginate($this->perPage())->withQueryString(),
            'kpi' => [
                'to_collect' => Money::sum($open->map->balance()->all()),
                'open_count' => $open->count(),
                'overdue' => Money::sum($overdue->map->balance()->all()),
                'overdue_count' => $overdue->count(),
                'lent_total' => Money::of(Loan::sum('amount')),
                'repaid_total' => Money::of(LoanRepayment::sum('amount')),
            ],
        ]);
    }

    public function show(Loan $loan)
    {
        return view('loans.show', [
            'loan' => $loan,
            'repayments' => $loan->repayments()->with('creator')->orderBy('date')->orderBy('id')->get(),
            'history' => Audit::history($loan),
        ]);
    }

    public function create()
    {
        return view('loans.form', ['loan' => new Loan(['date' => now()->toDateString()])]);
    }

    public function store(Request $request)
    {
        $loan = new Loan;
        $loan->created_by = $request->user()->id;
        $this->save($request, $loan);
        Audit::created($loan);

        return redirect()->route('loans.show', $loan)->with('success', "{$loan->number()} saved: money lent to {$loan->borrower_name}.");
    }

    public function edit(Loan $loan)
    {
        return view('loans.form', ['loan' => $loan]);
    }

    public function update(Request $request, Loan $loan)
    {
        $before = Audit::snapshot($loan);
        $this->save($request, $loan);
        Audit::updated($loan, $before);

        return redirect()->route('loans.show', $loan)->with('success', "{$loan->number()} updated.");
    }

    private function save(Request $request, Loan $loan): void
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'borrower_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value && strlen(preg_replace('/\D/', '', (string) $value)) < 10) {
                    $fail('Enter a valid phone number (at least 10 digits).');
                }
            }],
            'amount' => ['required', ...self::MONEY],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'mode' => ['required', Rule::in(array_keys(Payment::MODES))],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['due_date.after_or_equal' => 'The due date cannot be before the date the money was lent.']);
        $data['borrower_name'] = preg_replace('/\s+/', ' ', trim($data['borrower_name']));

        $errors = [];
        if (! Money::isPositive($data['amount'])) {
            $errors['amount'] = 'Enter an amount greater than zero.';
        } elseif ($loan->exists) {
            $repaid = Money::of($loan->repayments()->sum('amount'));
            if (Money::cmp($data['amount'], $repaid) < 0) {
                $errors['amount'] = "The amount cannot be less than what has already been repaid ({$repaid}).";
            }
        }
        if ($loan->exists && $loan->repayments()->where('date', '<', $data['date'])->exists()) {
            $errors['date'] = 'A repayment is dated before this date. Correct the repayment first.';
        }
        $this->failIf($errors);

        $loan->fill($data)->save();
    }

    public function confirmDelete(Loan $loan)
    {
        return view('partials.confirm-delete', ['label' => (string) $loan, 'cancel' => route('loans.show', $loan)]);
    }

    public function destroy(Loan $loan)
    {
        return $this->auditedDelete($loan, "{$loan->number()} deleted.", 'loans.index',
            'This loan has repayments recorded. Delete the repayments first.', route('loans.show', $loan));
    }

    public function storeRepayment(Request $request, Loan $loan)
    {
        $data = $request->validateWithBag('repayment', [
            'date' => ['required', 'date', 'after_or_equal:'.$loan->date->toDateString()],
            'amount' => ['required', ...self::MONEY],
            'mode' => ['required', Rule::in(array_keys(Payment::MODES))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['date.after_or_equal' => 'A repayment cannot be dated before the money was lent ('.$loan->date->format('d M Y').').']);

        $repayment = DB::transaction(function () use ($loan, $data, $request) {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $balance = $locked->balance();
            if (! Money::isPositive($data['amount'])) {
                $error = 'Enter an amount greater than zero.';
            } elseif (Money::cmp($data['amount'], $balance) > 0) {
                $error = "Amount exceeds the balance still to collect ({$balance}).";
            }
            if (isset($error)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => $error])->errorBag('repayment');
            }
            $r = new LoanRepayment($data);
            $r->loan_id = $locked->id;
            $r->created_by = $request->user()->id;
            $r->save();
            Audit::created($r);

            return $r;
        });

        $left = $loan->fresh()->balance();
        $msg = Money::isPositive($left)
            ? 'Repayment of '.inr($repayment->amount).' recorded. Still to collect: '.inr($left).'.'
            : 'Repayment of '.inr($repayment->amount).' recorded. This loan is now fully settled.';

        return redirect()->route('loans.show', $loan)->with('success', $msg);
    }

    public function confirmDeleteRepayment(Loan $loan, LoanRepayment $repayment)
    {
        abort_unless((int) $repayment->loan_id === (int) $loan->id, 404);

        return view('partials.confirm-delete', ['label' => (string) $repayment.' on '.$repayment->date->format('d M Y'),
            'cancel' => route('loans.show', $loan)]);
    }

    public function destroyRepayment(Loan $loan, LoanRepayment $repayment)
    {
        abort_unless((int) $repayment->loan_id === (int) $loan->id, 404);
        DB::transaction(function () use ($repayment) {
            Audit::deleted($repayment);
            $repayment->delete();
        });

        return redirect()->route('loans.show', $loan)->with('success', 'Repayment deleted.');
    }
}
