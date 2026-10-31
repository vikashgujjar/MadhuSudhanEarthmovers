<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Vehicle;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Services\Finance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    private function query(Request $request, ?string $category): Builder
    {
        $q = Vehicle::query()->withCount('drivers');
        $category ??= $request->query('category');
        if (is_string($category) && isset(Vehicle::CATEGORIES[$category])) {
            $q->where('category', $category);
        }
        if (isset(Vehicle::STATUSES[(string) $request->query('status')])) {
            $q->where('status', $request->query('status'));
        }

        return $this->search($q, $request, ['registration_number', 'name', 'make', 'model'])
            ->orderBy('category')->orderBy('registration_number');
    }

    private function table(Request $request, ?string $category): ReportTable
    {
        $rows = $this->query($request, $category)->get()->map(fn (Vehicle $v) => [
            'category' => $v->categoryLabel(), 'reg' => $v->registration_number, 'name' => $v->name,
            'make' => trim("{$v->make} {$v->model}"), 'year' => $v->year ?: '', 'hourly' => $v->hourly_rate,
            'daily' => $v->daily_rate, 'status' => $v->statusLabel(), 'insurance' => $v->insurance_expiry,
        ])->all();

        return new ReportTable(
            title: $category ? Vehicle::CATEGORIES[$category].' Fleet Register' : 'Vehicle Register',
            columns: [['Category', 'category', 'text'], ['Registration', 'reg', 'text'], ['Name', 'name', 'text'],
                ['Make / model', 'make', 'text'], ['Year', 'year', 'text'], ['Hourly rate', 'hourly', 'money'],
                ['Daily rate', 'daily', 'money'], ['Status', 'status', 'text'], ['Insurance expiry', 'insurance', 'date']],
            rows: $rows,
        );
    }

    public function index(Request $request)
    {
        if ($r = $this->export($request, fn () => $this->table($request, null))) {
            return $r;
        }
        $vehicles = $this->query($request, null)->paginate($this->perPage())->withQueryString();

        return view('fleet.vehicles', ['vehicles' => $vehicles, 'profitMap' => null, 'category' => null]);
    }

    public function jcb(Request $request)
    {
        return $this->categoryScreen($request, 'JCB');
    }

    public function tractors(Request $request)
    {
        return $this->categoryScreen($request, 'TRACTOR');
    }

    private function categoryScreen(Request $request, string $category)
    {
        if ($r = $this->export($request, fn () => $this->table($request, $category))) {
            return $r;
        }
        $base = Vehicle::where('category', $category);
        $data = [
            'category' => $category,
            'categoryLabel' => Vehicle::CATEGORIES[$category],
            'vehicles' => $this->query($request, $category)->paginate($this->perPage())->withQueryString(),
            'counts' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('status', 'ACTIVE')->count(),
                'maintenance' => (clone $base)->where('status', 'MAINTENANCE')->count(),
            ],
            'period' => null, 'profitMap' => null, 'catTotals' => null,
        ];
        if ($request->user()->hasCapability('view_reports')) {
            $period = $this->period($request);
            $rows = Finance::vehicleProfitability($period->startDate(), $period->endDate(), $category);
            $data['period'] = $period;
            $data['profitMap'] = collect($rows)->keyBy(fn ($r) => $r['vehicle']->id)->all();
            $data['catTotals'] = [
                'income' => Money::sum(array_column($rows, 'income')),
                'expense' => Money::sum(array_column($rows, 'expense')),
                'profit' => Money::sum(array_column($rows, 'profit')),
            ];
        }

        return view('fleet.category', $data);
    }

    public function show(Request $request, Vehicle $vehicle)
    {
        $user = $request->user();
        $data = [
            'vehicle' => $vehicle,
            'drivers' => $vehicle->drivers()->orderBy('name')->get(),
            'incomes' => Income::visibleTo($user)->where('vehicle_id', $vehicle->id)->with('customer')
                ->orderByDesc('date')->orderByDesc('id')->limit(10)->get(),
            'expenses' => Expense::visibleTo($user)->where('vehicle_id', $vehicle->id)->with('driver')
                ->orderByDesc('date')->orderByDesc('id')->limit(10)->get(),
            'period' => null, 'stats' => null, 'expenseBreakdown' => [],
        ];
        if ($user->hasCapability('view_reports')) {
            $period = $this->period($request);
            $rows = Finance::vehicleProfitability($period->startDate(), $period->endDate());
            $data['period'] = $period;
            $data['stats'] = Arr::first($rows, fn ($r) => $r['vehicle']->id === $vehicle->id);
            $data['expenseBreakdown'] = Finance::expenseBreakdown(
                Expense::approved()->between($period->startDate(), $period->endDate())->where('vehicle_id', $vehicle->id)
            );
        }

        return view('fleet.vehicle-show', $data);
    }

    public function create(Request $request)
    {
        $vehicle = new Vehicle;
        if (isset(Vehicle::CATEGORIES[(string) $request->query('category')])) {
            $vehicle->category = $request->query('category');
        }

        return view('fleet.vehicle-form', ['vehicle' => $vehicle]);
    }

    public function store(Request $request)
    {
        $vehicle = new Vehicle;
        $this->save($request, $vehicle);
        Audit::created($vehicle);

        return redirect()->route('vehicles.show', $vehicle)->with('success', "{$vehicle} saved.");
    }

    public function edit(Vehicle $vehicle)
    {
        return view('fleet.vehicle-form', ['vehicle' => $vehicle]);
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $before = Audit::snapshot($vehicle);
        $this->save($request, $vehicle);
        Audit::updated($vehicle, $before);

        return redirect()->route('vehicles.show', $vehicle)->with('success', "{$vehicle} updated.");
    }

    private function save(Request $request, Vehicle $vehicle): void
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Vehicle::CATEGORIES))],
            'registration_number' => ['required', 'string', 'max:20', function ($attr, $value, $fail) use ($vehicle) {
                $compact = Vehicle::compactPlate((string) $value);
                if ($compact === '') {
                    $fail('Registration number is required.');

                    return;
                }
                $taken = Vehicle::query()->when($vehicle->exists, fn ($q) => $q->whereKeyNot($vehicle->id))
                    ->whereRaw("REPLACE(REPLACE(UPPER(registration_number), ' ', ''), '-', '') = ?", [$compact])->exists();
                if ($taken) {
                    $fail('A vehicle with this registration number already exists.');
                }
            }],
            'name' => ['nullable', 'string', 'max:80'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'chassis_number' => ['nullable', 'string', 'max:40'],
            'engine_number' => ['nullable', 'string', 'max:40'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'purchase_cost' => ['nullable', ...self::MONEY],
            'hourly_rate' => ['required', ...self::MONEY],
            'daily_rate' => ['required', ...self::MONEY],
            'insurance_expiry' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(array_keys(Vehicle::STATUSES))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], ['registration_number.required' => 'Registration number is required.']);

        if ($vehicle->exists && $data['category'] !== $vehicle->category
            && ($vehicle->incomes()->exists() || $vehicle->expenses()->exists())) {
            $this->failIf(['category' => 'Category cannot be changed because transactions are already recorded against this vehicle.']);
        }
        $vehicle->fill($data);
        if (! $vehicle->exists) {
            $vehicle->created_by = $request->user()->id;
        }
        $vehicle->save();
    }

    public function confirmDelete(Vehicle $vehicle)
    {
        return view('partials.confirm-delete', ['label' => (string) $vehicle, 'cancel' => route('vehicles.show', $vehicle)]);
    }

    public function destroy(Vehicle $vehicle)
    {
        return $this->auditedDelete($vehicle, "{$vehicle} deleted.", 'vehicles.index',
            "This vehicle has income or expense records and cannot be deleted. Set its status to 'Inactive / sold' instead to keep the history intact.",
            route('vehicles.show', $vehicle));
    }

    /** JSON list of usable vehicles for a category (category-first selection). */
    public function options(Request $request)
    {
        $category = $request->query('category');
        if (! is_string($category) || ! isset(Vehicle::CATEGORIES[$category])) {
            return response()->json(['vehicles' => [], 'error' => 'Unknown category'], 400);
        }
        $vehicles = Vehicle::where('category', $category)->where('status', '!=', 'INACTIVE')
            ->orderBy('registration_number')->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id, 'label' => $v->optionLabel(),
                'hourly_rate' => $v->hourly_rate, 'daily_rate' => $v->daily_rate,
            ]);

        return response()->json(['vehicles' => $vehicles]);
    }
}
