@extends('layouts.base')
@section('body')
<div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-navy-950/60 backdrop-blur-sm lg:hidden" data-sidebar-close></div>
@include('partials.sidebar')
<div id="app-shell" class="min-h-screen lg:pl-72">
  <header id="topbar" class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/90 backdrop-blur">
    <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
      <button type="button" class="btn-icon lg:hidden" data-sidebar-open aria-label="Open menu"><i data-lucide="menu"></i></button>
      <div class="min-w-0 flex-1">
        <p class="truncate text-[0.7rem] font-bold uppercase tracking-[0.18em] text-brand-600">{{ $business->business_name }}</p>
        <p class="truncate text-sm font-semibold text-navy-800">@yield('header_title', $business->erp_title)</p>
      </div>
      @if ($user->isAdmin() && $pendingApprovalsCount)
      <a href="{{ route('approvals.index') }}" class="relative btn-icon" title="{{ $pendingApprovalsCount }} pending approval{{ plural($pendingApprovalsCount) }}">
        <i data-lucide="bell"></i>
        <span class="absolute -right-0.5 -top-0.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-brand-500 px-1 text-[0.65rem] font-bold text-white">{{ $pendingApprovalsCount }}</span>
      </a>
      @endif
      <div class="relative" data-dropdown>
        <button type="button" class="flex items-center gap-2 rounded-xl p-1.5 pr-2 hover:bg-slate-100" data-dropdown-toggle aria-haspopup="true">
          <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-navy-800 text-sm font-bold text-white">{{ mb_strtoupper(mb_substr($user->username, 0, 1)) }}</span>
          <span class="hidden text-left sm:block">
            <span class="block text-sm font-semibold leading-tight text-navy-800">{{ $user }}</span>
            <span class="block text-xs leading-tight text-slate-500">{{ $user->roleLabel() }}</span>
          </span>
          <i data-lucide="chevron-down" class="hidden h-4 w-4 text-slate-400 sm:block"></i>
        </button>
        <div class="absolute right-0 mt-2 hidden w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl" data-dropdown-menu>
          <a href="{{ route('password') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 hover:bg-slate-50"><i data-lucide="key-round" class="h-4 w-4"></i> Change password</a>
          <form method="post" action="{{ route('logout') }}">@csrf
            <button class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-red-600 hover:bg-red-50"><i data-lucide="log-out" class="h-4 w-4"></i> Sign out</button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
    <div class="print-only mb-4 border-b-2 border-navy-800 pb-3">
      <div class="text-xl font-bold text-navy-800">{{ $business->business_name }}</div>
      <div class="text-xs text-slate-600">{{ $business->tagline }}</div>
      @if ($business->address)<div class="text-xs text-slate-600">{!! nl2br(e($business->address)) !!}</div>@endif
      @if ($business->contactLine())<div class="text-xs text-slate-600">{{ $business->contactLine() }}</div>@endif
    </div>
    @include('partials.messages')
    @yield('content')
  </main>
  <footer class="no-print px-4 pb-6 text-center text-xs text-slate-400 sm:px-6 lg:px-8">
    &copy; {{ date('Y') }} {{ $business->business_name }} · {{ $business->erp_title }}
  </footer>
</div>
@endsection
