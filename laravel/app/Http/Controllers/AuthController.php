<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ], ['username.required' => 'Enter your username.', 'password.required' => 'Enter your password.']);
        $username = trim($data['username']);

        if ($this->isLockedOut($username)) {
            throw ValidationException::withMessages([
                'login' => 'Too many failed attempts. Try again in '.config('erp.lockout_minutes').' minutes.',
            ]);
        }

        $user = User::where('username', $username)->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            Audit::log('LOGIN_FAILED', objectRepr: $username, modelName: 'Session', username: $username);
            throw ValidationException::withMessages(['login' => 'Incorrect username or password. Passwords are case-sensitive.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This account has been deactivated. Contact your administrator.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        Audit::log('LOGIN', user: $user, objectRepr: $user->username, modelName: 'Session');

        return redirect()->intended(route('dashboard'));
    }

    /** 5 failures for a username within 15 minutes (since its last successful login) lock it. */
    private function isLockedOut(string $username): bool
    {
        $since = now()->subMinutes(config('erp.lockout_minutes'));
        $failures = AuditLog::where('action', 'LOGIN_FAILED')->where('username', $username)->where('timestamp', '>=', $since);
        $lastOk = AuditLog::where('action', 'LOGIN')->where('username', $username)->where('timestamp', '>=', $since)
            ->orderByDesc('timestamp')->orderByDesc('id')->first();
        if ($lastOk) {
            $failures->where('id', '>', $lastOk->id);
        }

        return $failures->count() >= config('erp.lockout_attempts');
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        Audit::log('LOGOUT', user: $user, objectRepr: $user->username, modelName: 'Session');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showPassword()
    {
        return view('auth.password');
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'old_password' => ['required', 'current_password'],
            'password' => PasswordRules::rules($user->username),
        ], ['old_password.current_password' => 'Your old password was entered incorrectly.']);
        $user->password = $data['password'];
        $user->save();
        $request->session()->regenerate();
        Audit::log('UPDATE', $user, changes: ['note' => 'Changed own password']);

        return redirect()->route('dashboard')->with('success', 'Your password has been changed.');
    }
}
