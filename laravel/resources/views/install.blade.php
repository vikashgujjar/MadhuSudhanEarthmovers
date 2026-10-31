@extends('layouts.base')
@section('title', 'Install')
@section('body')
<div class="min-h-screen bg-surface px-5 py-10">
  <div class="mx-auto max-w-2xl">
    <div class="mb-8">@include('partials.logo')</div>
    <div class="card overflow-hidden">
      <div class="h-1.5 stripe-accent"></div>
      <div class="card-body space-y-6">
        <div>
          <h1 class="page-title">Install the ERP</h1>
          <p class="page-subtitle">One-time setup: creates the database tables and the first administrator. This page switches itself off afterwards.</p>
        </div>

        @include('partials.messages')

        <div>
          <p class="form-section mb-2">Server check</p>
          <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
            @foreach ($checks as [$label, $ok])
            <li class="flex items-center justify-between px-4 py-2"><span>{{ $label }}</span>@if ($ok)<span class="badge-green">OK</span>@else<span class="badge-red">Missing</span>@endif</li>
            @endforeach
            <li class="flex items-center justify-between px-4 py-2"><span>Database connection</span>@if ($state['db'])<span class="badge-green">OK</span>@else<span class="badge-red">Failed</span>@endif</li>
          </ul>
          @unless ($state['db'])
          <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            Could not connect to MySQL. Open the <code>.env</code> file (File Manager) and set <code>DB_DATABASE</code>, <code>DB_USERNAME</code> and <code>DB_PASSWORD</code> to the database you created in hPanel → Databases, then reload this page.
            <div class="mt-2 text-xs text-red-700">{{ \Illuminate\Support\Str::limit($state['error'], 300) }}</div>
          </div>
          @endunless
        </div>

        @if ($state['db'])
        <form method="post" action="{{ route('install.run') }}" class="space-y-5" novalidate>
          @csrf
          @include('partials.form-errors')
          <x-field name="install_key" label="Installation key" required help="Copy the value of ERP_INSTALL_KEY from the .env file. It proves you are the owner of this hosting account." autocomplete="off" />
          <x-field name="business_name" label="Business name" :value="$business->business_name" required />
          <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="first_name" label="Your name" required />
            <x-field name="username" label="Admin username" value="admin" required />
            <x-field name="password" label="Admin password" type="password" required autocomplete="new-password" help="At least 8 characters; not entirely numeric; not a common password." />
            <x-field name="password_confirmation" label="Repeat password" type="password" required autocomplete="new-password" />
          </div>
          <button class="btn-primary w-full py-3"><i data-lucide="rocket"></i> Install</button>
        </form>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection
