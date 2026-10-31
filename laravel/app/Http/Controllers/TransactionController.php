<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Income;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\Workflow;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Shared filtering, totals and create/update plumbing for income, expense and payment registers. */
abstract class TransactionController extends Controller
{
    protected function listPeriod(Request $request): Period
    {
        return $this->period($request, 'all');
    }

    /** Visibility, period, status and search filters common to every register. */
    protected function baseFilters(Builder $q, Request $request, Period $period, array $searchFields): Builder
    {
        $q->visibleTo($request->user())->between($period->startDate(), $period->endDate());
        if (isset(Income::STATUSES[(string) $request->query('status')])) {
            $q->where($q->getModel()->qualifyColumn('status'), $request->query('status'));
        }

        return $this->search($q, $request, $searchFields);
    }

    protected function vehicleFilters(Builder $q, Request $request): Builder
    {
        if (isset(Vehicle::CATEGORIES[(string) $request->query('vehicle_category')])) {
            $q->where($q->getModel()->qualifyColumn('vehicle_category'), $request->query('vehicle_category'));
        }
        if ($vehicle = $this->idParam($request, 'vehicle')) {
            $q->where($q->getModel()->qualifyColumn('vehicle_id'), $vehicle);
        }

        return $q;
    }

    protected function vehicleChoices(Request $request)
    {
        $q = Vehicle::query()->orderBy('category')->orderBy('registration_number');
        if (isset(Vehicle::CATEGORIES[(string) $request->query('vehicle_category')])) {
            $q->where('category', $request->query('vehicle_category'));
        }

        return $q->get();
    }

    /** Usable vehicles of a category (plus the record's current vehicle). */
    protected function vehiclesFor(?string $category, ?int $currentId = null)
    {
        if (! $category || ! isset(Vehicle::CATEGORIES[$category])) {
            return collect();
        }

        return Vehicle::where('category', $category)
            ->where(fn ($q) => $q->where('status', '!=', 'INACTIVE')->when($currentId, fn ($w) => $w->orWhere('id', $currentId)))
            ->orderBy('registration_number')->get();
    }

    protected function activeCustomers(?int $currentId = null)
    {
        return Customer::where('is_active', true)->when($currentId, fn ($q) => $q->orWhere('id', $currentId))->orderBy('name')->get();
    }

    protected function activeDrivers(?int $currentId = null)
    {
        return Driver::where('status', 'ACTIVE')->when($currentId, fn ($q) => $q->orWhere('id', $currentId))->orderBy('name')->get();
    }

    /** Find a record the user may see (Entry Users: only their own). */
    protected function findVisible(string $model, int $id, Request $request): Model
    {
        return $model::visibleTo($request->user())->findOrFail($id);
    }

    protected function findEditable(string $model, int $id, Request $request): Model
    {
        $obj = $this->findVisible($model, $id, $request);
        abort_unless($obj->canEdit($request->user()), 403, 'You can edit only your own entries, and only until they are approved. Ask an administrator to change this one.');

        return $obj;
    }

    /** Save a new transaction with workflow status and an audit entry. */
    protected function createTransaction(Model $obj, Request $request, ?callable $after = null): RedirectResponse
    {
        DB::transaction(function () use ($obj, $request, $after) {
            Workflow::stampNew($obj, $request->user());
            $obj->save();
            if ($after) {
                $after($obj);
            }
            Audit::created($obj);
        });
        $message = $obj->status === 'PENDING' ? "{$obj->number} saved and sent for admin approval." : "{$obj->number} saved.";
        $to = $request->boolean('save_and_add') ? $request->url() : $obj->url();

        return redirect($to)->with('success', $message);
    }

    protected function updateTransaction(Model $obj, array $before, Request $request): RedirectResponse
    {
        DB::transaction(function () use ($obj, $before, $request) {
            Workflow::stampEdit($obj, $request->user());
            $obj->save();
            Audit::updated($obj, $before);
        });

        return redirect($obj->url())->with('success', "{$obj->number} updated.");
    }

    protected function prefill(Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
