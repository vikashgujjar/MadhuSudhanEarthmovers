<?php

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\Artisan;

// php artisan erp:create-admin admin "Strong-Pass1" --name="Owner"
Artisan::command('erp:create-admin {username} {password} {--name=Administrator}', function (string $username, string $password) {
    $this->info('Organisation: '.BusinessSetting::current()->business_name);
    if (User::where('username', $username)->exists()) {
        $this->warn("User '{$username}' already exists; nothing changed.");

        return 0;
    }
    if (mb_strlen($password) < 8) {
        $this->error('Password must be at least 8 characters.');

        return 1;
    }
    $user = new User(['username' => $username, 'first_name' => $this->option('name'), 'role' => 'ADMIN', 'is_active' => true]);
    foreach (User::capabilityFields() as $field) {
        $user->setAttribute($field, true);
    }
    $user->password = $password;
    $user->save();
    Audit::log('CREATE', $user, $user, ['note' => 'Created from the command line']);
    $this->info("Administrator '{$username}' created.");

    return 0;
})->purpose('Create the first administrator');
