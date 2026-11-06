<?php

namespace App\Providers;

use App\Models\BusinessSetting;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Server-side access control. Every route that reads or writes business
        // data uses one of these gates; hiding a button is never the only guard.
        Gate::define('admin', fn (User $u) => $u->isAdmin()
            ?: \Illuminate\Auth\Access\Response::deny('Only administrators can access this page.'));
        foreach (array_keys(User::CAPABILITIES) as $capability) {
            Gate::define($capability, fn (User $u) => $u->hasCapability($capability)
                ?: \Illuminate\Auth\Access\Response::deny('You do not have permission to perform this action.'));
        }
        // Customer balances: reporting users and those who record payments.
        Gate::define('view_balances', fn (User $u) => ($u->hasCapability('view_reports') || $u->hasCapability('record_payment'))
            ?: \Illuminate\Auth\Access\Response::deny('You do not have permission to view customer balances.'));

        Paginator::defaultView('partials.pagination');

        // Behind the host's proxy/CDN the app may not see HTTPS; generate https links when the site URL is https.
        if (str_starts_with((string) config('app.url'), 'https://') && $this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Organisation profile and the approvals badge for every page (computed once per request).
        View::composer('*', function ($view) {
            $request = request();
            if (! $request->attributes->has('erp.shared')) {
                $user = auth()->user();
                $shared = ['business' => BusinessSetting::current(), 'pendingApprovalsCount' => 0, 'user' => $user,
                    'loansReady' => false, 'overdueLoansCount' => 0, 'personalExpensesReady' => false, 'attendanceReady' => false, 'driverBookReady' => false];
                if ($user) {
                    // The personal expenses table arrives with a later database update; hide the menu until it is applied.
                    $shared['personalExpensesReady'] = \Illuminate\Support\Facades\Schema::hasTable('personal_expenses');
                    $shared['attendanceReady'] = \Illuminate\Support\Facades\Schema::hasTable('driver_attendances');
                    $shared['driverBookReady'] = \Illuminate\Support\Facades\Schema::hasTable('driver_salary_adjustments');
                }
                if ($user && $user->isAdmin()) {
                    $shared['pendingApprovalsCount'] = Income::pending()->count() + Expense::pending()->count() + Payment::pending()->count();
                    // The loans tables arrive with a later database update; until it is applied, hide the menu entry.
                    try {
                        $shared['overdueLoansCount'] = \App\Models\Loan::overdue()->count();
                        $shared['loansReady'] = true;
                    } catch (\Illuminate\Database\QueryException) {
                    }
                }
                $request->attributes->set('erp.shared', $shared);
            }
            $view->with($request->attributes->get('erp.shared'));
        });
    }
}
