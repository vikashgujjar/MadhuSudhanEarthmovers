<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends TransactionController
{
    private const SEARCH = ['number', 'vendor', 'bill_number', 'description', 'driver.name', 'vehicle.registration_number', 'vehicle.name'];

    private function query(Request $request, $period, bool $driverOnly): Builder
    {
        $q = Expense::query()->with(['vehicle', 'driver']);
        if ($driverOnly) {
            $q->where('expenses.category', 'DRIVER');
        }
        $this->baseFilters($q, $request, $period, self::SEARCH);
        $this->vehicleFilters($q, $request);
        if (! $driverOnly && isset(Expense::CATEGORIES[(string) $request->query('category')])) {
            $q->where('expenses.category', $request->query('category'));
        }
        if ($driver = $this->idParam($request, 'driver')) {
            $q->where('expenses.driver_id', $driver);
        }
        if ($driverOnly && isset(Expense::DRIVER_TYPES[(string) $request->query('type')])) {
            $q->where('expenses.driver_expense_type', $request->query('type'));
        }
        if (isset(Payment::MODES[(string) $request->query('mode')])) {
            $q->where('expenses.payment_mode', $request->query('mode'));
        }
        if ($request->query('general') === '1') {
            $q->whereNull('expenses.vehicle_id');
        }

        return $q->orderByDesc('expenses.date')->orderByDesc('expenses.id');
    }

    public function index(Request $request)
    {
        return $this->register($request, false);
    }

    public function driverIndex(Request $request)
    {
        return $this->register($request, true);
    }

    private function register(Request $request, bool $driverOnly)
    {
        $period = $this->listPeriod($request);
        $title = $driverOnly ? 'Driver Expense Register' : 'Expense Register';
        if ($r = $this->export($request, fn () => $this->table($request, $period, $driverOnly, $title))) {
            return $r;
        }
        $all = $this->query($request, $period, $driverOnly);
        $approved = (clone $all)->where('expenses.status', 'APPROVED')->reorder();
        $data = [
            'expenses' => (clone $all)->paginate($this->perPage())->withQueryString(),
            'driverOnly' => $driverOnly,
            'period' => $period,
            'vehicles' => $this->vehicleChoices($request),
            'drivers' => Driver::orderBy('name')->get(),
            'totalApproved' => Expense::totalOf($approved),
            'totalPending' => Expense::totalOf((clone $all)->where('expenses.status', 'PENDING')),
            'totalCount' => (clone $all)->count(),
        ];
        if ($driverOnly) {
            $data['byDriver'] = (clone $approved)->join('drivers', 'drivers.id', '=', 'expenses.driver_id')
                ->selectRaw('drivers.id as did, drivers.name as dname, SUM(expenses.amount) as t')
                ->groupBy('drivers.id', 'drivers.name')->get()
                ->sortByDesc(fn ($r) => Money::toPaise($r->t))->take(8)->values();
        } else {
            $data['byCategory'] = (clone $approved)->selectRaw('expenses.category as k, SUM(expenses.amount) as t')
                ->groupBy('expenses.category')->get()
                ->sortByDesc(fn ($r) => Money::toPaise($r->t))
                ->map(fn ($r) => ['key' => $r->k, 'label' => Expense::CATEGORIES[$r->k] ?? $r->k, 't' => $r->t])->values();
        }

        return view('finance.expense-index', $data);
    }

    private function table(Request $request, $period, bool $driverOnly, string $title): ReportTable
    {
        $rows = [];
        $total = 0;
        foreach ($this->query($request, $period, $driverOnly)->get() as $e) {
            $rows[] = [
                'date' => $e->date, 'number' => $e->number, 'category' => $e->categoryLabel(),
                'type' => $e->isDriverExpense() ? $e->driverTypeLabel() : '',
                'vehicle' => $e->vehicle ? $e->vehicle->displayName() : 'General', 'driver' => $e->driver?->name ?? '',
                'vendor' => $e->vendor, 'mode' => $e->modeLabel(), 'litres' => $e->litres, 'amount' => $e->amount,
                'status' => $e->statusLabel(),
            ];
            if ($e->isApproved()) {
                $total += Money::toPaise($e->amount);
            }
        }
        $cols = [['Date', 'date', 'date'], ['No.', 'number', 'text']];
        $cols = $driverOnly
            ? [...$cols, ['Driver', 'driver', 'text'], ['Type', 'type', 'text'], ['Vehicle', 'vehicle', 'text']]
            : [...$cols, ['Category', 'category', 'text'], ['Vehicle', 'vehicle', 'text'], ['Driver', 'driver', 'text'], ['Litres', 'litres', 'number']];
        $cols = [...$cols, ['Vendor', 'vendor', 'text'], ['Paid via', 'mode', 'text'], ['Amount', 'amount', 'money'], ['Status', 'status', 'text']];

        return new ReportTable(
            title: $title, columns: $cols, rows: $rows, totals: ['amount' => Money::fromPaise($total)],
            filters: $this->filterSummary($request, $period, Expense::STATUSES), totalsLabel: 'Total (approved)',
        );
    }

    public function show(Request $request, int $expense)
    {
        $expense = $this->findVisible(Expense::class, $expense, $request);
        $expense->load(['vehicle', 'driver', 'creator', 'approver']);

        return view('finance.expense-show', ['expense' => $expense, 'history' => Audit::history($expense)]);
    }

    public function create(Request $request)
    {
        return $this->form($request, new Expense(['date' => now()->toDateString()]), false);
    }

    public function driverCreate(Request $request)
    {
        $expense = new Expense(['date' => now()->toDateString()]);
        $expense->category = 'DRIVER';

        return $this->form($request, $expense, true);
    }

    private function form(Request $request, Expense $expense, bool $driverMode)
    {
        if (! $expense->exists) {
            $expense->fill($this->prefill($request, ['vehicle_category', 'driver_expense_type']));
        }
        $category = old('vehicle_category', $expense->vehicle_category);

        return view('finance.expense-form', [
            'expense' => $expense,
            'driverMode' => $driverMode,
            'prefill' => $expense->exists ? [] : $this->prefill($request, ['vehicle', 'driver']),
            'vehicles' => $this->vehiclesFor($category, $expense->vehicle_id),
            'drivers' => $this->activeDrivers($expense->driver_id),
        ]);
    }

    public function store(Request $request)
    {
        $expense = new Expense;
        $this->validated($request, $expense, false);

        return $this->createTransaction($expense, $request);
    }

    public function driverStore(Request $request)
    {
        $expense = new Expense;
        $this->validated($request, $expense, true);

        return $this->createTransaction($expense, $request);
    }

    /** The capability needed depends on the record being edited (driver vs other expense). */
    private function editable(Request $request, int $id): Expense
    {
        $expense = $this->findVisible(Expense::class, $id, $request);
        $capability = $expense->isDriverExpense() ? 'add_driver_expense' : 'add_expense';
        abort_unless($request->user()->hasCapability($capability), 403, 'You do not have permission to perform this action.');
        abort_unless($expense->canEdit($request->user()), 403, 'You can edit only your own entries, and only until they are approved. Ask an administrator to change this one.');

        return $expense;
    }

    public function edit(Request $request, int $expense)
    {
        $expense = $this->editable($request, $expense);

        return $this->form($request, $expense, $expense->isDriverExpense());
    }

    public function update(Request $request, int $expense)
    {
        $expense = $this->editable($request, $expense);
        $before = Audit::snapshot($expense);
        $this->validated($request, $expense, $expense->isDriverExpense());

        return $this->updateTransaction($expense, $before, $request);
    }

    private function validated(Request $request, Expense $expense, bool $driverMode): void
    {
        $vcat = $request->input('vehicle_category');
        $vcat = is_string($vcat) && isset(Vehicle::CATEGORIES[$vcat]) ? $vcat : null;
        $categories = array_keys(Expense::CATEGORIES);
        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'vehicle_category' => ['nullable', Rule::in(array_keys(Vehicle::CATEGORIES))],
            'vehicle' => ['nullable', 'integer', Rule::in($this->vehiclesFor($vcat, $expense->vehicle_id)->pluck('id')->all())],
            'driver' => [$driverMode ? 'required' : 'nullable', 'integer', Rule::in($this->activeDrivers($expense->driver_id)->pluck('id')->all())],
            'amount' => ['required', ...self::MONEY],
            'payment_mode' => ['required', Rule::in(array_keys(Payment::MODES))],
            'vendor' => ['nullable', 'string', 'max:150'],
            'bill_number' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
        if ($driverMode) {
            $rules['driver_expense_type'] = ['required', Rule::in(array_keys(Expense::DRIVER_TYPES))];
        } else {
            $rules['category'] = ['required', Rule::in(array_diff($categories, ['DRIVER'])), 'not_in:DRIVER'];
            $rules['litres'] = ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999.99'];
        }
        $data = $request->validate($rules, [
            'category.required' => 'Select the expense type.',
            'category.in' => "Use 'Driver Expenses' to record driver expenses.",
            'driver.required' => 'Select the driver this expense belongs to.',
            'driver_expense_type.required' => 'Select the type of driver expense.',
            'vehicle.in' => 'Select a valid vehicle for the chosen category.',
            'attachment.max' => 'File must be smaller than 5 MB.',
            'attachment.mimes' => 'Upload a PDF, JPG, PNG or WebP file.',
        ]);
        $category = $driverMode ? 'DRIVER' : $data['category'];
        if (in_array($category, Expense::VEHICLE_REQUIRED, true) && ! $vcat) {
            $this->failIf(['vehicle_category' => 'Select the vehicle category first (JCB or Tractor).']);
        }

        $expense->fill([
            'date' => $data['date'], 'category' => $category, 'vehicle_category' => $vcat,
            'vehicle_id' => $vcat ? ($data['vehicle'] ?? null) : null, 'driver_id' => $data['driver'] ?? null,
            'driver_expense_type' => $driverMode ? $data['driver_expense_type'] : null,
            'litres' => $driverMode ? null : ($data['litres'] ?? null), 'amount' => $data['amount'],
            'payment_mode' => $data['payment_mode'], 'vendor' => $data['vendor'] ?? null,
            'bill_number' => $data['bill_number'] ?? null, 'description' => $data['description'] ?? null,
        ]);
        $this->failIf($expense->businessErrors());

        if ($request->hasFile('attachment')) {
            $old = $expense->attachment;
            $expense->attachment = $request->file('attachment')->store('expense_bills/'.now()->format('Y/m'), 'local');
            if ($old) {
                Storage::disk('local')->delete($old);
            }
        }
    }

    public function confirmDelete(Expense $expense)
    {
        return view('partials.confirm-delete', ['label' => (string) $expense, 'cancel' => $expense->url()]);
    }

    public function destroy(Expense $expense)
    {
        $attachment = $expense->attachment;
        $response = $this->auditedDelete($expense, "{$expense->number} deleted. A copy is kept in the audit log.",
            $expense->isDriverExpense() ? 'driver-expenses.index' : 'expenses.index',
            'This record cannot be deleted because other records depend on it.', $expense->url());
        if ($attachment && ! Expense::whereKey($expense->id)->exists()) {
            Storage::disk('local')->delete($attachment);
        }

        return $response;
    }
}
