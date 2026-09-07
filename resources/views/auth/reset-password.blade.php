<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex">
<title>{{ __('t_recovery.reset') }} — OGameX Francophone</title>
<link rel="stylesheet" href="{{ asset('azria-home/v2/home.css') }}"><link rel="stylesheet" href="{{ asset('azria-home/v2/reference-alignment.css') }}"><link rel="stylesheet" href="{{ asset('azria-home/v2/recovery.css') }}"></head>
<body class="recovery-page">
<header class="site-header"><div class="header-inner"><a class="brand" href="{{ route('login') }}"><img src="{{ asset('azria-home/v2/logo.webp') }}" alt="OGameX Francophone" width="264" height="88"></a><nav aria-label="{{ __('t_recovery.home') }}"><a href="{{ route('login') }}">{{ __('t_recovery.home') }}</a><a class="nav-login" href="{{ route('login') }}#login">{{ __('t_recovery.login') }}</a></nav></div></header>
<main class="recovery-shell"><div class="recovery-copy"><h1>{{ __('t_recovery.intro') }}</h1><p>{{ __('t_recovery.privacy') }}</p></div>
<section class="auth-panel auth-section" aria-labelledby="recovery-title"><h2 id="recovery-title">{{ __('t_recovery.reset') }}</h2><p class="recovery-description">{{ __('t_recovery.reset_detail') }}</p>
@if (session('status'))
<p class="notice" role="status">{{ session('status') }}</p>
@endif
@if ($errors->any())
<div class="notice error" role="alert"><ul>
@foreach ($errors->all() as $error)
<li>{{ $error }}</li>
@endforeach
</ul></div>
@endif
<form method="POST" action="{{ route('password.update') }}">
@csrf
<input type="hidden" name="token" value="{{ $request->route('token') }}">
<label for="email">{{ __('t_recovery.email') }}</label><input type="email" id="email" name="email" value="{{ old('email', $request->email) }}" autocomplete="email" required>
<label for="password">{{ __('t_recovery.password') }}</label><input type="password" id="password" name="password" autocomplete="new-password" required><label for="password_confirmation">{{ __('t_recovery.confirm') }}</label><input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
<button class="button primary" type="submit">{{ __('t_recovery.save') }}<span aria-hidden="true">›</span></button></form>

<p class="password-help"><a href="{{ route('login') }}#login">{{ __('t_recovery.back') }}</a></p>
<p class="password-help"><a href="{{ route('password.request') }}">{{ __('t_recovery.retry') }}</a></p>
</section></main><footer class="site-footer"><p>OGameX Francophone</p></footer></body></html>
