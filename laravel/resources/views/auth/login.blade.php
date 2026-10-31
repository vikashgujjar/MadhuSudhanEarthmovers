@extends('layouts.base')
@section('title', 'Sign in')
@section('body_class', 'bg-white')
@section('body')
<div class="flex min-h-screen">
  <!-- Brand panel -->
  <section class="relative hidden w-1/2 overflow-hidden bg-navy-800 lg:flex lg:flex-col xl:w-[55%]">
    <div class="absolute inset-x-0 top-0 h-2 stripe-accent"></div>
    <svg class="pointer-events-none absolute -bottom-24 -right-24 h-[520px] w-[520px] opacity-[0.07]" viewBox="0 0 48 48" aria-hidden="true">
      <path d="M10.5 33 L19.5 14.5 L33 18.5" fill="none" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M31.2 17.2 L39.2 21.4 L36.6 31.4 L28.4 28.6 Z" fill="#FFFFFF"/>
    </svg>
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,107,0,0.18),transparent_45%)]"></div>
    <div class="relative flex flex-1 flex-col justify-between p-12 xl:p-16">
      @include('partials.logo', ['dark' => true, 'size' => 'h-14 w-14'])
      <div class="max-w-lg">
        <p class="mb-4 inline-flex items-center gap-2 rounded-full border border-brand-500/40 bg-brand-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-brand-400">
          <i data-lucide="hard-hat" class="h-3.5 w-3.5"></i> {{ $business->erp_title }}
        </p>
        <h1 class="font-display text-5xl font-extrabold uppercase leading-[0.95] tracking-wide text-white xl:text-6xl">
          Built for the<br><span class="text-brand-500">ground you move.</span>
        </h1>
        <p class="mt-6 text-base leading-relaxed text-navy-100/80">{{ $business->tagline }}</p>
        <ul class="mt-10 grid gap-4 text-sm text-navy-100/90">
          <li class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/5 text-brand-500"><i data-lucide="construction" class="h-5 w-5"></i></span> JCB &amp; tractor income, expenses and profitability</li>
          <li class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/5 text-brand-500"><i data-lucide="wallet" class="h-5 w-5"></i></span> Partial payments, customer outstanding &amp; cash book</li>
          <li class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/5 text-brand-500"><i data-lucide="shield-check" class="h-5 w-5"></i></span> Approval workflow with a complete audit trail</li>
        </ul>
      </div>
      <p class="text-xs text-navy-200/60">&copy; {{ date('Y') }} {{ $business->business_name }}. All rights reserved.</p>
    </div>
  </section>

  <!-- Sign-in form -->
  <section class="flex flex-1 flex-col">
    <div class="h-1.5 stripe-accent lg:hidden"></div>
    <div class="flex flex-1 items-center justify-center px-5 py-12 sm:px-10">
      <div class="w-full max-w-md">
        <div class="mb-10 lg:hidden">@include('partials.logo', ['size' => 'h-12 w-12'])</div>
        <h2 class="font-display text-3xl font-bold uppercase tracking-wide text-navy-800">Sign in</h2>
        <p class="mt-2 text-sm text-slate-500">Welcome to <span class="font-semibold text-navy-800">{{ $business->erp_title }}</span>. Use the credentials provided by your administrator.</p>

        <form method="post" action="{{ route('login') }}" class="mt-8 space-y-5" novalidate>
          @csrf
          @if ($errors->has('login') || session('error'))
          <div class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <i data-lucide="circle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i>
            <div>{{ $errors->first('login') ?: session('error') }}</div>
          </div>
          @endif
          <x-field name="username" label="Username" required placeholder="Enter your username" autofocus autocomplete="username" />
          <x-field name="password" label="Password" type="password" required placeholder="Enter your password" autocomplete="current-password" />
          <button type="submit" class="btn-primary w-full py-3 text-base"><i data-lucide="log-in"></i> Sign in</button>
        </form>
        <p class="mt-8 text-center text-xs text-slate-400">Forgot your password? Ask an administrator to reset it from Users &amp; Permissions.</p>
      </div>
    </div>
  </section>
</div>
@endsection
