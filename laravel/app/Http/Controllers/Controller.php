<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Reports\Exporter;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    public const MONEY = ['numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'];

    protected function user(): User
    {
        return auth()->user();
    }

    protected function business(): BusinessSetting
    {
        return BusinessSetting::current();
    }

    protected function period(Request $request, string $default = 'this_fy'): Period
    {
        return Period::resolve($request, $this->business()->financial_year_start_month, $default);
    }

    protected function perPage(): int
    {
        return (int) config('erp.page_size', 25);
    }

    /**
     * `?q=` search over columns; "relation.column" searches a related table.
     */
    protected function search(Builder $q, Request $request, array $fields): Builder
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '' || ! $fields) {
            return $q;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $q->where(function (Builder $w) use ($fields, $like) {
            foreach ($fields as $field) {
                if (str_contains($field, '.')) {
                    [$relation, $column] = explode('.', $field, 2);
                    $w->orWhereHas($relation, fn (Builder $r) => $r->where($column, 'like', $like));
                } else {
                    $w->orWhere($w->getModel()->qualifyColumn($field), 'like', $like);
                }
            }
        });
    }

    /** Throw a validation error for business-rule failures (keys like "vehicle_id" map to input "vehicle"). */
    protected function failIf(array $errors): void
    {
        if (! $errors) {
            return;
        }
        $mapped = [];
        foreach ($errors as $key => $message) {
            $mapped[str_ends_with($key, '_id') ? substr($key, 0, -3) : $key] = $message;
        }
        throw ValidationException::withMessages($mapped);
    }

    /** Return an export download when `?export=` is present, otherwise null. */
    protected function export(Request $request, callable $buildTable): ?\Symfony\Component\HttpFoundation\Response
    {
        $format = $request->query('export');
        if (in_array($format, Exporter::FORMATS, true)) {
            /** @var ReportTable $table */
            $table = $buildTable();

            return Exporter::respond($table, $format);
        }

        return null;
    }

    /** Delete with an audit copy; both commit together or not at all. */
    protected function auditedDelete(Model $model, string $label, string $successRoute, string $protectedMessage, string $backUrl): RedirectResponse
    {
        try {
            DB::transaction(function () use ($model) {
                Audit::deleted($model);
                $model->delete();
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[1] ?? null, [1451, 1217], true)) { // foreign key restricts the delete
                return redirect($backUrl)->with('error', $protectedMessage);
            }
            throw $e;
        }

        return redirect()->route($successRoute)->with('success', $label);
    }

    /** Only same-site relative redirects. */
    protected function safeNext(Request $request, string $fallback): string
    {
        $next = (string) $request->input('next', '');

        return str_starts_with($next, '/') && ! str_starts_with($next, '//') ? $next : $fallback;
    }

    /** Ids from a query string value ("" or non-digits ignored). */
    protected function idParam(Request $request, string $key): ?int
    {
        $v = $request->query($key);

        return is_string($v) && ctype_digit($v) ? (int) $v : null;
    }

    protected function filterSummary(Request $request, Period $period, array $statuses): array
    {
        $parts = [['Period', $period->label]];
        $status = $request->query('status');
        $parts[] = ['Status', is_string($status) && isset($statuses[$status]) ? $statuses[$status] : 'All statuses'];
        if ($request->filled('q')) {
            $parts[] = ['Search', (string) $request->query('q')];
        }

        return $parts;
    }
}
