<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Income;
use App\Models\Payment;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Services\Finance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    private function canSeeBalances(Request $request): bool
    {
        $u = $request->user();

        return $u->hasCapability('view_reports') || $u->hasCapability('record_payment');
    }

    private function query(Request $request): Builder
    {
        $q = Customer::query();
        if ($this->canSeeBalances($request)) {
            $q->withBalances();
        }
        if ($request->query('status') === 'active') {
            $q->where('is_active', true);
        } elseif ($request->query('status') === 'inactive') {
            $q->where('is_active', false);
        }

        return $this->search($q, $request, ['name', 'contact_person', 'phone', 'email', 'gstin'])->orderBy('name');
    }

    public function index(Request $request)
    {
        $show = $this->canSeeBalances($request);
        $export = $this->export($request, function () use ($request, $show) {
            $rows = $this->query($request)->get()->map(function (Customer $c) use ($show) {
                $row = ['name' => $c->name, 'contact' => $c->contact_person, 'phone' => $c->phone, 'email' => $c->email,
                    'gstin' => $c->gstin, 'status' => $c->is_active ? 'Active' : 'Inactive'];
                if ($show) {
                    $row += ['billed' => Money::of($c->billed), 'received' => Money::of($c->received), 'balance' => $c->balance()];
                }

                return $row;
            })->all();
            $cols = [['Customer', 'name', 'text'], ['Contact', 'contact', 'text'], ['Phone', 'phone', 'text'],
                ['Email', 'email', 'text'], ['GSTIN', 'gstin', 'text'], ['Status', 'status', 'text']];
            if ($show) {
                $cols = [...$cols, ['Billed', 'billed', 'money'], ['Received', 'received', 'money'], ['Balance', 'balance', 'money']];
            }

            return new ReportTable(title: 'Customer Register', columns: $cols, rows: $rows);
        });
        if ($export) {
            return $export;
        }

        return view('customers.index', [
            'customers' => $this->query($request)->paginate($this->perPage())->withQueryString(),
            'showBalances' => $show,
        ]);
    }

    public function show(Request $request, Customer $customer)
    {
        $user = $request->user();
        $data = ['customer' => $customer, 'showBalances' => $this->canSeeBalances($request)];
        if ($data['showBalances']) {
            [$data['statement'], $data['totals']] = Finance::customerStatement($customer);
            $data['openInvoices'] = $customer->incomes()->approved()->orderBy('date')->orderBy('id')->get()
                ->filter(fn (Income $i) => Money::isPositive($i->outstanding()))->values();
            $data['pendingIncome'] = $customer->incomes()->pending()->count();
            $data['pendingPayments'] = $customer->payments()->pending()->count();
        } else {
            $data['myIncome'] = Income::visibleTo($user)->where('customer_id', $customer->id)->with('vehicle')
                ->orderByDesc('date')->orderByDesc('id')->limit(10)->get();
        }

        return view('customers.show', $data);
    }

    public function create()
    {
        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(Request $request)
    {
        $customer = new Customer;
        $this->save($request, $customer);
        Audit::created($customer);
        $next = $this->safeNext($request, route('customers.show', $customer));

        return redirect($next)->with('success', "{$customer} saved.");
    }

    public function edit(Customer $customer)
    {
        return view('customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer)
    {
        $before = Audit::snapshot($customer);
        $this->save($request, $customer);
        Audit::updated($customer, $before);

        return redirect()->route('customers.show', $customer)->with('success', "{$customer} updated.");
    }

    private function save(Request $request, Customer $customer): void
    {
        $request->merge(['name' => preg_replace('/\s+/', ' ', trim((string) $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', function ($attr, $value, $fail) use ($customer) {
                $taken = Customer::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                    ->when($customer->exists, fn ($q) => $q->whereKeyNot($customer->id))->exists();
                if ($taken) {
                    $fail('A customer with this name already exists.');
                }
            }],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value && strlen(preg_replace('/\D/', '', (string) $value)) < 10) {
                    $fail('Enter a valid phone number (at least 10 digits).');
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', function ($attr, $value, $fail) {
                $v = strtoupper(trim((string) $value));
                if ($v !== '' && (strlen($v) !== 15 || ! ctype_alnum($v))) {
                    $fail('GSTIN must be 15 letters/digits.');
                }
            }],
            'opening_balance' => ['required', ...self::MONEY],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['gstin'] = isset($data['gstin']) ? strtoupper(trim($data['gstin'])) : null;
        $customer->fill($data);
        $customer->is_active = $request->boolean('is_active');
        if (! $customer->exists) {
            $customer->created_by = $request->user()->id;
        }
        $customer->save();
    }

    public function confirmDelete(Customer $customer)
    {
        return view('partials.confirm-delete', ['label' => (string) $customer, 'cancel' => route('customers.show', $customer)]);
    }

    public function destroy(Customer $customer)
    {
        return $this->auditedDelete($customer, "{$customer} deleted.", 'customers.index',
            'This customer has invoices or payments and cannot be deleted. Mark them inactive instead.',
            route('customers.show', $customer));
    }

    public function outstanding(Request $request)
    {
        $showAll = $request->query('show') === 'all';
        $rows = Finance::customerOutstandingRows(trim((string) $request->query('q', '')), ! $showAll);
        $totals = [
            'opening' => Money::sum(array_column($rows, 'opening')),
            'billed' => Money::sum(array_column($rows, 'billed')),
            'received' => Money::sum(array_column($rows, 'received')),
            // Advances held for one customer do not reduce what others owe.
            'balance' => Money::sum(array_filter(array_column($rows, 'balance'), fn ($b) => Money::isPositive($b))),
        ];
        $export = $this->export($request, fn () => new ReportTable(
            title: 'Customer Outstanding Balances',
            columns: [['Customer', 'name', 'text'], ['Phone', 'phone', 'text'], ['Opening balance', 'opening', 'money'],
                ['Billed', 'billed', 'money'], ['Received', 'received', 'money'], ['Outstanding', 'balance', 'money']],
            rows: array_map(fn ($r) => ['name' => $r['customer']->name, 'phone' => $r['customer']->phone, 'opening' => $r['opening'],
                'billed' => $r['billed'], 'received' => $r['received'], 'balance' => $r['balance']], $rows),
            totals: $totals,
            filters: [['Showing', $showAll ? 'All customers' : 'Customers with dues'], ['Basis', 'Approved invoices and payments to date']],
        ));
        if ($export) {
            return $export;
        }

        return view('customers.outstanding', [
            'rows' => $rows, 'totals' => $totals, 'pendingPayments' => Payment::pending()->count(),
        ]);
    }
}
