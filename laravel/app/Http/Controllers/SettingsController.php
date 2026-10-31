<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function edit()
    {
        return view('settings.edit', ['settings' => $this->business(), 'pendingMigrations' => $this->pendingMigrations()]);
    }

    /** Database changes shipped with an update that have not been applied yet. */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [];
        }
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));

        return array_values(array_diff($files, $migrator->getRepository()->getRan()));
    }

    /** Apply pending database updates (for hosting without a terminal). */
    public function migrate()
    {
        $pending = $this->pendingMigrations();
        if (! $pending) {
            return redirect()->route('settings')->with('info', 'The database is already up to date.');
        }
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        Audit::log('SETTINGS', objectRepr: 'Database updated', modelName: 'System', changes: ['note' => 'Applied: '.implode(', ', $pending)]);

        return redirect()->route('settings')->with('success', count($pending).' database update'.plural(count($pending)).' applied.');
    }

    public function update(Request $request)
    {
        $prefix = ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9\-\/]+$/'];
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'erp_title' => ['required', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:12'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'financial_year_start_month' => ['required', 'integer', Rule::in(array_keys(BusinessSetting::MONTHS))],
            'invoice_prefix' => $prefix,
            'receipt_prefix' => $prefix,
            'expense_prefix' => $prefix,
            'opening_cash_balance' => ['required', 'numeric', 'decimal:0,2', 'min:-999999999999.99', 'max:999999999999.99'],
            'report_footer' => ['nullable', 'string', 'max:200'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ], [
            'invoice_prefix.regex' => "Use letters, numbers, '-' or '/' only.",
            'receipt_prefix.regex' => "Use letters, numbers, '-' or '/' only.",
            'expense_prefix.regex' => "Use letters, numbers, '-' or '/' only.",
            'logo.max' => 'Logo must be smaller than 2 MB.',
        ]);

        $settings = $this->business();
        $before = Audit::snapshot($settings);
        foreach (['invoice_prefix', 'receipt_prefix', 'expense_prefix'] as $key) {
            $data[$key] = strtoupper(trim($data[$key]));
        }
        $settings->fill(collect($data)->except(['logo', 'remove_logo'])->all());
        $settings->require_approval = $request->boolean('require_approval');

        $oldLogo = $settings->logo;
        if ($request->hasFile('logo')) {
            $settings->logo = $request->file('logo')->store('branding', 'local');
        } elseif ($request->boolean('remove_logo')) {
            $settings->logo = null;
        }
        $settings->save();
        if ($oldLogo && $oldLogo !== $settings->logo) {
            Storage::disk('local')->delete($oldLogo);
        }

        $changes = Audit::diff($before, Audit::snapshot($settings->fresh()));
        if ($changes) {
            Audit::log('SETTINGS', $settings, changes: ['fields' => $changes]);
        }

        return redirect()->route('settings')->with('success', 'Business settings saved. The new details now appear on every page and report.');
    }
}
