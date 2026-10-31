<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Support\PasswordRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $q = User::query();
        $this->search($q, $request, ['username', 'first_name', 'last_name', 'email', 'phone']);
        if (array_key_exists((string) $request->query('role'), User::ROLES)) {
            $q->where('role', $request->query('role'));
        }
        if ($request->query('status') === 'active') {
            $q->where('is_active', true);
        } elseif ($request->query('status') === 'inactive') {
            $q->where('is_active', false);
        }
        $users = $q->orderByDesc('is_active')->orderBy('role')->orderBy('first_name')->orderBy('username')
            ->paginate($this->perPage())->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.form', ['target' => new User(['role' => 'ENTRY'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + ['password' => PasswordRules::rules($request->input('username'))]);
        $user = new User;
        $this->fill($user, $data, $request);
        $user->password = $data['password'];
        $user->save();
        Audit::created($user);

        return redirect()->route('users.index')->with('success', "User '{$user->username}' created.");
    }

    public function edit(User $user)
    {
        return view('users.form', ['target' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate($this->rules($user));
        $role = $data['role'];
        $active = $request->boolean('is_active');
        $errors = [];
        $demoting = $user->role === 'ADMIN' && ($role !== 'ADMIN' || ! $active);
        if ($demoting && ! User::activeAdmins()->whereKeyNot($user->id)->exists()) {
            $errors['role'] = 'At least one active administrator is required.';
        }
        if ($user->id === $request->user()->id) {
            if (! $active) {
                $errors['is_active'] = 'You cannot deactivate your own account.';
            }
            if ($role !== 'ADMIN' && $user->role === 'ADMIN') {
                $errors['role'] = 'You cannot remove your own administrator role.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $before = Audit::snapshot($user);
        $this->fill($user, $data, $request);
        $user->is_active = $active;
        $user->save();
        Audit::updated($user, $before);

        return redirect()->route('users.index')->with('success', "User '{$user->username}' updated.");
    }

    public function passwordForm(User $user)
    {
        return view('users.password', ['target' => $user]);
    }

    public function setPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => PasswordRules::rules($user->username)]);
        $user->password = $data['password'];
        $user->save();
        if ($user->id === $request->user()->id) {
            $request->session()->regenerate();
        }
        Audit::log('UPDATE', $user, changes: ['note' => 'Password reset by administrator']);

        return redirect()->route('users.index')->with('success', "Password for '{$user->username}' has been reset.");
    }

    public function toggleActive(Request $request, User $user)
    {
        $back = $this->safeNext($request, route('users.index'));
        if ($user->id === $request->user()->id) {
            return redirect($back)->with('error', 'You cannot deactivate your own account.');
        }
        if ($user->is_active && $user->role === 'ADMIN' && ! User::activeAdmins()->whereKeyNot($user->id)->exists()) {
            return redirect($back)->with('error', 'At least one active administrator is required.');
        }
        $before = Audit::snapshot($user);
        $user->is_active = ! $user->is_active;
        $user->save();
        Audit::updated($user, $before);

        return redirect($back)->with('success', "'{$user->username}' ".($user->is_active ? 'activated' : 'deactivated').'.');
    }

    private function rules(?User $user = null): array
    {
        $rules = [
            'username' => ['required', 'string', 'max:150', 'regex:/^[\w.@+-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
        ];

        return $rules;
    }

    /** Role rules: Viewers can never write; Admins have everything. */
    private function fill(User $user, array $data, Request $request): void
    {
        $user->fill(array_intersect_key($data, array_flip(['username', 'first_name', 'last_name', 'email', 'phone', 'role'])));
        foreach (array_keys(User::CAPABILITIES) as $name) {
            $flag = $request->boolean("can_{$name}");
            if ($data['role'] === 'VIEWER' && in_array($name, User::WRITE_CAPABILITIES, true)) {
                $flag = false;
            }
            if ($data['role'] === 'ADMIN') {
                $flag = true;
            }
            $user->setAttribute("can_{$name}", $flag);
        }
    }
}
