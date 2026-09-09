<?php

namespace OGame\Alliance;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Services\AllianceService;
use OGame\Services\SettingsService;

/**
 * Aucune offensive entre membres d une meme alliance.
 *
 * ## La regle, et d ou elle vient
 *
 * Plan approuve du 9 septembre 2026, section 2 : « Bloquer les ordres offensifs contre tout bien
 * d un membre de la meme alliance : planete, lune, flotte/patrouille, missiles, destruction de lune
 * et groupes ACS. » Elle n existait pas : rien n empechait d attaquer la planete d un membre de sa
 * propre alliance, ni au lancement, ni a l arrivee.
 *
 * ## Un seul decideur, appele a deux moments — et une seule autorite
 *
 * Le plan exige les deux — « Controles au lancement et a l arrivee avant ouverture/admission, avec
 * garantie sous transaction a l endroit ou l action devient effective. Ne pas se contenter de
 * boutons grises. » Les deux moments appellent donc **cette** classe, jamais deux regles ecrites
 * separement : elles divergeraient au premier changement, et le lancement dirait non quand
 * l arrivee dirait oui.
 *
 * Les deux moments n ont pas le meme poids. `forbids()` repond hors de toute protection : au
 * lancement, ou aucun combat ne s ouvre encore, et comme voie rapide ailleurs. `forbidsUnderTheRendezvous()`
 * repond **la ou l action devient effective**, sous le rendez-vous des joueurs, et c est elle qui fait
 * autorite : un « pas allies » rendu par la premiere ne dispense jamais de la seconde.
 *
 * ## Ce qu elle ne decide pas
 *
 * Ni le camp d une bataille ouverte, ni l admissibilite d un renfort : ceux-la sont **figes a
 * l ouverture** et ne se relisent pas. Un allie qui rejoint apres coup n entre pas
 * retroactivement, et un depart ne change aucun camp.
 *
 * ## L interrupteur
 *
 * `alliance_offensive_protection_enabled`, a zero par defaut. Tant qu il vaut non, la regle
 * n existe pas et le jeu se comporte exactement comme avant — pas une lecture d alliance de plus.
 */
final class AllianceOffensiveGuard
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AllianceService $alliances,
    ) {
    }

    /**
     * Cette offensive est-elle interdite parce qu elle vise un membre de la meme alliance ?
     *
     * @param CombatMissionKind $kind le genre de l ordre — un genre non offensif n est jamais refuse
     * @param int $attackerId celui qui lance
     * @param int|null $targetOwnerId le proprietaire du bien vise, ou null s il n en a pas
     */
    public function forbids(CombatMissionKind $kind, int $attackerId, int|null $targetOwnerId): bool
    {
        if ($targetOwnerId === null || !$this->questionArises($kind, $attackerId, $targetOwnerId)) {
            return false;
        }

        return $this->alliances->arePlayersInSameAlliance($attackerId, $targetOwnerId);
    }

    /**
     * La meme regle, mais lue **la ou l offensive devient effective**, sous le rendez-vous.
     *
     * ## Le defaut que cette methode ferme, mesure sur MariaDB
     *
     * `forbids()` lit l appartenance **hors de toute protection**. Une course reelle du bac
     * (`AllianceVersusCombatOpeningRaceTest`) l a prise en faute : l arrivee lisait « pas allies »,
     * l adhesion prenait sa barriere, ecrivait et commitait, puis l arrivee prenait la barriere a son
     * tour et ouvrait le combat sur sa lecture d avant. **L action etait protegee, la decision non.**
     *
     * ## Pourquoi une lecture verrouillante, et pas une simple relecture
     *
     * Relire sous la barriere ne suffirait pas. Sous `REPEATABLE READ`, une lecture ordinaire rend le
     * monde tel qu il etait a la **premiere** lecture ordinaire de la transaction — et la porte des
     * mouvements en fait une avant de prendre le rendez-vous, pour savoir de quels joueurs il s agit.
     * Une relecture ordinaire, meme placee apres la barriere, rendrait donc la meme reponse perimee.
     * Seule une lecture verrouillante voit la derniere version commitee.
     *
     * ## Pourquoi `alliance_members`, et jamais `users`
     *
     * Verrouiller la ligne du compte fermerait le cycle que `PlayerCoordinationBarrier` existe pour
     * eviter — c est la raison pour laquelle `forbidsUnderLock()` a ete retiree (voir plus bas).
     * `alliance_members` porte la meme verite : les cinq chemins qui changent une appartenance
     * (fondation, admission, depart, exclusion, dissolution) ecrivent la ligne **et**
     * `users.alliance_id` dans une seule transaction, et `AllianceMembershipMirrorsTheMemberRowTest`
     * l exige route par route. Aucun autre chemin du jeu ne verrouille cette table.
     *
     * L unicite de `user_id` compte : sur un index unique, l egalite sur une ligne **absente** pose un
     * verrou d intervalle — c est ce qui empeche l adhesion de s inserer derriere la decision.
     *
     * @param CombatMissionKind $kind le genre de l ordre — un genre non offensif n est jamais refuse
     * @param int $attackerId celui qui arrive
     * @param int|null $targetOwnerId le proprietaire du corps vise, ou null s il n en a pas
     */
    public function forbidsUnderTheRendezvous(CombatMissionKind $kind, int $attackerId, int|null $targetOwnerId): bool
    {
        if ($targetOwnerId === null || !$this->questionArises($kind, $attackerId, $targetOwnerId)) {
            return false;
        }

        return $this->sameAllianceUnderTheRendezvous($attackerId, $targetOwnerId);
    }

    /**
     * La question se pose-t-elle seulement ? Les deux moments repondent par les memes exclusions.
     */
    private function questionArises(CombatMissionKind $kind, int $attackerId, int $targetOwnerId): bool
    {
        if (!$this->settings->allianceOffensiveProtectionEnabled()) {
            return false;
        }

        if (!$kind->isOffensiveAgainstAnotherPlayer()) {
            return false;
        }

        // Se viser soi-meme n est pas une affaire d alliance : d autres regles le refusent deja, et
        // repondre « votre alliance » ici serait faux.
        return $targetOwnerId !== $attackerId;
    }

    /**
     * L appartenance effective des deux joueurs, lue ligne par ligne et par identifiant croissant.
     *
     * L ordre est celui du rendez-vous, et pour la meme raison : deux chemins qui prendraient les
     * memes deux lignes dans deux ordres differents s interbloqueraient.
     */
    private function sameAllianceUnderTheRendezvous(int $attackerId, int $targetOwnerId): bool
    {
        $joueurs = [$attackerId, $targetOwnerId];
        sort($joueurs);

        $adhesions = [];

        foreach ($joueurs as $joueur) {
            $ligne = DB::table('alliance_members')->where('user_id', $joueur)->lockForUpdate()->first();

            $adhesions[] = $ligne === null ? null : (int)$ligne->alliance_id;
        }

        // Sans alliance, pas de protection : deux joueurs sans ligne ne sont pas « de la meme ».
        return $adhesions[0] !== null && $adhesions[0] === $adhesions[1];
    }

    /*
     * ## Ou la coordination vit, et pourquoi pas ici
     *
     * Une variante `forbidsUnderLock()` a existe dans cette classe : elle verrouillait les lignes
     * `users` des deux combattants. **Elle a ete retiree parce qu elle inversait un ordre.** Mesure
     * faite : `PlayerService::update()` verrouille le compte **puis** les planetes, a presque chaque
     * page ; `updateFleetMissions()` verrouille les planetes **puis** les missions. Deux requetes
     * concurrentes du meme joueur — deux onglets — fermaient le cycle.
     *
     * La coordination vit desormais dans `PlayerCoordinationBarrier`, sur une table que rien d autre
     * ne verrouille, prise **en tete** par la porte des mouvements, le chemin administratif et
     * l adhesion. Le rendez-vous est donc deja tenu quand on appelle cette classe.
     *
     * Elle prend malgre tout un verrou, et un seul : la ligne d `alliance_members` de chacun des deux
     * joueurs, dans `sameAllianceUnderTheRendezvous()`. Ce n est pas une coordination — c est la seule
     * facon de lire la derniere version commitee sous `REPEATABLE READ`. La distinction avec la
     * variante retiree tient en un mot : **`alliance_members`, jamais `users`**, et
     * `AllianceOffensiveProtectionTest::testTheDecisionUnderTheRendezvousNeverLocksAnAccountRow`
     * l epingle, faute de quoi le cycle se refermerait sans que personne ne le voie.
     */
    /**
     * La clef du message que le joueur lira.
     *
     * **Elle ne revele rien qu il ne sache deja.** Le proprietaire d une planete est public dans la
     * Galaxie, et l appartenance a sa propre alliance l est aussi : dire « c est un membre de votre
     * alliance » n apprend donc rien. Une cible dont l identite n est PAS publique — une patrouille
     * detectee — passe par une porte qui verifie la detection **avant** toute protection, pour que
     * le refus ne devienne pas un detecteur gratuit.
     */
    public function reason(): string
    {
        return 't_ingame.alliance.refusal_offensive_against_a_member';
    }
}
