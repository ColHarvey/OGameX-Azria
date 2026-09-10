{{--
    La seconde fonction du chantier spatial : reparer les survivants endommages, contre paiement.

    Ce bloc n existe **que** si le serveur a rendu `$hullRepair` — c est-a-dire si l interrupteur
    `hull_damage_enabled` est arme. Un bloc « rien a reparer » affiche sur un univers ou la fonction
    n existe pas annoncerait une fonctionnalite absente.

    **Rien ici n est calcule par le navigateur.** Le devis, la duree, l avancement et le remboursement
    viennent du serveur : un prix compose cote client serait un prix que le joueur pourrait choisir.
--}}
@if (!empty($hullRepair))
    <div class="content-box-s" id="hullRepairBox">
        <div class="header">
            <h3>{{ __('t_facilities.hull_repair.title') }}</h3>
        </div>
        <div class="content">
            @if ($hullRepair['order'] !== null)
                {{-- Une reparation court : on montre ou elle en est, et ce qu une annulation rendrait. --}}
                <p>{{ __('t_facilities.hull_repair.in_progress', ['percent' => $hullRepair['order']['progress_percent']]) }}</p>

                <ul class="hullRepairHeld">
                    @foreach ($hullRepair['order']['units'] as $tenue)
                        <li>{{ $tenue['count'] }} &times; {{ $tenue['title'] }}</li>
                    @endforeach
                </ul>

                <p class="hullRepairCountdown"
                   data-seconds-remaining="{{ $hullRepair['order']['seconds_remaining'] }}">
                    <span id="hullRepairTimer">{{ $hullRepair['order']['seconds_remaining'] }}</span>
                </p>

                <p class="hullRepairRefund">
                    {{ __('t_facilities.hull_repair.refund_notice') }}
                    ({{ $hullRepair['order']['refund_if_cancelled_now']['metal'] }} /
                    {{ $hullRepair['order']['refund_if_cancelled_now']['crystal'] }} /
                    {{ $hullRepair['order']['refund_if_cancelled_now']['deuterium'] }})
                </p>

                <button type="button" id="hullRepairCancel" class="btn_blue">
                    {{ __('t_facilities.hull_repair.cancel') }}
                </button>
            @elseif ($hullRepair['unavailable_because'] !== null)
                {{-- **La raison d un bouton indisponible est une donnee.** Un bouton grise sans
                     explication oblige le joueur a deviner. --}}
                <p class="hullRepairUnavailable">
                    {{ __('t_facilities.hull_repair.refused.' . $hullRepair['unavailable_because']) }}
                </p>
            @else
                <p>{{ __('t_facilities.hull_repair.intro') }}</p>

                <ul class="hullRepairFleet">
                    @foreach ($hullRepair['fleet'] as $ligne)
                        <li>
                            {{-- « 20 croiseurs, dont 8 endommages » : le libelle exact demande. --}}
                            <b>{{ __('t_facilities.hull_repair.fleet_line', [
                                'total' => $ligne['total'],
                                'vaisseau' => $ligne['title'],
                                'damaged' => $ligne['damaged'],
                            ]) }}</b>

                            {{-- **Le detail par palier, jamais une moyenne.** « 82 % en moyenne »
                                 decrirait une flotte qui n existe pas : douze intacts et huit a
                                 moitie detruits ne font pas vingt vaisseaux a 82 %. --}}
                            <ul class="hullRepairLevels">
                                @foreach ($ligne['levels'] as $palier)
                                    <li data-damage="{{ $palier['damage_basis_points'] }}"
                                        data-machine-name="{{ $ligne['machine_name'] }}"
                                        data-count="{{ $palier['count'] }}">
                                        {{ __('t_facilities.hull_repair.level_line', [
                                            'count' => $palier['count'],
                                            'percent' => $palier['hull_percent'],
                                        ]) }}
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>

                <button type="button" id="hullRepairQuote" class="btn_blue">
                    {{ __('t_facilities.hull_repair.quote') }}
                </button>

                <div id="hullRepairQuoteResult" hidden>
                    <p class="hullRepairCost"></p>
                    <button type="button" id="hullRepairStart" class="btn_blue">
                        {{ __('t_facilities.hull_repair.start') }}
                    </button>
                </div>
            @endif
        </div>
        <div class="footer"></div>
    </div>

    <script type="text/javascript">
        // **Ce script vit dans le gabarit, pas dans `resources/js`.** Un fichier de `resources/js`
        // exige `npm run build`, impossible depuis le poste de l administrateur : il faudrait pousser,
        // attendre le workflow, reprendre l artefact et commiter le bundle. Pour une centaine de
        // lignes qui ne servent qu a cette page, le gabarit est le bon endroit — Blade n est pas
        // compile par Vite, donc ce code est servi tel quel.
        (function () {
            var routes = {
                quote: @json(route('facilities.hullrepairquote')),
                start: @json(route('facilities.hullrepairstart')),
                cancel: @json(route('facilities.hullrepaircancel')),
            };

            // Les libelles traduits viennent du serveur : une chaine ecrite ici en dur serait
            // anglaise pour un lecteur francais, et personne ne s en apercevrait avant la production.
            var loca = {
                cost: @json(__('t_facilities.hull_repair.quote')),
                refused: @json(__('t_facilities.hull_repair.refused.units_gone')),
            };

            var jeton = document.querySelector('meta[name="csrf-token"]');
            var enTetes = {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': jeton ? jeton.getAttribute('content') : '',
            };

            // La selection : tout ce que la page montre comme endommage. Les paliers sont lus dans
            // le DOM que le serveur a rendu, jamais recomposes ici.
            function selectionCourante() {
                var unites = {};

                document.querySelectorAll('#hullRepairBox .hullRepairLevels li').forEach(function (ligne) {
                    var nom = ligne.getAttribute('data-machine-name');
                    var degats = ligne.getAttribute('data-damage');
                    var nombre = parseInt(ligne.getAttribute('data-count'), 10);

                    if (!nom || !degats || !nombre) {
                        return;
                    }

                    unites[nom] = unites[nom] || {};
                    unites[nom][degats] = (unites[nom][degats] || 0) + nombre;
                });

                return unites;
            }

            function envoyer(url, corps) {
                return fetch(url, {
                    method: 'POST',
                    headers: enTetes,
                    credentials: 'same-origin',
                    body: JSON.stringify(corps),
                }).then(function (reponse) {
                    return reponse.json().catch(function () {
                        return { success: false, reason: 'hull_repair.refused.body_gone' };
                    });
                });
            }

            var devisEnCours = null;

            var boutonDevis = document.getElementById('hullRepairQuote');
            if (boutonDevis) {
                boutonDevis.addEventListener('click', function () {
                    boutonDevis.disabled = true;

                    envoyer(routes.quote, { units: selectionCourante() }).then(function (reponse) {
                        boutonDevis.disabled = false;

                        var bloc = document.getElementById('hullRepairQuoteResult');
                        var texte = document.querySelector('#hullRepairBox .hullRepairCost');

                        if (!reponse.success) {
                            texte.textContent = reponse.reason || loca.refused;
                            bloc.hidden = false;
                            return;
                        }

                        // **L empreinte est conservee telle quelle** : c est elle que la confirmation
                        // renvoie, et c est ce qui empeche de payer un prix jamais montre si le monde
                        // a bouge entre l affichage et le clic.
                        devisEnCours = reponse;

                        texte.textContent = loca.cost + ' : '
                            + reponse.cost.metal + ' / ' + reponse.cost.crystal + ' / ' + reponse.cost.deuterium
                            + ' — ' + Math.round(reponse.duration_seconds / 60) + ' min';

                        bloc.hidden = false;
                    });
                });
            }

            var boutonLancer = document.getElementById('hullRepairStart');
            if (boutonLancer) {
                boutonLancer.addEventListener('click', function () {
                    if (!devisEnCours) {
                        return;
                    }

                    boutonLancer.disabled = true;

                    envoyer(routes.start, {
                        units: selectionCourante(),
                        fingerprint: devisEnCours.fingerprint,
                    }).then(function (reponse) {
                        if (reponse.success) {
                            window.location.reload();
                            return;
                        }

                        boutonLancer.disabled = false;
                        document.querySelector('#hullRepairBox .hullRepairCost').textContent =
                            reponse.reason || loca.refused;
                    });
                });
            }

            var boutonAnnuler = document.getElementById('hullRepairCancel');
            if (boutonAnnuler) {
                boutonAnnuler.addEventListener('click', function () {
                    boutonAnnuler.disabled = true;

                    envoyer(routes.cancel, {}).then(function () {
                        window.location.reload();
                    });
                });
            }

            // Le compte a rebours : des **secondes restantes**, jamais un instant absolu — la meme
            // regle que le panneau de combat.
            var minuteur = document.getElementById('hullRepairTimer');
            if (minuteur) {
                var restant = parseInt(
                    document.querySelector('#hullRepairBox .hullRepairCountdown').getAttribute('data-seconds-remaining'),
                    10
                );

                var tic = window.setInterval(function () {
                    restant -= 1;

                    if (restant <= 0) {
                        window.clearInterval(tic);
                        window.location.reload();
                        return;
                    }

                    var heures = Math.floor(restant / 3600);
                    var minutes = Math.floor((restant % 3600) / 60);
                    var secondes = restant % 60;

                    minuteur.textContent = (heures > 0 ? heures + 'h ' : '')
                        + (minutes < 10 ? '0' : '') + minutes + 'm '
                        + (secondes < 10 ? '0' : '') + secondes + 's';
                }, 1000);
            }
        })();
    </script>
@endif
