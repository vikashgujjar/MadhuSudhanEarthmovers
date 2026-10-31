<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Income;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Services\Workflow;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncomeController extends TransactionController
{
    private const SEARCH = ['number', 'work_location', 'description', 'customer.name', 'vehicle.registration_number', 'vehicle.name'];

    private function query(Request $request, $period): Builder
    {
        $q = Income::query()->with(['customer', 'vehicle', 'driver']);
        $this->baseFilters($q, $request, $period, self::SEARCH);
        $this->vehicleFilters($q, $request);
        if ($customer = $this->idParam($request, 'customer')) {
            $q->where('incomes.customer_id', $customer);
        }
        if (isset(Income::BILLING_TYPES[(string) $request->query('billing_type')])) {
            $q->where('incomes.billing_type', $request->query('billing_type'));
        }
        $received = DB::table('payments')->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('payments.income_id', 'incomes.id')->where('payments.status', 'APPROVED');
        $q->select('incomes.*')->selectSub($received, 'received');
        $state = $request->query('payment');
        $sub = '(SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.income_id = incomes.id AND p.status = \'APPROVED\')';
        if ($state === 'unpaid') {
            $q->whereRaw("{$sub} = 0");
        } elseif ($state === 'partial') {
            $q->whereRaw("{$sub} > 0")->whereRaw("{$sub} < incomes.amount");
        } elseif ($state === 'paid') {
            $q->whereRaw("{$sub} >= incomes.amount");
        }

        return $q->orderByDesc('incomes.date')->orderByDesc('incomes.id');
    }

    public function index(Request $request)
    {
        $period = $this->listPeriod($request);
        if ($r = $this->export($request, fn () => $this->table($request, $period))) {
            return $r;
        }
        $all = $this->query($request, $period);
        $approved = (clone $all)->where('incomes.status', 'APPROVED');
        $totalApproved = Income::totalOf($approved);
        $totalReceived = Money::of(DB::query()->fromSub((clone $approved)->reorder()->toBase(), 't')->sum('t.received'));

        return view('finance.income-index', [
            'incomes' => $all->paginate($this->perPage())->withQueryString(),
            'period' => $period,
            'vehicles' => $this->vehicleChoices($request),
            'customers' => Customer::orderBy('name')->get(),
            'totalApproved' => $totalApproved,
            'totalReceived' => $totalReceived,
            'totalOutstanding' => Money::sub($totalApproved, $totalReceived),
            'totalPending' => Income::totalOf((clone $all)->where('incomes.status', 'PENDING')),
        ]);
    }

    private function table(Request $request, $period): ReportTable
    {
        $rows = [];
        $sums = ['amount' => 0, 'received' => 0, 'balance' => 0];
        foreach ($this->query($request, $period)->get() as $i) {
            $balance = Money::sub($i->amount, $i->received);
            $rows[] = [
                'date' => $i->date, 'number' => $i->number, 'customer' => $i->customer->name, 'category' => $i->categoryLabel(),
                'vehicle' => $i->vehicle->displayName(), 'billing' => $i->billingLabel(),
                'qty' => $i->quantity ? qty($i->quantity).' '.$i->unitLabel().' × '.$i->rate : '',
                'amount' => $i->amount, 'received' => Money::of($i->received), 'balance' => $balance, 'status' => $i->statusLabel(),
            ];
            if ($i->isApproved()) {
                $sums['amount'] += Money::toPaise($i->amount);
                $sums['received'] += Money::toPaise($i->received);
                $sums['balance'] += Money::toPaise($balance);
            }
        }

        return new ReportTable(
            title: 'Income Register',
            columns: [['Date', 'date', 'date'], ['Invoice', 'number', 'text'], ['Customer', 'customer', 'text'],
                ['Category', 'category', 'text'], ['Vehicle', 'vehicle', 'text'], ['Billing', 'billing', 'text'],
                ['Qty × rate', 'qty', 'text'], ['Billed', 'amount', 'money'], ['Received', 'received', 'money'],
                ['Balance', 'balance', 'money'], ['Status', 'status', 'text']],
            rows: $rows,
            totals: array_map(fn ($p) => Money::fromPaise($p), $sums),
            filters: $this->filterSummary($request, $period, Income::STATUSES),
            totalsLabel: 'Total (approved)',
        );
    }

    public function show(Request $request, int $income)
    {
        $income = $this->findVisible(Income::class, $income, $request);
        $income->load(['customer', 'vehicle', 'driver', 'creator', 'approver']);

        return view('finance.income-show', [
            'income' => $income,
            'payments' => $income->payments()->orderBy('date')->orderBy('id')->get(),
            'history' => Audit::history($income),
        ]);
    }

    public function create(Request $request)
    {
        $income = new Income(['date' => now()->toDateString()]);
        $income->fill($this->prefill($request, ['vehicle_category']));
        $prefill = $this->prefill($request, ['vehicle', 'customer', 'driver']);

        return $this->form($income, true, $prefill);
    }

    private function form(Income $income, bool $withPayment, array $prefill = [])
    {
        $category = old('vehicle_category', $income->vehicle_category);

        return view('finance.income-form', [
            'income' => $income,
            'withPayment' => $withPayment,
            'prefill' => $prefill,
            'vehicles' => $this->vehiclesFor($category, $income->vehicle_id),
            'customers' => $this->activeCustomers($income->customer_id),
            'drivers' => $this->activeDrivers($income->driver_id),
        ]);
    }

    public function store(Request $request)
    {
        $income = new Income;
        $data = $this->validated($request, $income, true);
        $received = $data['received_now'] ?? null;

        return $this->createTransaction($income, $request, function (Income $inc) use ($received, $data, $request) {
            if ($received !== null && Money::isPositive($received)) {
                $pay = new Payment([
                    'date' => $inc->date->toDateString(), 'customer_id' => $inc->customer_id, 'income_id' => $inc->id,
                    'amount' => $received, 'mode' => $data['payment_mode'] ?? 'CASH',
                    'reference' => $data['payment_reference'] ?? null, 'notes' => 'Received at the time of billing',
                ]);
                $pay->is_initial = true;
                Workflow::stampNew($pay, $request->user());
                $this->failIf($pay->businessErrors());
                $pay->save();
                Audit::created($pay);
            }
        });
    }

    public function edit(Request $request, int $income)
    {
        return $this->form($this->findEditable(Income::class, $income, $request), false);
    }

    public function update(Request $request, int $income)
    {
        $income = $this->findEditable(Income::class, $income, $request);
        $before = Audit::snapshot($income);
        $this->validated($request, $income, false);

        return $this->updateTransaction($income, $before, $request);
    }

    /** Validate input, fill the model and apply the business rules. */
    private function validated(Request $request, Income $income, bool $withPayment): array
    {
        $category = $request->input('vehicle_category');
        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'vehicle_category' => ['required', Rule::in(array_keys(Vehicle::CATEGORIES))],
            'vehicle' => ['required', 'integer', Rule::in($this->vehiclesFor(is_string($category) ? $category : null, $income->vehicle_id)->pluck('id')->all())],
            'customer' => ['required', 'integer', Rule::in($this->activeCustomers($income->customer_id)->pluck('id')->all())],
            'driver' => ['nullable', 'integer', Rule::in($this->activeDrivers($income->driver_id)->pluck('id')->all())],
            'billing_type' => ['required', Rule::in(array_keys(Income::BILLING_TYPES))],
            'quantity' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'rate' => ['nullable', ...self::MONEY],
            'amount' => ['nullable', ...self::MONEY],
            'work_location' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
        if ($withPayment) {
            $rules += [
                'received_now' => ['nullable', ...self::MONEY],
                'payment_mode' => ['required', Rule::in(array_keys(Payment::MODES))],
                'payment_reference' => ['nullable', 'string', 'max:80'],
            ];
        }
        $data = $request->validate($rules, [
            'vehicle.required' => 'Select the vehicle that did the work.',
            'vehicle.in' => 'Select a valid vehicle for the chosen category.',
            'customer.in' => 'Select a valid customer.',
            'driver.in' => 'Select a valid driver.',
        ]);

        $income->fill([
            'date' => $data['date'], 'vehicle_category' => $data['vehicle_category'], 'vehicle_id' => $data['vehicle'],
            'customer_id' => $data['customer'], 'driver_id' => $data['driver'] ?? null, 'billing_type' => $data['billing_type'],
            'quantity' => $data['quantity'] ?? null, 'rate' => $data['rate'] ?? null, 'amount' => $data['amount'] ?? null,
            'work_location' => $data['work_location'] ?? null, 'description' => $data['description'] ?? null,
        ]);
        $this->failIf($income->businessErrors());
        if ($withPayment && isset($data['received_now']) && Money::cmp($data['received_now'], $income->amount) > 0) {
            $this->failIf(['received_now' => "Cannot exceed the billed amount ({$income->amount})."]);
        }

        return $data;
    }

    public function confirmDelete(Income $income)
    {
        return view('partials.confirm-delete', ['label' => (string) $income, 'cancel' => $income->url()]);
    }

    public function destroy(Income $income)
    {
        return $this->auditedDelete($income, "{$income->number} deleted. A copy is kept in the audit log.", 'income.index',
            'This invoice has payments recorded against it. Delete those payments first.', $income->url());
    }
}
