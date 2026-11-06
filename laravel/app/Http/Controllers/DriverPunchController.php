<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attendance through a driver's private link. There is no login: the link is the
 * credential, so times always come from the server and only today can be marked.
 */
class DriverPunchController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer'];

    private function driver(string $token): Driver
    {
        return Driver::where('attendance_token', $token)->where('status', 'ACTIVE')->firstOrFail();
    }

    public function show(string $token)
    {
        $driver = $this->driver($token);
        $today = now()->toDateString();

        return response()->view('attendance.punch', [
            'driver' => $driver,
            'today' => $driver->attendances()->where('date', $today)->first(),
            'recent' => $driver->attendances()->where('date', '<', $today)->orderByDesc('date')->limit(7)->get(),
            'token' => $token,
        ])->withHeaders(self::HEADERS);
    }

    public function store(Request $request, string $token)
    {
        $driver = $this->driver($token);
        $action = $request->input('action');
        abort_unless(in_array($action, ['in', 'out'], true), 422);

        $error = DB::transaction(function () use ($driver, $action) {
            Driver::whereKey($driver->id)->lockForUpdate()->first();
            $record = $driver->attendances()->where('date', now()->toDateString())->first();

            return $action === 'in' ? $this->punchIn($driver, $record) : $this->punchOut($driver, $record);
        });
        $redirect = redirect()->route('driver.punch', $token)->withHeaders(self::HEADERS);

        return $error ? $redirect->with('error', $error) : $redirect->with('success', $action === 'in' ? 'Punch in recorded.' : 'Punch out recorded.');
    }

    /** @return string|null why the punch was refused */
    private function punchIn(Driver $driver, ?DriverAttendance $record): ?string
    {
        if ($driver->joining_date && $driver->joining_date->isFuture()) {
            return 'Your joining date is '.$driver->joining_date->format('d M Y').'. Attendance starts from that day.';
        }
        if ($record && in_array($record->status, DriverAttendance::NOT_WORKING, true)) {
            return 'You are marked '.$record->statusLabel().' today. Please contact the office.';
        }
        if ($record?->punch_in) {
            return 'You already punched in at '.DriverAttendance::timeLabel($record->punch_in).'.';
        }

        if (! $record) {
            $record = new DriverAttendance(['driver_id' => $driver->id, 'date' => now()->toDateString(), 'status' => 'PRESENT']);
            $record->punch_in = $this->currentTime();
            $record->self_marked = true;
            $record->save();
            Audit::log('CREATE', $record, changes: ['snapshot' => Audit::snapshot($record)], username: $this->actor($driver));

            return null;
        }
        $before = Audit::snapshot($record);
        $record->punch_in = $this->currentTime();
        $this->saveChange($record, $before, $driver);

        return null;
    }

    /** @return string|null why the punch was refused */
    private function punchOut(Driver $driver, ?DriverAttendance $record): ?string
    {
        if (! $record?->punch_in) {
            return 'Please punch in first.';
        }
        if ($record->punch_out) {
            return 'You already punched out at '.DriverAttendance::timeLabel($record->punch_out).'.';
        }
        $time = $this->currentTime();
        if (strcmp($time, $record->punch_in) <= 0) {
            return 'You punched in just now. Please punch out after a minute.';
        }
        $before = Audit::snapshot($record);
        $record->punch_out = $time;
        $this->saveChange($record, $before, $driver);

        return null;
    }

    private function saveChange(DriverAttendance $record, array $before, Driver $driver): void
    {
        $record->self_marked = true;
        $record->save();
        Audit::log('UPDATE', $record, changes: ['fields' => Audit::diff($before, Audit::snapshot($record->fresh()))], username: $this->actor($driver));
    }

    /** Whole minutes, the same form the daily sheet saves. */
    private function currentTime(): string
    {
        return now()->format('H:i').':00';
    }

    private function actor(Driver $driver): string
    {
        return "{$driver->name} (driver link)";
    }

    /** Create the driver's link, or replace it so the old one stops working. */
    public function generate(Driver $driver)
    {
        if ($driver->status !== 'ACTIVE') {
            return redirect()->route('drivers.show', $driver)->with('error', 'Only active drivers can have an attendance link.');
        }
        $replaced = (bool) $driver->attendance_token;
        $driver->attendance_token = Str::random(40);
        $driver->save();
        Audit::log('UPDATE', $driver, changes: ['note' => $replaced ? 'Attendance link replaced' : 'Attendance link generated']);

        return redirect()->route('drivers.show', $driver)
            ->with('success', $replaced ? 'New attendance link generated. The old link no longer works.' : 'Attendance link generated.');
    }

    public function revoke(Driver $driver)
    {
        if ($driver->attendance_token) {
            $driver->attendance_token = null;
            $driver->save();
            Audit::log('UPDATE', $driver, changes: ['note' => 'Attendance link turned off']);
        }

        return redirect()->route('drivers.show', $driver)->with('success', 'Attendance link turned off.');
    }
}
