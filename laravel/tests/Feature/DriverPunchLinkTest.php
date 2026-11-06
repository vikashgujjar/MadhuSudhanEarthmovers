<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverAttendance;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DriverPunchLinkTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function link(?Driver $driver = null): string
    {
        $driver ??= $this->driver;
        $this->login($this->admin)->post(route('drivers.punch-link', $driver))->assertRedirect(route('drivers.show', $driver));
        $this->app['auth']->forgetGuards();

        return (string) $driver->fresh()->attendance_token;
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-05-04 {$time}"));
    }

    public function test_driver_punches_in_and_out_without_logging_in(): void
    {
        $token = $this->link();
        $this->assertSame(40, strlen($token));
        $this->assertGuest();

        $this->at('08:31:40');
        $this->get(route('driver.punch', $token))->assertOk()->assertSee('Ramesh')->assertSee('Punch In')->assertDontSee('Punch Out');
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertRedirect(route('driver.punch', $token))->assertSessionHas('success');

        $r = DriverAttendance::sole();
        $this->assertSame(['2026-05-04', 'PRESENT', '08:31:00', null, true, null],
            [$r->date->toDateString(), $r->status, $r->punch_in, $r->punch_out, $r->self_marked, $r->created_by]);
        $this->get(route('driver.punch', $token))->assertSee('08:31 AM')->assertSee('Punch Out');

        $this->at('17:01:05');
        $this->post(route('driver.punch', $token), ['action' => 'out'])->assertSessionHas('success');
        $this->assertSame('17:01:00', DriverAttendance::sole()->punch_out);
        $this->get(route('driver.punch', $token))->assertSee('8 hr 30 min')->assertDontSee('Punch In')->assertDontSee('Punch Out<');

        $names = AuditLog::where('model_name', 'Driver attendance')->pluck('username')->unique()->all();
        $this->assertSame(['Ramesh (driver link)'], $names);
    }

    public function test_client_cannot_choose_time_or_date(): void
    {
        $token = $this->link();
        $this->at('09:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'in', 'punch_in' => '06:00', 'date' => '2026-01-01', 'status' => 'LEAVE', 'driver_id' => 999]);
        $r = DriverAttendance::sole();
        $this->assertSame(['2026-05-04', '09:00:00', 'PRESENT', $this->driver->id], [$r->date->toDateString(), $r->punch_in, $r->status, (int) $r->driver_id]);
    }

    public function test_repeat_and_out_of_order_punches_change_nothing(): void
    {
        $token = $this->link();
        $this->at('08:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'out'])->assertSessionHas('error', 'Please punch in first.');
        $this->assertSame(0, DriverAttendance::count());

        $this->post(route('driver.punch', $token), ['action' => 'in']);
        $this->post(route('driver.punch', $token), ['action' => 'out'])->assertSessionHas('error'); // same minute
        $this->at('08:05:00');
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertSessionHas('error', 'You already punched in at 08:00 AM.');
        $this->assertSame('08:00:00', DriverAttendance::sole()->punch_in);

        $this->at('12:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'out']);
        $this->at('13:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'out'])->assertSessionHas('error', 'You already punched out at 12:00 PM.');
        $this->assertSame('12:00:00', DriverAttendance::sole()->punch_out);
        $this->post(route('driver.punch', $token), ['action' => 'nonsense'])->assertStatus(422);
    }

    public function test_each_day_is_a_new_record(): void
    {
        $token = $this->link();
        $this->at('08:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'in']);
        Carbon::setTestNow(Carbon::parse('2026-05-05 08:10:00'));
        $this->get(route('driver.punch', $token))->assertSee('Punch In')->assertSee('Recent days');
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertSessionHas('success');
        $this->assertSame(2, DriverAttendance::count());
    }

    public function test_office_absent_or_leave_blocks_punch_and_office_present_is_completed(): void
    {
        $token = $this->link();
        $this->at('08:00:00');
        $absent = DriverAttendance::create(['driver_id' => $this->driver->id, 'date' => '2026-05-04', 'status' => 'LEAVE']);
        $this->get(route('driver.punch', $token))->assertSee('On leave')->assertDontSee('Punch In');
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertSessionHas('error');
        $this->assertNull($absent->fresh()->punch_in);

        $absent->update(['status' => 'HALF_DAY']);
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertSessionHas('success');
        $r = DriverAttendance::sole();
        $this->assertSame(['HALF_DAY', '08:00:00'], [$r->status, $r->punch_in]);
    }

    public function test_future_joiner_cannot_punch(): void
    {
        $driver = Driver::create(['name' => 'Future', 'joining_date' => '2026-06-01']);
        $token = $this->link($driver);
        $this->at('08:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertSessionHas('error');
        $this->assertSame(0, DriverAttendance::count());
    }

    public function test_replacing_or_turning_off_link_stops_the_old_one(): void
    {
        $old = $this->link();
        $new = $this->link();
        $this->assertNotSame($old, $new);
        $this->get(route('driver.punch', $old))->assertNotFound();
        $this->post(route('driver.punch', $old), ['action' => 'in'])->assertNotFound();
        $this->get(route('driver.punch', $new))->assertOk();

        $this->login($this->admin)->post(route('drivers.punch-link.revoke', $this->driver))->assertRedirect();
        $this->assertNull($this->driver->fresh()->attendance_token);
        $this->get(route('driver.punch', $new))->assertNotFound();
        $this->assertSame(0, DriverAttendance::count());
    }

    public function test_inactive_driver_and_bad_links_get_not_found(): void
    {
        $token = $this->link();
        $this->driver->update(['status' => 'INACTIVE']);
        $this->get(route('driver.punch', $token))->assertNotFound();
        $this->post(route('driver.punch', $token), ['action' => 'in'])->assertNotFound();
        $this->get('/punch/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/punch/short')->assertNotFound();

        $this->login($this->admin)->post(route('drivers.punch-link', $this->driver))->assertSessionHas('error');
    }

    public function test_page_shows_no_private_or_business_data(): void
    {
        $this->driver->update(['monthly_salary' => '23456', 'notes' => 'Private remark']);
        $token = $this->link();
        $this->get(route('driver.punch', $token))->assertOk()
            ->assertDontSee('23,456')->assertDontSee('Private remark')->assertDontSee('Dashboard')->assertDontSee('nav-link')
            ->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_only_markers_can_see_or_manage_the_link_and_token_stays_out_of_audit(): void
    {
        $token = $this->link();
        $this->login($this->admin)->get(route('drivers.show', $this->driver))->assertSee($token);

        foreach ([$this->entry, $this->viewer] as $user) {
            $this->login($user)->get(route('drivers.show', $this->driver))->assertOk()->assertDontSee($token)->assertDontSee('Attendance link');
            $this->post(route('drivers.punch-link', $this->driver))->assertForbidden();
            $this->post(route('drivers.punch-link.revoke', $this->driver))->assertForbidden();
        }
        $this->assertSame($token, $this->driver->fresh()->attendance_token);

        $this->entry->update(['can_mark_attendance' => true]);
        $this->login($this->entry->fresh())->get(route('drivers.show', $this->driver))->assertSee($token);

        $this->login($this->admin)->post(route('drivers.edit', $this->driver), ['name' => 'Ramesh K', 'monthly_salary' => '1', 'daily_allowance' => '0', 'status' => 'ACTIVE']);
        $this->assertSame($token, $this->driver->fresh()->attendance_token);
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString($token, json_encode($log->changes).$log->object_repr);
        }
        $this->assertTrue(AuditLog::where('model_name', 'Driver')->get()->contains(fn ($l) => ($l->changes['note'] ?? '') === 'Attendance link generated'));
        $this->assertArrayNotHasKey('attendance_token', $this->driver->fresh()->toArray());
    }

    public function test_office_sheet_shows_punches_and_can_correct_them(): void
    {
        $token = $this->link();
        $this->at('08:00:00');
        $this->post(route('driver.punch', $token), ['action' => 'in']);
        $this->login($this->admin)->get(route('attendance.index', ['date' => '2026-05-04']))->assertSee('08:00')->assertSee('via driver link');

        $row = ['status' => 'PRESENT', 'punch_in' => '08:00', 'punch_out' => '', 'notes' => ''];
        $this->post(route('attendance.index'), ['date' => '2026-05-04', 'rows' => [$this->driver->id => $row]]);
        $this->assertSame(1, AuditLog::where('model_name', 'Driver attendance')->count()); // nothing changed, nothing logged

        $this->post(route('attendance.index'), ['date' => '2026-05-04', 'rows' => [$this->driver->id => ['punch_in' => '07:45'] + $row]]);
        $this->assertSame('07:45:00', DriverAttendance::sole()->punch_in);
    }
}
