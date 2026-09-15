{{-- L invitation non bloquante a la premiere page apres l ouverture des formes de vie : « Decouvrir » ou
     « Plus tard », memorise par compte et par version d accueil. Rien n est bloque, rien n est offert. --}}
<div id="lifeform-welcome" class="content-box-s" style="margin-bottom: 12px;">
    <div class="header"><h3>{{ __('t_lifeforms_ui.welcome.title') }}</h3></div>
    <div class="content" style="padding: 12px;">
        <p style="margin: 0 0 8px 0;">{{ __('t_lifeforms_ui.welcome.text') }}</p>
        <p class="smallFont" style="margin: 0 0 10px 0;">{{ __('t_lifeforms_ui.welcome.fairness') }}</p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a class="btn_blue" href="{{ route('lifeforms.index') }}">{{ __('t_lifeforms_ui.welcome.discover') }}</a>
            <form method="post" action="{{ route('lifeforms.welcome.later') }}" style="display: inline;">
                {{ csrf_field() }}
                <button type="submit" class="btn_blue">{{ __('t_lifeforms_ui.welcome.later') }}</button>
            </form>
        </div>
    </div>
    <div class="footer"></div>
</div>
