@extends('ingame.layouts.main')

@section('content')

    @php
        // Regroupement par valeur de tritium, comme la page officielle : un bandeau
        // « Valeur en tritium : N » puis les missions qui la valent. Le service a deja trie
        // les missions par valeur croissante, un simple parcours suffit donc.
        $groupes = [];
        foreach ($missions as $mission) {
            $groupes[$mission['tritium']][] = $mission;
        }
    @endphp

    <style>
        /* Le theme porte deja toute l'interface des recompenses d'OGame, mais uniquement
           sous #rewardings : c'est la seule raison pour laquelle le conteneur ci-dessous
           porte cet identifiant. Ce bloc ne complete que ce que le theme ne fournit pas :
           les onglets et quelques etats. Aucune feuille construite n'est modifiee, elles
           ne peuvent pas etre regenerees sur ce serveur. */
        #rewardings .ogx-tab {
            cursor: pointer;
        }

        #rewardings .ogx-tab.reached {
            background: #2b5c1e;
            border-color: #3f8a2c;
        }

        #rewardings .ogx-tab.active {
            box-shadow: inset 0 0 0 1px #8fce00;
        }

        #rewardings .ogx-stage-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* .rewardheader porte le cadre ENTIER — puits d icone, bande de titre et
           biseau — en une seule image de fond, exactement comme .rewardlistimg sur la
           page Recompenses. Le decouper en morceaux etait le mauvais modele : le sprite
           est dessine d un bloc, a la position 0 0, sur un element de 619 px qui
           enveloppe l icone et le texte. Le corps qui deborde est prolonge par le
           remplissage repetable de .rewardlist-item-wrapper. */
        /* Le theme fournit deux variantes de ce cadre. La generique utilise une image de
           pied de 669 x 50 px dans une boite de 667 : elle deborde a droite et remonte de
           45 px sur le contenu. La Boutique, elle, declenche par setBodyId(shop) une
           variante ou l image fait 667 x 29 px — exactement sa boite. On reprend celle-ci,
           faute de pouvoir ajouter une regle #events dans les feuilles construites. */
        #eventscomponent #buttonz {
            width: 667px;
        }

        #eventscomponent #buttonz > .content {
            padding-bottom: 12px;
        }

        #eventscomponent #buttonz .footer {
            background: url(/img/icons/04a7b39dc27c29c4c2cadd3fd44ec0.gif) no-repeat;
            width: 667px;
            height: 29px;
            bottom: -17px;
        }

        /* Le cadre passant de 670 a 667 px, la liste doit se resserrer de 1 px de chaque
           cote pour que la ligne de mission de 619 px continue de tenir. */
        #eventscomponent #rewardings .rewardlist {
            margin: 0 14px;
        }

        #rewardings .rewardheader {
            width: 619px;
            margin-bottom: 12px;
        }

        /* L image remplit le puits, comme sur la reference. Les sources du jeu ne font
           que 40x40 : l agrandissement adoucit un peu le rendu, mais un cadre a moitie
           vide s en ecartait davantage. Le theme prevoit des planches de 80px nettes
           (.sprite.ship.small, .sprite.defense.small) mais celle des batiments,
           /img/layout/resources/spriteset.png, est absente du depot : les employer ne
           couvrirait que 8 missions sur 15 et melangerait deux tailles. */
        #rewardings .rewardlist-item-icon img {
            width: 76px;
            height: 74px;
        }

        /* Coche de validation. Celle du sprite d icones ne fait que 16 px ; le theme
           embarque une marque de 46x43 pour ses messages de succes, bien plus lisible.
           Elle est bleutee a l origine, la rotation de teinte la met au vert du jeu. */
        /* Gain et coche alignes a droite et centres verticalement sur la ligne, au lieu
           du decalage fixe de 30px du theme, prevu pour un contenu d une seule ligne. */
        #rewardings .reward-claimed-text {
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #rewardings .reward-claimed-text > p {
            float: none;
            margin: 0;
        }

        #rewardings .ogx-check {
            background: url(/img/icons/7f424858dabeaec63cbbc43f1cc8d9.png) no-repeat;
            filter: hue-rotate(-65deg) saturate(1.4);
            width: 46px;
            height: 43px;
            display: block;
        }

        #rewardings .ogx-gain {
            color: #9c0;
            font-weight: 600;
        }

        #rewardings .ogx-progress {
            color: #848484;
        }

        /* Textes du panneau de rang. Ils reprennent la mise en forme des identifiants
           #welcome, #rewarddescription et #commandingstaff du theme, en classes : la page
           affiche les cinq rangs a la fois, et un identifiant ne peut pas etre repete. */
        #rewardings .ogx-welcome {
            text-align: center;
            margin: 0 0 8px 2px;
            font-weight: 600;
        }

        #rewardings .ogx-rank-text {
            color: #848484;
            text-align: center;
            margin: 3px 0 0 4px;
            font-size: 11px;
            line-height: 130%;
        }

        #rewardings .ogx-rank-header {
            text-align: center;
            margin: 12px 0 0;
            font-size: 11px;
        }

        #rewardings .ogx-staff-note {
            color: #848484;
            text-align: center;
            margin: 14px 0 0;
            font-size: 11px;
        }

        #rewardings .ogx-staff-note.active {
            color: #9c0;
        }

        /* Les deux rangees de recompenses sont declarees flex par le theme, mais avec la
           seule valeur display:-ms-flexbox — la syntaxe d Internet Explorer 10. Aucun
           navigateur actuel ne l applique, et les vignettes s empilaient donc en colonne
           au lieu de s aligner. On redeclare la valeur standard. */
        #rewardings .normalRewards,
        #rewardings .additionalRewards {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: stretch;
        }

        /* Cinq vignettes bonus dans la meme largeur : elles se partagent la place. */
        #rewardings .additionalRewards .singleReward {
            flex: 1 1 0;
            min-width: 0;
            margin: 0 3px;
            padding: 8px 4px;
        }

        /* Chaque element d une recompense : son illustration et son montant, poses cote
           a cote. Le montant est dessine sur le coin, comme dans le jeu. */
        #rewardings .ogx-parts {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px;
            margin: 6px 0 4px;
        }

        #rewardings .ogx-part {
            text-align: center;
        }

        #rewardings .ogx-part-img {
            width: 52px;
            height: 52px;
            display: block;
        }

        #rewardings .ogx-part-res {
            float: none;
            display: block;
        }

        #rewardings .ogx-part-label {
            color: #cfe3f5;
            text-align: center;
            font-size: 10px;
            line-height: 13px;
            display: block;
            margin-top: 3px;
        }

        #rewardings .ogx-badge {
            color: #d29d00;
            text-align: center;
            white-space: nowrap;
            font-weight: 600;
            display: block;
            margin-top: 2px;
        }

        #rewardings .additionalRewards .ogx-part-img {
            width: 42px;
            height: 42px;
        }

        #rewardings .additionalRewards .ogx-parts {
            gap: 4px;
        }

        #rewardings .singleReward.ogx-chosen {
            border-color: #9c0;
        }

        #rewardings .ogx-amount {
            color: #d29d00;
            font-weight: 600;
        }

        /* Bouton vert du theme, decoupe dans le meme sprite que les onglets. */
        #rewardings .tier-button {
            color: #fff;
            text-align: center;
            text-shadow: -1px 1px 5px #246a05;
            background: url(/img/icons/f5f81e8302aaad56c958c033677fb8.png) 0 -188px no-repeat;
            border: 0;
            width: 141px;
            height: 25px;
            margin: 6px auto 0;
            padding: 0;
            font-weight: 600;
            font-size: 11px;
            cursor: pointer;
            display: block;
        }

        #rewardings .tier-button:hover {
            background-position: 0 -214px;
        }

        /* Les vignettes bonus sont plus etroites : on y resserre l ecart. */
        #rewardings .additionalRewards .ogx-part {
            gap: 4px;
        }

        #rewardings .singleReward .rewardName {
            text-align: center;
        }

        /* Avertissement de perte : discret tant que l'echeance est lointaine, franc
           quand il ne reste que deux jours. */
        #rewardings .ogx-loss {
            color: #848484;
            text-align: center;
            margin: 4px 0 10px;
            font-size: 11px;
        }

        #rewardings .ogx-loss.urgent {
            color: #e74c3c;
            font-weight: 600;
        }

        #rewardings .ogx-empty {
            color: #848484;
            padding: 20px;
            text-align: center;
        }
    </style>

    <div id="eventscomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" style="background-image:url(/img/headers/rewards/rewards.jpg);height:250px;">
                    <div id="header_text">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                </div>

                <div id="buttonz">
                    <div class="header">
                        <h2>{{ __('t_ingame.events.page_title') }}</h2>
                    </div>
                    <div class="content">

                        @if (session('status'))
                            <div class="alert alert-success" style="margin: 4px 14px 12px;">{{ session('status') }}</div>
                        @endif

                        @if (session('error'))
                            <div class="alert alert-danger" style="margin: 4px 14px 12px;">{{ session('error') }}</div>
                        @endif

                        {{-- Identifiant impose par le theme : toutes les regles de cette interface
                             sont ecrites sous #rewardings. Le module JavaScript embarque du jeu
                             (Rewarding) vise les memes selecteurs et rechargerait la page par AJAX
                             vers une route inexistante, mais rien ne l'instancie : « new Rewarding »
                             est absent du bundle. Le verifier apres toute fusion amont touchant aux
                             assets construits. --}}
                        <div id="rewardings">
                            <div class="rewardlist">

                                <div class="titlebar">
                                    <button type="button" class="btn_blue ogx-tab active" data-panel="tasks">
                                        {{ __('t_ingame.events.tab_tasks') }}
                                    </button>
                                    <div class="tierlist">
                                        @foreach ($ranks as $rang)
                                            <button type="button"
                                                    class="btn_blue ogx-tab {{ $rang['reached'] ? 'reached' : '' }}"
                                                    data-panel="rank{{ $rang['rank'] }}">
                                                {{ __('t_ingame.events.rank_title', ['rank' => $rang['rank']]) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="rewardlist_wrapper">

                                    {{-- Onglet des missions du jour --}}
                                    <div class="ogx-panel" data-panel="tasks">

                                        <div class="ogx-stage-row">
                                            <div class="tritiumstage">
                                                <span class="tritiumvalue">{{ __('t_ingame.events.period', [
                                                    'start' => $start !== null ? $start->format('d/m/Y') : '',
                                                    'end' => $end !== null ? $end->format('d/m/Y') : '',
                                                ]) }}</span>
                                            </div>
                                            <div class="tritiumstage playervalue">
                                                <span class="tritiumvalue">{{ __('t_ingame.events.you_have', [
                                                    'tritium' => number_format($tritium, 0, ',', ' '),
                                                ]) }}</span>
                                                <span class="tritiumicon"></span>
                                            </div>
                                        </div>

                                        <p class="ogx-loss {{ $daysLeft <= 2 ? 'urgent' : '' }}">
                                            @if ($daysLeft <= 2)
                                                {{ __('t_ingame.events.loss_warning_urgent', ['days' => $daysLeft]) }}
                                            @else
                                                {{ __('t_ingame.events.loss_warning') }}
                                            @endif
                                        </p>

                                        @forelse ($groupes as $valeur => $groupe)
                                            <div class="tritiumstage">
                                                <span class="tritiumvalue">{{ __('t_ingame.events.tritium_value', [
                                                    'tritium' => number_format($valeur, 0, ',', ' '),
                                                ]) }}</span>
                                                <span class="tritiumicon"></span>
                                            </div>

                                            @foreach ($groupe as $mission)
                                                <div class="rewardlist-item {{ $mission['done'] ? 'rewarded' : '' }}">
                                                    {{-- .rewardheader porte le cadre entier en une seule image : puits
                                                         d'icone, bande de titre et biseau. Il enveloppe donc l'icone ET
                                                         le texte, comme .rewardlistimg sur la page Recompenses. --}}
                                                    <div class="rewardheader">
                                                        <div class="rewardlist-item-icon">
                                                            <img src="/img/{{ $mission['icon'] }}"
                                                                 alt="{{ __('t_ingame.events.mission_' . $mission['key'] . '_name') }}">
                                                        </div>
                                                        <div class="rewardlist-item-text">
                                                            <h3>{{ __('t_ingame.events.mission_' . $mission['key'] . '_name') }}</h3>

                                                            @if ($mission['done'])
                                                                <div class="reward-claimed-text">
                                                                    <p class="ogx-gain">+{{ number_format($mission['tritium'], 0, ',', ' ') }}</p>
                                                                    <span class="ogx-check"></span>
                                                                </div>
                                                            @endif

                                                            <div class="rewardlist-item-wrapper">
                                                                <p>{{ __('t_ingame.events.mission_' . $mission['key'], [
                                                                    'target' => number_format($mission['target'], 0, ',', ' '),
                                                                ]) }}</p>

                                                                @if ($mission['target'] > 1)
                                                                    <p class="ogx-progress">{{ __('t_ingame.events.progress', [
                                                                        'progress' => number_format($mission['progress'], 0, ',', ' '),
                                                                        'target' => number_format($mission['target'], 0, ',', ' '),
                                                                    ]) }}</p>
                                                                @endif
                                                            </div>
                                                            <div class="rewardlist-item-bottom"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        @empty
                                            <p class="ogx-empty">{{ __('t_ingame.events.no_mission') }}</p>
                                        @endforelse
                                    </div>

                                    {{-- Un onglet par rang --}}
                                    @foreach ($ranks as $rang)
                                        <div class="ogx-panel" data-panel="rank{{ $rang['rank'] }}" style="display:none;">

                                            <p class="ogx-welcome">{{ __('t_ingame.events.rank_welcome') }}</p>
                                            <p class="ogx-rank-text">{{ __('t_ingame.events.rank_hint') }}</p>
                                            <p class="ogx-rank-header">{!! __('t_ingame.events.rank_progress', [
                                                'tritium' => '<span class="ogx-amount">' . number_format($tritium, 0, ',', ' ') . '</span>',
                                                'threshold' => number_format($rang['threshold'], 0, ',', ' '),
                                            ]) !!}</p>

                                            <div class="normalRewards">
                                                @foreach ($rang['rewards'] as $rewardKey => $reward)
                                                    <div class="singleReward {{ $rang['claimed'] && $rang['chosen'] === $rewardKey ? 'ogx-chosen' : '' }}">
                                                        <span class="rewardName">{{ __('t_ingame.events.reward_' . $rewardKey) }}</span>

                                                        @include('ingame.events.partials.reward-visual', ['detail' => $reward['detail']])

                                                        @if ($rang['claimed'])
                                                            @if ($rang['chosen'] === $rewardKey)
                                                                <span class="ogx-gain">{{ __('t_ingame.events.your_choice') }}</span>
                                                            @endif
                                                        @elseif ($rang['reached'])
                                                            <form action="{{ route('events.claim-rank') }}" method="post">
                                                                {{ csrf_field() }}
                                                                <input type="hidden" name="rank" value="{{ $rang['rank'] }}"/>
                                                                <input type="hidden" name="reward" value="{{ $rewardKey }}"/>
                                                                <button type="submit" class="tier-button ogx-choose">{{ __('t_ingame.events.choose') }}</button>
                                                            </form>
                                                        @else
                                                            <span class="ogx-progress">{{ __('t_ingame.events.rank_locked') }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>

                                            <p class="ogx-staff-note {{ $staffActive ? 'active' : '' }}">
                                                {{ $staffActive
                                                    ? __('t_ingame.events.bonus_active')
                                                    : __('t_ingame.events.bonus_inactive') }}
                                            </p>

                                            <div class="additionalRewards">
                                                @foreach ($rang['bonus'] as $bonus)
                                                    <div class="singleReward">
                                                        @include('ingame.events.partials.reward-visual', ['detail' => $bonus['detail']])
                                                    </div>
                                                @endforeach
                                            </div>

                                            <p class="ogx-rank-text">{{ __('t_ingame.events.planet_warning', ['planet' => $currentPlanetName]) }}</p>
                                            <p class="ogx-loss {{ $daysLeft <= 2 ? 'urgent' : '' }}">{{ __('t_ingame.events.loss_warning') }}</p>
                                        </div>
                                    @endforeach

                                </div>
                            </div>
                        </div>

                        <div class="footer"></div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        $(document).ready(function () {
            // Bascule d'onglets, entierement cote client : les six panneaux sont rendus d'un
            // coup par le serveur, changer d'onglet ne recharge rien.
            $('#rewardings .ogx-tab').on('click', function () {
                var panneau = $(this).data('panel');

                $('#rewardings .ogx-tab').removeClass('active');
                $(this).addClass('active');

                $('#rewardings .ogx-panel').hide();
                $('#rewardings .ogx-panel[data-panel="' + panneau + '"]').show();
            });

            // Le choix d'une recompense de rang est definitif : on le fait confirmer.
            $('#rewardings .ogx-choose').on('click', function () {
                return confirm(@json(__('t_ingame.events.confirm_choice')));
            });
        });
    </script>

@endsection
