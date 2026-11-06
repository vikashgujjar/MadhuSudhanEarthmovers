<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-navy-800 transition-transform duration-200 lg:translate-x-0" aria-label="Main navigation">
  <div class="h-1.5 stripe-accent"></div>
  <div class="flex items-center justify-between px-5 py-5">
    <a href="{{ route('dashboard') }}" class="min-w-0">@include('partials.logo', ['dark' => true])</a>
    <button type="button" class="rounded-lg p-2 text-navy-200 hover:bg-white/10 lg:hidden" data-sidebar-close aria-label="Close menu"><i data-lucide="x" class="h-5 w-5"></i></button>
  </div>
  <nav class="flex-1 overflow-y-auto px-3 pb-6">
    <a href="{{ route('dashboard') }}" class="nav-link {{ nav_active('/', true) }}"><i data-lucide="layout-dashboard"></i> Dashboard</a>

    <div class="nav-section">Fleet</div>
    <a href="{{ route('fleet.jcb') }}" class="nav-link {{ nav_active('/fleet/jcb/') }}"><i data-lucide="construction"></i> JCB Management</a>
    <a href="{{ route('fleet.tractors') }}" class="nav-link {{ nav_active('/fleet/tractors/') }}"><i data-lucide="tractor"></i> Tractor Management</a>
    <a href="{{ route('vehicles.index') }}" class="nav-link {{ nav_active('/fleet/vehicles/') }}"><i data-lucide="truck"></i> All Vehicles</a>
    <a href="{{ route('drivers.index') }}" class="nav-link {{ nav_active('/fleet/drivers/') }}"><i data-lucide="id-card"></i> Drivers</a>
    @if ($attendanceReady)
    <a href="{{ route('attendance.index') }}" class="nav-link {{ nav_active('/fleet/attendance/') }}"><i data-lucide="calendar-check"></i> Driver Attendance</a>
    @endif

    <div class="nav-section">Transactions</div>
    <a href="{{ route('income.index') }}" class="nav-link {{ nav_active('/finance/income/') }}"><i data-lucide="trending-up"></i> Income</a>
    <a href="{{ route('expenses.index') }}" class="nav-link {{ nav_active('/finance/expenses/') }}"><i data-lucide="receipt"></i> Expenses</a>
    <a href="{{ route('driver-expenses.index') }}" class="nav-link {{ nav_active('/finance/driver-expenses/') }}"><i data-lucide="hard-hat"></i> Driver Expenses</a>
    @if ($driverBookReady)
    @can('view_reports')
    <a href="{{ route('driver-book.index') }}" class="nav-link {{ nav_active('/finance/driver-cashbook/') }}"><i data-lucide="book-user"></i> Driver Cash Book</a>
    @endcan
    @endif
    <a href="{{ route('payments.index') }}" class="nav-link {{ nav_active('/finance/payments/') }}"><i data-lucide="wallet"></i> Payments</a>
    @if ($loansReady)
    <a href="{{ route('loans.index') }}" class="nav-link {{ nav_active('/loans/') }}"><i data-lucide="hand-coins"></i> <span class="flex-1">Money Lent</span>@if ($overdueLoansCount)<span class="rounded-full bg-red-500 px-2 py-0.5 text-xs font-bold text-white" title="Overdue">{{ $overdueLoansCount }}</span>@endif</a>
    @endif
    @if ($personalExpensesReady && $user->hasCapability('manage_personal_expenses'))
    <a href="{{ route('personal-expenses.index') }}" class="nav-link {{ nav_active('/personal-expenses/') }}"><i data-lucide="user-round"></i> Personal Expenses</a>
    @endif
    @if ($user->isAdmin())
    <a href="{{ route('approvals.index') }}" class="nav-link {{ nav_active('/finance/approvals/') }}"><i data-lucide="badge-check"></i> <span class="flex-1">Approvals</span>@if ($pendingApprovalsCount)<span class="rounded-full bg-white/15 px-2 py-0.5 text-xs font-bold text-white">{{ $pendingApprovalsCount }}</span>@endif</a>
    @endif

    <div class="nav-section">Customers</div>
    <a href="{{ route('customers.index') }}" class="nav-link {{ nav_active('/customers/', false, '/customers/outstanding/') }}"><i data-lucide="users"></i> Customers</a>
    @can('view_balances')
    <a href="{{ route('customers.outstanding') }}" class="nav-link {{ nav_active('/customers/outstanding/') }}"><i data-lucide="hourglass"></i> Outstanding Balances</a>
    @endcan

    @can('view_reports')
    <div class="nav-section">Accounts &amp; Reports</div>
    <a href="{{ route('reports.cashbook') }}" class="nav-link {{ nav_active('/reports/cash-book/') }}"><i data-lucide="book-open"></i> Cash Book</a>
    <a href="{{ route('reports.profitability') }}" class="nav-link {{ nav_active('/reports/profitability/') }}"><i data-lucide="bar-chart-3"></i> Vehicle Profitability</a>
    <a href="{{ route('reports.index') }}" class="nav-link {{ nav_active('/reports/', true) }}{{ nav_active(['/reports/pnl/', '/reports/driver-expenses/', '/reports/expense-categories/']) }}"><i data-lucide="file-bar-chart"></i> Financial Reports</a>
    @endcan

    @if ($user->isAdmin())
    <div class="nav-section">Administration</div>
    <a href="{{ route('users.index') }}" class="nav-link {{ nav_active('/accounts/users/') }}"><i data-lucide="shield-check"></i> Users &amp; Permissions</a>
    <a href="{{ route('settings') }}" class="nav-link {{ nav_active('/settings/') }}"><i data-lucide="building-2"></i> Business Settings</a>
    <a href="{{ route('audit.index') }}" class="nav-link {{ nav_active('/audit/') }}"><i data-lucide="scroll-text"></i> Audit Logs</a>
    @endif
  </nav>
  <div class="border-t border-white/10 px-5 py-4 text-xs text-navy-200/70">
    <div class="font-semibold text-navy-100">{{ $business->erp_title }}</div>
    <div class="truncate">{{ $business->tagline }}</div>
  </div>
</aside>
