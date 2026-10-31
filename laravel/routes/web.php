<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::get('install', [InstallController::class, 'show'])->name('install');
Route::post('install', [InstallController::class, 'run'])->name('install.run');

Route::get('media/{path}', [MediaController::class, 'show'])->where('path', '.*')->name('media');

Route::middleware('guest')->group(function () {
    Route::get('accounts/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('accounts/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
});

Route::middleware('auth')->group(function () {
    Route::post('accounts/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('accounts/password', [AuthController::class, 'showPassword'])->name('password');
    Route::post('accounts/password', [AuthController::class, 'changePassword']);

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::middleware('can:admin')->group(function () {
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('settings', [SettingsController::class, 'update']);
        Route::post('settings/update-database', [SettingsController::class, 'migrate'])->name('settings.migrate');

        Route::get('accounts/users', [UserController::class, 'index'])->name('users.index');
        Route::get('accounts/users/new', [UserController::class, 'create'])->name('users.create');
        Route::post('accounts/users/new', [UserController::class, 'store']);
        Route::get('accounts/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::post('accounts/users/{user}/edit', [UserController::class, 'update']);
        Route::get('accounts/users/{user}/password', [UserController::class, 'passwordForm'])->name('users.password');
        Route::post('accounts/users/{user}/password', [UserController::class, 'setPassword']);
        Route::post('accounts/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/{log}', [AuditController::class, 'show'])->name('audit.show');

        Route::get('finance/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('finance/approvals/bulk', [ApprovalController::class, 'bulk'])->name('approvals.bulk');
        Route::post('finance/approvals/{kind}/{id}/approve', [ApprovalController::class, 'approve'])
            ->whereIn('kind', ['income', 'expense', 'payment'])->whereNumber('id')->name('approvals.approve');
        Route::post('finance/approvals/{kind}/{id}/reject', [ApprovalController::class, 'reject'])
            ->whereIn('kind', ['income', 'expense', 'payment'])->whereNumber('id')->name('approvals.reject');

        Route::get('fleet/vehicles/{vehicle}/delete', [VehicleController::class, 'confirmDelete'])->name('vehicles.delete');
        Route::post('fleet/vehicles/{vehicle}/delete', [VehicleController::class, 'destroy']);
        Route::get('fleet/drivers/{driver}/delete', [DriverController::class, 'confirmDelete'])->name('drivers.delete');
        Route::post('fleet/drivers/{driver}/delete', [DriverController::class, 'destroy']);
        Route::get('customers/{customer}/delete', [CustomerController::class, 'confirmDelete'])->whereNumber('customer')->name('customers.delete');
        Route::post('customers/{customer}/delete', [CustomerController::class, 'destroy'])->whereNumber('customer');
        Route::get('finance/income/{income}/delete', [IncomeController::class, 'confirmDelete'])->name('income.delete');
        Route::post('finance/income/{income}/delete', [IncomeController::class, 'destroy']);
        Route::get('finance/expenses/{expense}/delete', [ExpenseController::class, 'confirmDelete'])->name('expenses.delete');
        Route::post('finance/expenses/{expense}/delete', [ExpenseController::class, 'destroy']);
        Route::get('finance/payments/{payment}/delete', [PaymentController::class, 'confirmDelete'])->name('payments.delete');
        Route::post('finance/payments/{payment}/delete', [PaymentController::class, 'destroy']);
    });

    // Fleet
    Route::get('fleet/jcb', [VehicleController::class, 'jcb'])->name('fleet.jcb');
    Route::get('fleet/tractors', [VehicleController::class, 'tractors'])->name('fleet.tractors');
    Route::get('fleet/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::middleware('can:manage_masters')->group(function () {
        Route::get('fleet/vehicles/new', [VehicleController::class, 'create'])->name('vehicles.create');
        Route::post('fleet/vehicles/new', [VehicleController::class, 'store']);
        Route::get('fleet/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('vehicles.edit');
        Route::post('fleet/vehicles/{vehicle}/edit', [VehicleController::class, 'update']);
        Route::get('fleet/drivers/new', [DriverController::class, 'create'])->name('drivers.create');
        Route::post('fleet/drivers/new', [DriverController::class, 'store']);
        Route::get('fleet/drivers/{driver}/edit', [DriverController::class, 'edit'])->name('drivers.edit');
        Route::post('fleet/drivers/{driver}/edit', [DriverController::class, 'update']);
        Route::get('customers/new', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('customers/new', [CustomerController::class, 'store']);
        Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->whereNumber('customer')->name('customers.edit');
        Route::post('customers/{customer}/edit', [CustomerController::class, 'update'])->whereNumber('customer');
    });
    Route::get('fleet/vehicles/{vehicle}', [VehicleController::class, 'show'])->whereNumber('vehicle')->name('vehicles.show');
    Route::get('fleet/drivers', [DriverController::class, 'index'])->name('drivers.index');
    Route::get('fleet/drivers/{driver}', [DriverController::class, 'show'])->whereNumber('driver')->name('drivers.show');
    Route::get('fleet/api/vehicles', [VehicleController::class, 'options'])->name('api.vehicles');

    // Customers
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/outstanding', [CustomerController::class, 'outstanding'])->middleware('can:view_balances')->name('customers.outstanding');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');

    // Income
    Route::get('finance/income', [IncomeController::class, 'index'])->name('income.index');
    Route::get('finance/income/new', [IncomeController::class, 'create'])->middleware('can:add_income')->name('income.create');
    Route::post('finance/income/new', [IncomeController::class, 'store'])->middleware('can:add_income');
    Route::get('finance/income/{income}', [IncomeController::class, 'show'])->whereNumber('income')->name('income.show');
    Route::get('finance/income/{income}/edit', [IncomeController::class, 'edit'])->middleware('can:add_income')->name('income.edit');
    Route::post('finance/income/{income}/edit', [IncomeController::class, 'update'])->middleware('can:add_income');

    // Expenses (capability depends on the expense type; checked in the controller)
    Route::get('finance/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('finance/expenses/new', [ExpenseController::class, 'create'])->middleware('can:add_expense')->name('expenses.create');
    Route::post('finance/expenses/new', [ExpenseController::class, 'store'])->middleware('can:add_expense');
    Route::get('finance/expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->name('expenses.show');
    Route::get('finance/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::post('finance/expenses/{expense}/edit', [ExpenseController::class, 'update']);
    Route::get('finance/driver-expenses', [ExpenseController::class, 'driverIndex'])->name('driver-expenses.index');
    Route::get('finance/driver-expenses/new', [ExpenseController::class, 'driverCreate'])->middleware('can:add_driver_expense')->name('driver-expenses.create');
    Route::post('finance/driver-expenses/new', [ExpenseController::class, 'driverStore'])->middleware('can:add_driver_expense');

    // Payments
    Route::get('finance/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::middleware('can:record_payment')->group(function () {
        Route::get('finance/payments/new', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('finance/payments/new', [PaymentController::class, 'store']);
        Route::get('finance/payments/{payment}/edit', [PaymentController::class, 'edit'])->name('payments.edit');
        Route::post('finance/payments/{payment}/edit', [PaymentController::class, 'update']);
        Route::get('finance/api/open-invoices', [PaymentController::class, 'openInvoices'])->name('api.invoices');
    });
    Route::get('finance/payments/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('payments.show');

    // Reports
    Route::middleware('can:view_reports')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/cash-book', [ReportController::class, 'cashbook'])->name('reports.cashbook');
        Route::get('reports/profitability', [ReportController::class, 'profitability'])->name('reports.profitability');
        Route::get('reports/pnl', [ReportController::class, 'pnl'])->name('reports.pnl');
        Route::get('reports/driver-expenses', [ReportController::class, 'driverExpenses'])->name('reports.driver-expenses');
        Route::get('reports/expense-categories', [ReportController::class, 'expenseCategories'])->name('reports.expense-categories');
    });
});
