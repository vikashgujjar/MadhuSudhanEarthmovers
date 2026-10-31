<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\Audit;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time web installer for hosting without SSH: creates the tables and the
 * first administrator. It switches itself off once an administrator exists.
 */
class InstallController extends Controller
{
    private function state(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            return ['db' => false, 'error' => $e->getMessage(), 'installed' => false];
        }
        $installed = Schema::hasTable('users') && User::where('role', 'ADMIN')->exists();

        return ['db' => true, 'error' => null, 'installed' => $installed];
    }

    public function show()
    {
        $state = $this->state();
        abort_if($state['installed'], 404);

        return view('install', ['state' => $state, 'checks' => $this->checks()]);
    }

    public function run(Request $request)
    {
        $state = $this->state();
        abort_if($state['installed'], 404);
        if (! $state['db']) {
            return back()->with('error', 'Cannot connect to the database. Check DB_* settings in the .env file.');
        }
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'first_name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:150', 'regex:/^[\w.@+-]+$/'],
            'password' => PasswordRules::rules($request->input('username')),
            'install_key' => ['required', 'string'],
        ]);
        $expected = (string) config('erp.install_key');
        if ($expected === '' || ! hash_equals($expected, trim($data['install_key']))) {
            return back()->withInput($request->except('password', 'password_confirmation', 'install_key'))
                ->withErrors(['install_key' => 'The installation key does not match ERP_INSTALL_KEY in the .env file.']);
        }

        Artisan::call('migrate', ['--force' => true]);
        BusinessSetting::forgetCache();
        $settings = BusinessSetting::current();
        $settings->business_name = $data['business_name'];
        $settings->erp_title = $data['business_name'].' ERP';
        $settings->save();

        $admin = User::where('username', $data['username'])->first() ?? new User;
        $admin->fill(['username' => $data['username'], 'first_name' => $data['first_name'], 'role' => 'ADMIN', 'is_active' => true]);
        foreach (User::capabilityFields() as $field) {
            $admin->setAttribute($field, true);
        }
        $admin->password = $data['password'];
        $admin->save();
        Audit::log('CREATE', $admin, $admin, ['note' => 'Created by the installer']);

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('settings')->with('success', 'Installation complete. Fill in your address, phone and GSTIN below.');
    }

    /** Server requirements, shown on the installer page. */
    private function checks(): array
    {
        $writable = fn ($path) => is_writable($path);

        return [
            ['PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['PDO MySQL extension', extension_loaded('pdo_mysql')],
            ['mbstring extension', extension_loaded('mbstring')],
            ['GD extension (PDF/Excel images)', extension_loaded('gd')],
            ['Zip extension (Excel export)', extension_loaded('zip')],
            ['storage/ folder writable', $writable(storage_path())],
            ['bootstrap/cache/ folder writable', $writable(base_path('bootstrap/cache'))],
        ];
    }
}
