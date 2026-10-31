@extends('layouts.base')
@section('body')
<div class="flex min-h-screen flex-col items-center justify-center bg-surface px-6 text-center">
  <div class="mb-8">@include('partials.logo')</div>
  <p class="font-display text-7xl font-extrabold text-brand-500">@yield('code')</p>
  <h1 class="mt-2 font-display text-3xl font-bold uppercase tracking-wide text-navy-800">@yield('heading')</h1>
  <p class="mt-3 max-w-md text-sm text-slate-500">@yield('message')</p>
  <div class="mt-8 flex gap-3">
    <a href="{{ url('/') }}" class="btn-primary"><i data-lucide="layout-dashboard"></i> Go to dashboard</a>
    <a href="javascript:history.back()" class="btn-secondary">Go back</a>
  </div>
</div>
@endsection
