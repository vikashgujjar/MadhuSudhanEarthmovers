<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/** The one-time web installer used on hosting without SSH. */
class InstallerTest extends TestCase
{
    private function freshSite(): void
    {
        User::query()->delete();
        config(['erp.install_key' => 'secret-key-123']);
    }

    private function form(array $extra = []): array
    {
        return array_merge([
            'install_key' => 'secret-key-123', 'business_name' => 'Madhusudhan Earthmover', 'first_name' => 'Owner',
            'username' => 'owner', 'password' => 'Good#Pass2026', 'password_confirmation' => 'Good#Pass2026',
        ], $extra);
    }

    public function test_installer_creates_first_admin_with_correct_key(): void
    {
        $this->freshSite();
        $this->get(route('install'))->assertOk()->assertSee('Install the ERP');
        $this->post(route('install.run'), $this->form())->assertRedirect(route('settings'));
        $owner = User::where('username', 'owner')->sole();
        $this->assertTrue($owner->isAdmin());
        $this->assertTrue($owner->hasCapability('export_reports'));
        $this->assertAuthenticatedAs($owner);
        $this->get(route('install'))->assertNotFound();
    }

    public function test_wrong_key_is_refused(): void
    {
        $this->freshSite();
        $this->post(route('install.run'), $this->form(['install_key' => 'guess']))->assertSessionHasErrors('install_key');
        $this->assertSame(0, User::count());
    }

    public function test_installer_disabled_without_configured_key(): void
    {
        $this->freshSite();
        config(['erp.install_key' => '']);
        $this->post(route('install.run'), $this->form(['install_key' => '']))->assertSessionHasErrors('install_key');
        $this->assertSame(0, User::count());
    }
}
