<!doctype html>
@inject('homeSettings', 'OGame\Services\SettingsService')
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('t_home.description') }}">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title>OGameX Francophone — {{ __('t_home.title') }}</title>
    <link rel="stylesheet" href="{{ asset('azria-home/v2/home.css') }}">
    <link rel="stylesheet" href="{{ asset('azria-home/v2/reference-alignment.css') }}">
    <script src="{{ asset('azria-home/v2/home.js') }}" defer></script>
</head>
<body data-mode="{{ $errors->getBag('register')->any() ? 'register' : ($errors->any() ? 'login' : 'register') }}" data-errors="{{ $errors->any() || $errors->getBag('register')->any() ? 'true' : 'false' }}">
<a class="skip" href="#main">{{ __('t_home.skip') }}</a>
<header class="site-header"><div class="header-inner">
    <a href="#" class="brand" aria-label="OGameX Francophone"><img src="{{ asset('azria-home/v2/logo.webp') }}" width="220" height="80" alt="OGameX Francophone"></a>
    <nav aria-label="{{ __('t_home.navigation') }}">
        <a class="nav-home" href="#main">{{ __('t_home.home') }}</a>
        <a href="#universe">{{ __('t_home.universe') }}</a>
        <a href="#community">{{ __('t_home.community') }}</a>
        <a href="https://wiki.ogame.org/" target="_blank" rel="noopener noreferrer">{{ __('t_home.wiki') }}</a>
        <a class="nav-login" href="#login" data-auth="login">{{ __('t_home.login') }}</a>
    </nav>
</div></header>
<main id="main" class="shell">
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero-copy">
            <h1 id="hero-title">{{ __('t_home.headline_one') }}<br><span>{{ __('t_home.headline_two') }}</span></h1>
            <p class="hero-intro">{{ __('t_home.intro') }}</p>
            <a href="#universe" class="button secondary">{{ __('t_home.discover') }} <span aria-hidden="true"></span></a>
        </div>
        <aside class="auth-panel" aria-label="{{ __('t_home.account') }}">
            <div class="auth-tabs" hidden>
                @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
                <button type="button" data-auth="register">{{ __('t_home.register_tab') }}</button>
                @endif
                <button type="button" data-auth="login">{{ __('t_home.login') }}</button>
            </div>
            @if (session('status'))
            <p class="notice" role="status">{{ session('status') }}</p>
            @endif
            @if (session('ban_message'))
            <p class="notice error" role="alert">{{ session('ban_message') }}</p>
            @endif
            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
            <section id="register" class="auth-section" data-panel="register" aria-labelledby="register-title">
                <h2 id="register-title">{{ __('t_home.join') }}</h2>
                @if ($errors->getBag('register')->any())
                <div class="notice error" role="alert" tabindex="-1" data-error-summary><ul>
                    @foreach ($errors->getBag('register')->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul></div>
                @endif
                <form method="POST" action="{{ route('register') }}" data-game-form>
                    @csrf
                    <label for="signup-universe">{{ __('t_home.choose_universe') }}</label>
                    <select id="signup-universe" name="uni" required aria-describedby="signup-universe-help">
                        <option value="s1">{{ $homeSettings->universeName() }}</option>
                    </select>
                    <p class="universe-help" id="signup-universe-help">{{ __('t_home.register_universe_help') }}</p>
                    <label for="signup-username">{{ __('t_home.username') }}</label>
                    <input id="signup-username" name="username" value="{{ old('username') }}" type="text" autocomplete="username" minlength="3" maxlength="20" required>
                    <label for="signup-email">{{ __('t_home.email') }}</label>
                    <input id="signup-email" name="email" value="{{ old('email') }}" type="email" autocomplete="email" maxlength="255" required>
                    <label for="signup-password">{{ __('t_home.password') }}</label>
                    <input id="signup-password" name="password" type="password" autocomplete="new-password" required>
                    <label for="signup-confirm">{{ __('t_home.confirm') }}</label>
                    <input id="signup-confirm" name="password_confirmation" type="password" autocomplete="new-password" required>
                    <div class="consent"><input id="consent" name="agb" type="checkbox" value="on" required><label for="consent">{{ __('t_home.accept') }} <a href="{{ route('terms.ajax') }}" data-document>{{ __('t_home.game_terms') }}</a></label></div>
                    <button class="button primary" type="submit">{{ __('t_home.create') }} <span aria-hidden="true">›</span></button>
                </form>
                <p class="switch">{{ __('t_home.already') }} <a href="#login" data-auth="login">{{ __('t_home.login') }}</a></p>
                @if (Route::has('password.request'))
                <p class="password-help"><a href="{{ route('password.request') }}">{{ __('t_home.forgot') }}</a></p>
                @endif
            </section>
            @endif
            <section id="login" class="auth-section" data-panel="login" aria-labelledby="login-title">
                <h2 id="login-title">{{ __('t_home.welcome') }}</h2>
                @if ($errors->any())
                <div class="notice error" role="alert" tabindex="-1" data-error-summary><ul>
                    @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul></div>
                @endif
                <form method="POST" action="{{ route('login') }}" data-game-form>
                    @csrf
                    <label for="login-universe">{{ __('t_home.choose_universe') }}</label>
                    <select id="login-universe" name="uni" required aria-describedby="login-universe-help">
                        <option value="s1">{{ $homeSettings->universeName() }}</option>
                    </select>
                    <p class="universe-help" id="login-universe-help">{{ __('t_home.login_universe_help') }}</p>
                    <label for="login-email">{{ __('t_home.email') }}</label>
                    <input id="login-email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required>
                    <label for="login-password">{{ __('t_home.password') }}</label>
                    <input id="login-password" name="password" type="password" autocomplete="current-password" required>
                    <div class="login-options"><label class="remember"><input type="checkbox" name="remember" value="1"> {{ __('t_home.remember') }}</label>
                    </div>
                    <button class="button primary" type="submit">{{ __('t_home.enter') }} <span aria-hidden="true">›</span></button>
                </form>
                @if (Route::has('password.request'))
                <p class="password-help"><a href="{{ route('password.request') }}">{{ __('t_home.forgot') }}</a></p>
                @endif
            </section>
        </aside>
    </section>
    <section id="universe" class="universe" aria-labelledby="universe-title">
        <div class="section-heading visually-hidden"><span aria-hidden="true"></span><h2 id="universe-title">{{ __('t_home.your_universe') }}</h2><span aria-hidden="true"></span></div>
        <div class="cards">
            <article class="card"><img src="{{ asset('azria-home/v2/colony.webp') }}" width="640" height="360" alt="" loading="lazy"><div class="card-copy"><img class="emblem" src="{{ asset('azria-home/v2/emblem-colony.svg') }}" alt="" width="34" height="36"><h3>{{ __('t_home.build') }}</h3><p>{{ __('t_home.build_text') }}</p></div></article>
<article class="card" id="community"><img src="{{ asset('azria-home/v2/alliance-detailed.webp') }}" width="640" height="360" alt="" loading="lazy"><div class="card-copy"><img class="emblem" src="{{ asset('azria-home/v2/emblem-alliance.svg') }}" alt="" width="34" height="36"><h3>{{ __('t_home.alliance') }}</h3><p>{{ __('t_home.alliance_text') }}</p></div></article>
            <article class="card"><img src="{{ asset('azria-home/v2/explore.webp') }}" width="640" height="360" alt="" loading="lazy"><div class="card-copy"><img class="emblem" src="{{ asset('azria-home/v2/emblem-explore.svg') }}" alt="" width="34" height="36"><h3>{{ __('t_home.explore') }}</h3><p>{{ __('t_home.explore_text') }}</p></div></article>
        </div>
    </section>
</main>
<footer class="site-footer shell"><nav aria-label="{{ __('t_home.legal_navigation') }}"><a href="{{ route('rules.ajax') }}" data-document>{{ __('t_home.rules') }}</a><a href="{{ route('legal.ajax') }}" data-document>{{ __('t_home.legal') }}</a><a href="{{ route('privacypolicy.ajax') }}" data-document>{{ __('t_home.privacy') }}</a><a href="{{ route('terms.ajax') }}" data-document>{{ __('t_home.terms') }}</a><a href="{{ route('contact.ajax') }}" data-document>{{ __('t_home.contact') }}</a></nav><p class="footer-credit">OGameX Francophone</p><div class="languages"><a href="{{ route('language.switch', ['lang' => 'fr']) }}" lang="fr" aria-label="Français">FR</a><a href="{{ route('language.switch', ['lang' => 'en']) }}" lang="en" aria-label="English">EN</a><a href="{{ route('language.switch', ['lang' => 'it']) }}" lang="it" aria-label="Italiano">IT</a><a href="{{ route('language.switch', ['lang' => 'nl']) }}" lang="nl" aria-label="Nederlands">NL</a><a href="{{ route('language.switch', ['lang' => 'zh-TW']) }}" lang="zh-TW" aria-label="繁體中文">繁體中文</a></div></footer>
<dialog id="document-dialog" aria-labelledby="document-title"><div class="dialog-bar"><h2 id="document-title">{{ __('t_home.information') }}</h2><button type="button" class="dialog-close" aria-label="{{ __('t_home.close') }}">×</button></div><iframe title="{{ __('t_home.information') }}" sandbox="allow-same-origin allow-popups"></iframe></dialog>
</body>
</html>
