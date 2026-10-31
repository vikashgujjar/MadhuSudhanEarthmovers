<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Expense;
use App\Models\Vehicle;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    private function query(Request $request): Builder
    {
        $q = Driver::with('assignedVehicle');
        if (isset(Driver::STATUSES[(string) $request->query('status')])) {
            $q->where('status', $request->query('status'));
        }
        if ($vehicle = $this->idParam($request, 'vehicle')) {
            $q->where('assigned_vehicle_id', $vehicle);
        }

        return $this->search($q, $request, ['name', 'phone', 'license_number'])->orderBy('name');
    }

    public function index(Request $request)
    {
        $export = $this->export($request, fn () => new ReportTable(
            title: 'Driver Register',
            columns: [['Name', 'name', 'text'], ['Phone', 'phone', 'text'], ['Licence no.', 'license', 'text'],
                ['Licence expiry', 'expiry', 'date'], ['Assigned vehicle', 'vehicle', 'text'],
                ['Monthly salary', 'salary', 'money'], ['Daily allowance', 'bata', 'money'], ['Status', 'status', 'text']],
            rows: $this->query($request)->get()->map(fn (Driver $d) => [
                'name' => $d->name, 'phone' => $d->phone, 'license' => $d->license_number, 'expiry' => $d->license_expiry,
                'vehicle' => $d->assignedVehicle?->displayName() ?? '', 'salary' => $d->monthly_salary,
                'bata' => $d->daily_allowance, 'status' => $d->statusLabel(),
            ])->all(),
        ));
        if ($export) {
            return $export;
        }

        return view('fleet.drivers', [
            'drivers' => $this->query($request)->paginate($this->perPage())->withQueryString(),
            'vehicles' => Vehicle::where('status', '!=', 'INACTIVE')->orderBy('category')->orderBy('registration_number')->get(),
        ]);
    }

    public function show(Request $request, Driver $driver)
    {
        $user = $request->user();
        $data = [
            'driver' => $driver->load('assignedVehicle'),
            'expenses' => Expense::visibleTo($user)->where('driver_id', $driver->id)->with('vehicle')
                ->orderByDesc('date')->orderByDesc('id')->limit(15)->get(),
            'period' => null,
        ];
        if ($user->hasCapability('view_reports')) {
            $period = $this->period($request);
            $approved = Expense::approved()->between($period->startDate(), $period->endDate())
                ->where('expenses.driver_id', $driver->id)->where('expenses.category', 'DRIVER');
            $data['period'] = $period;
            $data['total'] = Expense::totalOf($approved);
            $data['byType'] = (clone $approved)->selectRaw('driver_expense_type as k, SUM(amount) as t')
                ->groupBy('driver_expense_type')->get()
                ->sortByDesc(fn ($r) => Money::toPaise($r->t))
                ->map(fn ($r) => ['label' => Expense::DRIVER_TYPES[$r->k] ?? 'Other', 'amount' => Money::of($r->t)])->values();
            $data['byVehicle'] = (clone $approved)->leftJoin('vehicles', 'vehicles.id', '=', 'expenses.vehicle_id')
                ->selectRaw('vehicles.name as vname, vehicles.registration_number as reg, SUM(expenses.amount) as t')
                ->groupBy('vehicles.id', 'vehicles.name', 'vehicles.registration_number')->get()
                ->sortByDesc(fn ($r) => Money::toPaise($r->t))
                ->map(fn ($r) => ['label' => $r->vname ?: ($r->reg ?: 'Not vehicle-specific'), 'amount' => Money::of($r->t)])->values();
        }

        return view('fleet.driver-show', $data);
    }

    public function create()
    {
        return view('fleet.driver-form', ['driver' => new Driver, 'vehicles' => $this->vehicleChoices()]);
    }

    public function store(Request $request)
    {
        $driver = new Driver;
        $this->save($request, $driver);
        Audit::created($driver);

        return redirect()->route('drivers.show', $driver)->with('success', "{$driver} saved.");
    }

    public function edit(Driver $driver)
    {
        return view('fleet.driver-form', ['driver' => $driver, 'vehicles' => $this->vehicleChoices($driver)]);
    }

    public function update(Request $request, Driver $driver)
    {
        $before = Audit::snapshot($driver);
        $this->save($request, $driver);
        Audit::updated($driver, $before);

        return redirect()->route('drivers.show', $driver)->with('success', "{$driver} updated.");
    }

    private function vehicleChoices(?Driver $driver = null)
    {
        return Vehicle::where('status', '!=', 'INACTIVE')
            ->when($driver?->assigned_vehicle_id, fn ($q, $id) => $q->orWhere('id', $id))
            ->orderBy('category')->orderBy('registration_number')->get();
    }

    private function save(Request $request, Driver $driver): void
    {
        $phone = function ($attr, $value, $fail) {
            if ($value && strlen(preg_replace('/\D/', '', (string) $value)) < 10) {
                $fail('Enter a valid phone number (at least 10 digits).');
            }
        };
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', $phone],
            'alternate_phone' => ['nullable', 'string', 'max:20'],
            'license_number' => ['nullable', 'string', 'max:40'],
            'license_expiry' => ['nullable', 'date_format:Y-m-d'],
            'assigned_vehicle' => ['nullable', 'integer', Rule::in($this->vehicleChoices($driver)->pluck('id')->all())],
            'monthly_salary' => ['required', ...self::MONEY],
            'daily_allowance' => ['required', ...self::MONEY],
            'joining_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(array_keys(Driver::STATUSES))],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['assigned_vehicle.in' => 'Select a valid vehicle.']);
        $data['assigned_vehicle_id'] = $data['assigned_vehicle'] ?? null;
        unset($data['assigned_vehicle']);
        $driver->fill($data);
        if (! $driver->exists) {
            $driver->created_by = $request->user()->id;
        }
        $driver->save();
    }

    public function confirmDelete(Driver $driver)
    {
        return view('partials.confirm-delete', ['label' => (string) $driver, 'cancel' => route('drivers.show', $driver)]);
    }

    public function destroy(Driver $driver)
    {
        return $this->auditedDelete($driver, "{$driver} deleted.", 'drivers.index',
            'This driver has expense records and cannot be deleted. Mark the driver Inactive instead.',
            route('drivers.show', $driver));
    }
}
