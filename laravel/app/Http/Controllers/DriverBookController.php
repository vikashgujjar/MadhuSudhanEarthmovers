<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Models\DriverSalaryAdjustment;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Services\DriverLedger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Driver cash book: salary owed, money paid date-wise and the final amount to pay. */
class DriverBookController extends Controller
{
    private const MONTH = 'regex:/^\d{4}-(0[1-9]|1[0-2])$/';

    public function index(Request $request)
    {
        $key = now()->format('Y-m');
        $rows = Driver::where('status', 'ACTIVE')->orWhereNotNull('ledger_start_month')->orderBy('name')->get()
            ->map(function (Driver $d) use ($key) {
                $ledger = DriverLedger::build($d);
                $month = $ledger['months'][$key] ?? null;

                return ['driver' => $d, 'started' => $ledger !== null, 'paid' => $month['out'] ?? 0, 'other' => $month['other'] ?? 0,
                    'pending' => $month['pending'] ?? 0, 'balance' => $ledger['balance'] ?? 0];
            });

        $export = $this->export($request, fn () => new ReportTable(
            title: 'Driver Cash Book',
            columns: [['Driver', 'name', 'text'], ['Monthly salary', 'salary', 'money'], ['Paid this month', 'paid', 'money'],
                ['Other given this month', 'other', 'money'], ['Final to pay', 'balance', 'money'], ['Note', 'note', 'text']],
            rows: $rows->map(fn ($r) => ['name' => $r['driver']->name, 'salary' => $r['driver']->monthly_salary, 'paid' => DriverLedger::money($r['paid']),
                'other' => DriverLedger::money($r['other']), 'balance' => DriverLedger::money($r['balance']), 'note' => $r['started'] ? '' : 'Not set up'])->all(),
            filters: [['As on', now()->format('d M Y')]],
        ));
        if ($export) {
            return $export;
        }

        return view('driver-book.index', ['rows' => $rows,
            'totalToPay' => DriverLedger::money($rows->sum(fn ($r) => max(0, $r['balance']))),
            'paidThisMonth' => DriverLedger::money($rows->sum('paid')),
            'notStarted' => $rows->where('started', false)->count()]);
    }

    public function show(Request $request, Driver $driver)
    {
        $ledger = DriverLedger::build($driver);
        if (! $ledger) {
            return view('driver-book.show', ['driver' => $driver, 'ledger' => null]);
        }
        $wanted = (string) $request->query('month');
        $all = $wanted === 'all';
        $key = isset($ledger['months'][$wanted]) ? $wanted : array_key_last($ledger['months']);
        $entries = $all ? $ledger['entries'] : array_values(array_filter($ledger['entries'], fn ($e) => $e['date']->format('Y-m') === $key));
        $sum = fn (string $field) => array_sum(array_column($entries, $field));
        $opening = $all ? $ledger['opening'] : $ledger['months'][$key]['opening'];
        $label = $all ? 'All dates' : $ledger['months'][$key]['month']->format('F Y');

        $export = $this->export($request, fn () => new ReportTable(
            title: "Driver Cash Book: {$driver->name}",
            columns: [['Date', 'date', 'date'], ['Details', 'title', 'text'], ['Reference', 'detail', 'text'], ['Mode', 'mode', 'text'],
                ['Salary / added', 'in', 'money'], ['Paid / deducted', 'out', 'money'], ['Other given', 'other', 'money'], ['Balance to pay', 'balance', 'money']],
            rows: array_map(fn ($e) => ['date' => $e['date'], 'title' => $e['title'].($e['kind'] === 'pending' ? ' (pending approval, not counted)' : ''), 'detail' => $e['detail'], 'mode' => $e['mode'],
                'in' => $e['in'] ? DriverLedger::money($e['in']) : null, 'out' => $e['out'] ? DriverLedger::money($e['out']) : null,
                'other' => $e['other'] ? DriverLedger::money($e['other']) : null, 'balance' => DriverLedger::money($e['balance'])], $entries),
            totals: ['in' => DriverLedger::money($sum('in')), 'out' => DriverLedger::money($sum('out')), 'other' => DriverLedger::money($sum('other'))],
            filters: [['Period', $label]],
            summary: [['Opening balance', Money::inr(DriverLedger::money($opening))], ['Final to pay today', Money::inr(DriverLedger::money($ledger['balance']))]],
        ));
        if ($export) {
            return $export;
        }

        return view('driver-book.show', [
            'driver' => $driver, 'ledger' => $ledger, 'entries' => $entries, 'selected' => $all ? 'all' : $key, 'label' => $label, 'periodOpening' => $opening,
            'totals' => ['in' => $sum('in'), 'out' => $sum('out'), 'other' => $sum('other'), 'pending' => $sum('pending')],
            'closing' => $opening + $sum('in') - $sum('out'),
            'presentDays' => DriverAttendance::summarise($driver->attendances()->whereBetween('date', $all
                ? [$driver->ledger_start_month->toDateString(), now()->toDateString()]
                : [$key.'-01', CarbonImmutable::parse($key.'-01')->endOfMonth()->toDateString()])->get()),
        ]);
    }

    public function setup(Request $request, Driver $driver)
    {
        $data = $request->validate([
            'ledger_start_month' => ['required', self::MONTH],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:-999999999999.99', 'max:999999999999.99'],
            'ledger_end_month' => ['nullable', self::MONTH],
        ], [
            'ledger_start_month.required' => 'Select the month this cash book starts from.',
            'ledger_start_month.regex' => 'Select a valid start month.',
            'ledger_end_month.regex' => 'Select a valid last salary month.',
        ]);
        $current = now()->format('Y-m');
        $end = $data['ledger_end_month'] ?? null;
        $this->failIf(array_filter([
            'ledger_start_month' => $data['ledger_start_month'] > $current ? 'The start month cannot be in the future.' : null,
            'ledger_end_month' => $end && $end < $data['ledger_start_month'] ? 'The last salary month cannot be before the start month.' : null,
        ]));

        $before = Audit::snapshot($driver);
        $driver->ledger_start_month = $data['ledger_start_month'].'-01';
        $driver->ledger_end_month = $end ? $end.'-01' : null;
        $driver->opening_balance = $data['opening_balance'];
        $driver->save();
        Audit::updated($driver, $before);

        return redirect()->route('driver-book.show', $driver)->with('success', 'Cash book settings saved.');
    }

    public function storeAdjustment(Request $request, Driver $driver)
    {
        abort_unless((bool) $driver->ledger_start_month, 404);
        $start = $driver->ledger_start_month->toDateString();
        $data = $request->validateWithBag('adjustment', [
            'date' => ['required', 'date_format:Y-m-d', "after_or_equal:{$start}", 'before_or_equal:today'],
            'kind' => ['required', Rule::in(array_keys(DriverSalaryAdjustment::KINDS))],
            'amount' => ['required', ...self::MONEY, 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'date.after_or_equal' => 'The date cannot be before this cash book starts.',
            'date.before_or_equal' => 'The date cannot be in the future.',
            'amount.gt' => 'Enter an amount greater than zero.',
            'reason.required' => 'Enter the reason so it stays on record.',
        ]);

        DB::transaction(function () use ($driver, $data, $request) {
            $adjustment = new DriverSalaryAdjustment($data + ['driver_id' => $driver->id]);
            $adjustment->created_by = $request->user()->id;
            $adjustment->save();
            Audit::created($adjustment);
        });

        return redirect()->route('driver-book.show', ['driver' => $driver, 'month' => substr($data['date'], 0, 7)])->with('success', 'Adjustment saved.');
    }

    public function confirmDeleteAdjustment(Driver $driver, DriverSalaryAdjustment $adjustment)
    {
        abort_unless((int) $adjustment->driver_id === (int) $driver->id, 404);

        return view('partials.confirm-delete', ['label' => (string) $adjustment, 'cancel' => route('driver-book.show', $driver)]);
    }

    public function destroyAdjustment(Driver $driver, DriverSalaryAdjustment $adjustment)
    {
        abort_unless((int) $adjustment->driver_id === (int) $driver->id, 404);
        DB::transaction(function () use ($adjustment) {
            Audit::deleted($adjustment);
            $adjustment->delete();
        });

        return redirect()->route('driver-book.show', $driver)->with('success', 'Adjustment deleted.');
    }
}
