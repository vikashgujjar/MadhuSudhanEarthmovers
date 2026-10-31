<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Income;
use App\Models\Payment;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends TransactionController
{
    private const SEARCH = ['number', 'reference', 'notes', 'customer.name', 'income.number'];

    private function query(Request $request, $period): Builder
    {
        $q = Payment::query()->with(['customer', 'income']);
        $this->baseFilters($q, $request, $period, self::SEARCH);
        if ($customer = $this->idParam($request, 'customer')) {
            $q->where('payments.customer_id', $customer);
        }
        if (isset(Payment::MODES[(string) $request->query('mode')])) {
            $q->where('payments.mode', $request->query('mode'));
        }
        if ($request->query('type') === 'advance') {
            $q->whereNull('payments.income_id');
        } elseif ($request->query('type') === 'invoice') {
            $q->whereNotNull('payments.income_id');
        }

        return $q->orderByDesc('payments.date')->orderByDesc('payments.id');
    }

    public function index(Request $request)
    {
        $period = $this->listPeriod($request);
        $export = $this->export($request, function () use ($request, $period) {
            $rows = [];
            $total = 0;
            foreach ($this->query($request, $period)->get() as $p) {
                $rows[] = [
                    'date' => $p->date, 'number' => $p->number, 'customer' => $p->customer->name,
                    'invoice' => $p->income?->number ?? 'Advance / on account', 'mode' => $p->modeLabel(),
                    'reference' => $p->reference, 'amount' => $p->amount, 'status' => $p->statusLabel(),
                ];
                if ($p->isApproved()) {
                    $total += Money::toPaise($p->amount);
                }
            }

            return new ReportTable(
                title: 'Payments Received',
                columns: [['Date', 'date', 'date'], ['Receipt', 'number', 'text'], ['Customer', 'customer', 'text'],
                    ['Invoice', 'invoice', 'text'], ['Mode', 'mode', 'text'], ['Reference', 'reference', 'text'],
                    ['Amount', 'amount', 'money'], ['Status', 'status', 'text']],
                rows: $rows, totals: ['amount' => Money::fromPaise($total)],
                filters: $this->filterSummary($request, $period, Payment::STATUSES), totalsLabel: 'Total (approved)',
            );
        });
        if ($export) {
            return $export;
        }
        $all = $this->query($request, $period);
        $approved = (clone $all)->where('payments.status', 'APPROVED')->reorder();

        return view('finance.payment-index', [
            'payments' => (clone $all)->paginate($this->perPage())->withQueryString(),
            'period' => $period,
            'customers' => Customer::orderBy('name')->get(),
            'totalApproved' => Payment::totalOf($approved),
            'totalPending' => Payment::totalOf((clone $all)->where('payments.status', 'PENDING')),
            'byMode' => (clone $approved)->selectRaw('payments.mode as k, SUM(payments.amount) as t')->groupBy('payments.mode')->get()
                ->sortByDesc(fn ($r) => Money::toPaise($r->t))
                ->map(fn ($r) => ['label' => Payment::MODES[$r->k] ?? $r->k, 't' => $r->t])->values(),
        ]);
    }

    public function show(Request $request, int $payment)
    {
        $payment = $this->findVisible(Payment::class, $payment, $request);
        $payment->load(['customer', 'income', 'creator', 'approver']);

        return view('finance.payment-show', ['payment' => $payment, 'history' => Audit::history($payment)]);
    }

    private function invoicesFor(?int $customerId, ?Payment $payment = null)
    {
        if (! $customerId) {
            return collect();
        }

        return Income::where('customer_id', $customerId)->notRejected()
            ->orderByDesc('date')->orderByDesc('id')->get();
    }

    private function form(Payment $payment, array $prefill = [])
    {
        $customerId = old('customer', $prefill['customer'] ?? $payment->customer_id);
        $customerId = is_numeric($customerId) ? (int) $customerId : null;

        return view('finance.payment-form', [
            'payment' => $payment,
            'prefill' => $prefill,
            'customers' => $this->activeCustomers($payment->customer_id),
            'invoices' => $this->invoicesFor($customerId, $payment),
        ]);
    }

    public function create(Request $request)
    {
        return $this->form(new Payment(['date' => now()->toDateString()]), $this->prefill($request, ['customer', 'income']));
    }

    public function store(Request $request)
    {
        $payment = new Payment;
        $this->validated($request, $payment);

        return $this->locked($payment, fn () => $this->createTransaction($payment, $request));
    }

    public function edit(Request $request, int $payment)
    {
        return $this->form($this->findEditable(Payment::class, $payment, $request));
    }

    public function update(Request $request, int $payment)
    {
        $payment = $this->findEditable(Payment::class, $payment, $request);
        $before = Audit::snapshot($payment);
        $this->validated($request, $payment);

        return $this->locked($payment, fn () => $this->updateTransaction($payment, $before, $request));
    }

    /**
     * Lock the invoice row and re-check its balance inside the transaction, so
     * two simultaneous payments cannot together exceed the amount due.
     */
    private function locked(Payment $payment, callable $save)
    {
        return DB::transaction(function () use ($payment, $save) {
            if ($payment->income_id) {
                Income::whereKey($payment->income_id)->lockForUpdate()->first();
                $this->failIf($payment->businessErrors());
            }

            return $save();
        });
    }

    private function validated(Request $request, Payment $payment): void
    {
        $customer = $request->input('customer');
        $customerId = is_numeric($customer) ? (int) $customer : null;
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'customer' => ['required', 'integer', Rule::in($this->activeCustomers($payment->customer_id)->pluck('id')->all())],
            'income' => ['nullable', 'integer', Rule::in($this->invoicesFor($customerId, $payment)->pluck('id')->all())],
            'amount' => ['required', ...self::MONEY],
            'mode' => ['required', Rule::in(array_keys(Payment::MODES))],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['customer.in' => 'Select a valid customer.', 'income.in' => 'Select a valid invoice of this customer.']);

        $payment->fill([
            'date' => $data['date'], 'customer_id' => $data['customer'], 'income_id' => $data['income'] ?? null,
            'amount' => $data['amount'], 'mode' => $data['mode'], 'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->failIf($payment->businessErrors());
    }

    /** Invoices of a customer with an unpaid balance (for the payment form). */
    public function openInvoices(Request $request)
    {
        $customer = $this->idParam($request, 'customer');
        $include = (string) $request->query('include', '');
        if (! $customer) {
            return response()->json(['invoices' => []]);
        }
        $rows = [];
        foreach (Income::where('customer_id', $customer)->notRejected()->orderBy('date')->orderBy('id')->get() as $inc) {
            $committed = Payment::totalOf($inc->payments()->notRejected()->getQuery());
            $due = Money::sub($inc->amount, $committed);
            if (Money::isPositive($due) || (string) $inc->id === $include) {
                $pending = $inc->status === 'PENDING' ? ' · pending approval' : '';
                $rows[] = ['id' => $inc->id, 'due' => $due, 'label' => "{$inc->number} · {$inc->date->format('d M Y')} · due {$due}{$pending}"];
            }
        }

        return response()->json(['invoices' => $rows]);
    }

    public function confirmDelete(Payment $payment)
    {
        return view('partials.confirm-delete', ['label' => (string) $payment, 'cancel' => $payment->url()]);
    }

    public function destroy(Payment $payment)
    {
        return $this->auditedDelete($payment, "{$payment->number} deleted. A copy is kept in the audit log.", 'payments.index',
            'This record cannot be deleted because other records depend on it.', $payment->url());
    }
}
