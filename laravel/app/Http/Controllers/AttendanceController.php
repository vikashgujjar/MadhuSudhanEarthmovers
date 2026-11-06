<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Reports\ReportTable;
use App\Services\Audit;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Daily driver attendance sheet and the monthly summary. */
class AttendanceController extends Controller
{
    /** Active drivers who had joined by that date, plus anyone already marked that day. */
    private function driversFor(string $date): Collection
    {
        return Driver::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $a) => $a->where('status', 'ACTIVE')
                    ->where(fn (Builder $j) => $j->whereNull('joining_date')->orWhere('joining_date', '<=', $date)))
                ->orWhereHas('attendances', fn (Builder $r) => $r->where('date', $date)))
            ->with('assignedVehicle')->orderBy('name')->get();
    }

    private function dateFrom(Request $request): CarbonImmutable
    {
        $today = now()->toImmutable()->startOfDay();
        $date = Period::parseDate($request->query('date'));

        return $date && $date->lte($today) ? $date : $today;
    }

    public function index(Request $request)
    {
        $date = $this->dateFrom($request);
        $drivers = $this->driversFor($date->toDateString());
        $records = DriverAttendance::where('date', $date->toDateString())->get()->keyBy('driver_id');

        $export = $this->export($request, fn () => new ReportTable(
            title: 'Driver Attendance',
            columns: [['Driver', 'name', 'text'], ['Status', 'status', 'text'], ['Punch in', 'in', 'text'], ['Punch out', 'out', 'text'],
                ['Worked', 'worked', 'text'], ['Notes', 'notes', 'text']],
            rows: $drivers->map(function (Driver $d) use ($records) {
                $r = $records->get($d->id);

                return ['name' => $d->name, 'status' => $r?->statusLabel() ?? 'Not marked', 'in' => DriverAttendance::timeLabel($r?->punch_in),
                    'out' => DriverAttendance::timeLabel($r?->punch_out), 'worked' => $r?->workedLabel() ?? '', 'notes' => $r?->notes];
            })->all(),
            filters: [['Date', $date->format('d M Y')]],
        ));
        if ($export) {
            return $export;
        }

        return view('attendance.day', ['date' => $date, 'drivers' => $drivers, 'records' => $records,
            'counts' => DriverAttendance::summarise($records), 'unmarked' => $drivers->count() - $records->count()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows' => ['nullable', 'array'],
        ], ['date.before_or_equal' => 'Attendance cannot be marked for a future date.']);
        $date = $data['date'];
        $rows = [];
        $errors = [];
        foreach ($this->driversFor($date) as $driver) {
            if (is_array($data['rows'][$driver->id] ?? null)) {
                $rows[$driver->id] = $this->cleanRow($data['rows'][$driver->id], $driver, $errors);
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors)->redirectTo(route('attendance.index', ['date' => $date]));
        }

        $changed = DB::transaction(function () use ($rows, $date, $request) {
            $changed = 0;
            foreach ($rows as $driverId => $row) {
                $record = DriverAttendance::where('driver_id', $driverId)->where('date', $date)->lockForUpdate()->first();
                if ($row['status'] === null) {
                    if ($record) {
                        Audit::deleted($record);
                        $record->delete();
                        $changed++;
                    }
                } elseif (! $record) {
                    $record = new DriverAttendance($row + ['driver_id' => $driverId, 'date' => $date]);
                    $record->created_by = $request->user()->id;
                    $record->save();
                    Audit::created($record);
                    $changed++;
                } else {
                    $before = Audit::snapshot($record);
                    $record->fill($row);
                    if ($record->isDirty()) {
                        $record->save();
                        Audit::updated($record, $before);
                        $changed++;
                    }
                }
            }

            return $changed;
        });

        $label = CarbonImmutable::parse($date)->format('d M Y');

        return redirect()->route('attendance.index', ['date' => $date])
            ->with('success', $changed ? "Attendance saved for {$label}: {$changed} driver".plural($changed).' updated.' : "No changes for {$label}.");
    }

    /** Normalise one submitted row, adding any problems to $errors. */
    private function cleanRow(array $row, Driver $driver, array &$errors): array
    {
        $text = fn (string $key) => is_string($row[$key] ?? null) ? trim($row[$key]) : '';
        $status = $text('status');
        $in = $text('punch_in');
        $out = $text('punch_out');
        $notes = $text('notes');
        $key = "rows.{$driver->id}";
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';

        if ($status !== '' && ! isset(DriverAttendance::STATUSES[$status])) {
            $errors["{$key}.status"] = "{$driver->name}: select a valid status.";
        }
        $inValid = $in === '' || preg_match($time, $in);
        $outValid = $out === '' || preg_match($time, $out);
        if (! $inValid) {
            $errors["{$key}.punch_in"] = "{$driver->name}: enter a valid punch in time.";
        }
        if (! $outValid) {
            $errors["{$key}.punch_out"] = "{$driver->name}: enter a valid punch out time.";
        }
        if (mb_strlen($notes) > 255) {
            $errors["{$key}.notes"] = "{$driver->name}: notes can be at most 255 characters.";
        }
        // Entering a time means the driver came to work.
        if ($status === '' && ($in !== '' || $out !== '')) {
            $status = 'PRESENT';
        }
        if ($status === '' && $notes !== '') {
            $errors["{$key}.status"] = "{$driver->name}: select a status for the note.";
        }
        if (in_array($status, DriverAttendance::NOT_WORKING, true) && ($in !== '' || $out !== '')) {
            $errors["{$key}.status"] = "{$driver->name}: remove the punch times, or change the status from ".DriverAttendance::STATUSES[$status].'.';
        }
        if ($out !== '' && $in === '') {
            $errors["{$key}.punch_in"] = "{$driver->name}: enter the punch in time as well.";
        } elseif ($inValid && $outValid && $in !== '' && $out !== '' && strcmp($out, $in) <= 0) {
            $errors["{$key}.punch_out"] = "{$driver->name}: punch out must be later than punch in.";
        }

        return [
            'status' => $status === '' ? null : $status,
            'punch_in' => $in === '' ? null : "{$in}:00",
            'punch_out' => $out === '' ? null : "{$out}:00",
            'notes' => $notes === '' ? null : $notes,
        ];
    }

    public function monthly(Request $request)
    {
        $month = DriverAttendance::monthFrom($request->query('month'));
        $start = $month->toDateString();
        $end = $month->endOfMonth()->toDateString();
        $today = now()->toDateString();

        $records = DriverAttendance::whereBetween('date', [$start, $end])->get()->groupBy('driver_id');
        $rows = Driver::where('status', 'ACTIVE')->orWhereIn('id', $records->keys())->orderBy('name')->get()
            ->map(function (Driver $d) use ($records, $start, $end, $today) {
                $own = $records->get($d->id, collect());
                $from = max($start, $d->joining_date?->toDateString() ?? $start);
                $to = min($end, $today);
                $days = $from <= $to ? (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 : 0;
                $marked = $own->filter(fn ($r) => $r->date->toDateString() >= $from && $r->date->toDateString() <= $to)->count();

                return ['driver' => $d, 'counts' => DriverAttendance::summarise($own),
                    'unmarked' => $d->status === 'ACTIVE' ? max(0, $days - $marked) : null];
            });

        $export = $this->export($request, fn () => new ReportTable(
            title: 'Driver Attendance Summary',
            columns: [['Driver', 'name', 'text'], ['Present', 'present', 'number'], ['Half day', 'half', 'number'], ['Absent', 'absent', 'number'],
                ['On leave', 'leave', 'number'], ['Not marked', 'unmarked', 'number'], ['Worked', 'worked', 'text']],
            rows: $rows->map(fn ($r) => ['name' => $r['driver']->name, 'present' => $r['counts']['PRESENT'], 'half' => $r['counts']['HALF_DAY'],
                'absent' => $r['counts']['ABSENT'], 'leave' => $r['counts']['LEAVE'], 'unmarked' => $r['unmarked'],
                'worked' => DriverAttendance::durationLabel($r['counts']['minutes'])])->all(),
            filters: [['Month', $month->format('F Y')]],
        ));
        if ($export) {
            return $export;
        }

        return view('attendance.monthly', ['month' => $month, 'rows' => $rows]);
    }
}
