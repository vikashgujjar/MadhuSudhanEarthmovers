<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverAttendance;
use App\Services\Finance;
use Tests\TestCase;

class DriverAttendanceTest extends TestCase
{
    private const DAY = '2026-05-04';

    private function mark(array $row, string $date = self::DAY)
    {
        return $this->post(route('attendance.index'), ['date' => $date, 'rows' => [$this->driver->id => $row + ['status' => '', 'punch_in' => '', 'punch_out' => '', 'notes' => '']]]);
    }

    public function test_marks_attendance_with_punch_times_and_audit(): void
    {
        $this->login($this->admin);
        $this->mark(['status' => 'PRESENT', 'punch_in' => '08:30', 'punch_out' => '17:00', 'notes' => 'Site A'])
            ->assertRedirect(route('attendance.index', ['date' => self::DAY]))->assertSessionHas('success');

        $r = DriverAttendance::sole();
        $this->assertSame(['PRESENT', '08:30:00', '17:00:00', 510], [$r->status, $r->punch_in, $r->punch_out, $r->workedMinutes()]);
        $this->assertSame('8 hr 30 min', $r->workedLabel());
        $this->assertSame($this->admin->id, (int) $r->created_by);
        $this->assertSame(1, AuditLog::where('model_name', 'Driver attendance')->where('action', 'CREATE')->count());

        $this->get(route('attendance.index', ['date' => self::DAY]))->assertOk()->assertSee('08:30')->assertSee('8 hr 30 min')->assertSee('Site A');
        $this->get(route('drivers.show', ['driver' => $this->driver, 'month' => '2026-05']))->assertOk()->assertSee('04 May 2026')->assertSee('08:30 AM')->assertSee('05:00 PM');
    }

    public function test_saving_again_updates_the_same_record_and_logs_only_real_changes(): void
    {
        $this->login($this->admin);
        $row = ['status' => 'PRESENT', 'punch_in' => '08:30'];
        $this->mark($row);
        $this->mark($row);
        $this->assertSame(0, AuditLog::where('model_name', 'Driver attendance')->where('action', 'UPDATE')->count());

        $this->mark($row + ['punch_out' => '12:30']);
        $this->assertSame('12:30:00', DriverAttendance::sole()->punch_out);
        $this->assertSame(1, AuditLog::where('model_name', 'Driver attendance')->where('action', 'UPDATE')->count());

        $this->mark([]);
        $this->assertSame(0, DriverAttendance::count());
        $this->assertSame(1, AuditLog::where('model_name', 'Driver attendance')->where('action', 'DELETE')->count());
    }

    public function test_time_without_status_means_present(): void
    {
        $this->login($this->admin);
        $this->mark(['punch_in' => '09:00']);
        $this->assertSame('PRESENT', DriverAttendance::sole()->status);
        $this->assertNull(DriverAttendance::sole()->workedMinutes());
    }

    public function test_validation(): void
    {
        $this->login($this->admin);
        $key = "rows.{$this->driver->id}";
        $this->mark(['status' => 'PRESENT', 'punch_in' => '17:00', 'punch_out' => '08:00'])->assertSessionHasErrors("{$key}.punch_out");
        $this->mark(['status' => 'PRESENT', 'punch_in' => '08:00', 'punch_out' => '08:00'])->assertSessionHasErrors("{$key}.punch_out");
        $this->mark(['status' => 'PRESENT', 'punch_out' => '17:00'])->assertSessionHasErrors("{$key}.punch_in");
        $this->mark(['status' => 'PRESENT', 'punch_in' => '25:00'])->assertSessionHasErrors("{$key}.punch_in");
        $this->mark(['status' => 'ABSENT', 'punch_in' => '08:00'])->assertSessionHasErrors("{$key}.status");
        $this->mark(['status' => 'NOPE'])->assertSessionHasErrors("{$key}.status");
        $this->mark(['notes' => 'x'])->assertSessionHasErrors("{$key}.status");
        $this->mark(['status' => 'PRESENT'], now()->addDay()->toDateString())->assertSessionHasErrors('date');
        $this->assertSame(0, DriverAttendance::count());
    }

    public function test_one_bad_row_saves_nothing(): void
    {
        $other = Driver::create(['name' => 'Suresh']);
        $this->login($this->admin)->post(route('attendance.index'), ['date' => self::DAY, 'rows' => [
            $this->driver->id => ['status' => 'PRESENT'],
            $other->id => ['status' => 'PRESENT', 'punch_in' => '17:00', 'punch_out' => '08:00'],
        ]])->assertSessionHasErrors();
        $this->assertSame(0, DriverAttendance::count());
    }

    public function test_sheet_lists_only_drivers_employed_that_day(): void
    {
        Driver::create(['name' => 'Future Joiner', 'joining_date' => '2026-06-01']);
        $gone = Driver::create(['name' => 'Left Driver', 'status' => 'INACTIVE']);
        $this->login($this->admin);
        $this->get(route('attendance.index', ['date' => self::DAY]))->assertSee('Ramesh')->assertDontSee('Future Joiner')->assertDontSee('Left Driver');

        // An inactive driver stays visible on a day already marked.
        DriverAttendance::create(['driver_id' => $gone->id, 'date' => self::DAY, 'status' => 'ABSENT']);
        $this->get(route('attendance.index', ['date' => self::DAY]))->assertSee('Left Driver');
    }

    public function test_monthly_summary_and_exports(): void
    {
        $this->login($this->admin);
        $this->mark(['status' => 'PRESENT', 'punch_in' => '08:00', 'punch_out' => '16:30'], '2026-05-04');
        $this->mark(['status' => 'PRESENT', 'punch_in' => '08:00', 'punch_out' => '10:15'], '2026-05-05');
        $this->mark(['status' => 'HALF_DAY'], '2026-05-06');
        $this->mark(['status' => 'ABSENT'], '2026-05-07');
        $this->mark(['status' => 'LEAVE'], '2026-05-08');

        $month = ['month' => '2026-05'];
        // 31 days in May, 5 marked: 26 not marked.
        $this->get(route('attendance.monthly', $month))->assertOk()->assertSee('May 2026')->assertSee('10 hr 45 min')->assertSee('>26<', false);
        $csv = $this->get(route('attendance.monthly', $month + ['export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('10 hr 45 min', $csv);
        $this->get(route('attendance.monthly', $month + ['export' => 'pdf']))->assertOk();
        $this->get(route('attendance.monthly', $month + ['export' => 'xlsx']))->assertOk();
        $day = $this->get(route('attendance.index', ['date' => '2026-05-04', 'export' => 'csv']))->assertOk()->getContent();
        $this->assertStringContainsString('08:00 AM', $day);
        $this->get(route('attendance.monthly', ['month' => 'garbage']))->assertOk()->assertSee(now()->format('F Y'));
    }

    public function test_marking_needs_the_permission_but_everyone_can_view(): void
    {
        foreach ([$this->entry, $this->viewer] as $user) {
            $this->login($user)->get(route('attendance.index'))->assertOk()->assertDontSee('Save attendance');
            $this->mark(['status' => 'PRESENT'])->assertForbidden();
        }
        $this->assertSame(0, DriverAttendance::count());

        $this->entry->update(['can_mark_attendance' => true]);
        $this->login($this->entry->fresh())->get(route('attendance.index'))->assertSee('Save attendance');
        $this->mark(['status' => 'PRESENT'])->assertRedirect();
        $this->assertSame(1, DriverAttendance::count());

        $this->viewer->update(['can_mark_attendance' => true]);
        $this->assertFalse($this->viewer->fresh()->hasCapability('mark_attendance'));
    }

    public function test_attendance_does_not_change_business_figures(): void
    {
        $this->expense('500');
        $summary = Finance::financialSummary();
        $this->login($this->admin);
        $this->mark(['status' => 'PRESENT', 'punch_in' => '08:00', 'punch_out' => '17:00']);
        $this->assertSame($summary, Finance::financialSummary());
    }

    public function test_driver_with_attendance_cannot_be_deleted(): void
    {
        $this->login($this->admin);
        $this->mark(['status' => 'PRESENT']);
        $this->post(route('drivers.delete', $this->driver))->assertSessionHas('error');
        $this->assertNotNull($this->driver->fresh());
    }

    public function test_demo_remove_clears_attendance(): void
    {
        DriverAttendance::create(['driver_id' => $this->driver->id, 'date' => self::DAY, 'status' => 'PRESENT']);
        $this->artisan('erp:demo-data --remove')->expectsConfirmation(
            'Delete ALL vehicles, drivers, attendance, customers, income, expenses, payments and loans in '.config('database.connections.mysql.database').'?', 'yes'
        )->assertSuccessful();
        $this->assertSame(0, DriverAttendance::count());
        $this->assertSame(0, Driver::count());
    }
}
