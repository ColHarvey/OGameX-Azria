<?php

namespace OGame\Lifeforms\Combat;

use OGame\Combat\Exceptions\UnknownAdmissionHistory;
use OGame\Combat\Support\FrozenLifeformCombatBonuses;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Bonuses\LifeformBonusSet;
use OGame\Lifeforms\Catalogue\LifeformEffect;
use OGame\Lifeforms\Catalogue\LifeformKind;
use OGame\Lifeforms\Demography\LifeformDemography;
use OGame\Lifeforms\Demography\PlanetLifeformProfile;
use OGame\Lifeforms\LifeformHistoryUnavailable;
use OGame\Lifeforms\Rules\LifeformRuleRevisions;
use OGame\Lifeforms\Services\LifeformLevels;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformPlanet;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Photographie ce que les formes de vie apportent a un combat, **au moment ou on le lui demande** :
 * a l admission d une flotte, a l ouverture d un ralliement, a l arrivee d une attaque instantanee.
 *
 * ## Tout ce qui est photographie est ramene a l instant demande
 *
 * Un travailleur traite une arrivee bien apres l avoir datee, et ce que le joueur change entre les deux ne
 * doit ni armer ni desarmer cette bataille. Les **niveaux** reviennent par la file des travaux
 * (`LifeformLevels::levelsAt()`), l**occupation des emplacements** par son historique
 * (`LifeformSlotHistory`). Le gel qui suit fait le reste : rien n est relu pendant que la bataille dure.
 *
 * L**experience** revient par les vols de decouverte deja regles, et la **population** — celle qui decide qu un
 * emplacement est ouvert — se rejoue depuis l etat d ou le dernier passage de la planete est parti.
 *
 * ## Quand la population de l instant n est pas etablissable
 *
 * L etat garde ne couvre qu un passage : au-dela, l instant demande n est pas reconstituable. Cette
 * photographie **leve alors `UnknownAdmissionHistory`** au lieu de rendre une valeur. Ni zero, ni la valeur
 * courante : l une desarmerait la flotte, l autre l armerait, et un avertissement au journal ne rendrait
 * aucune des deux juste. Le socle des combats connait deja cette conduite pour un historique de classe
 * manquant — arrivee suspendue, aucun reglement partiel, pages du joueur intactes, raison explicite — et
 * c est elle qui s applique ici (journal §155.12).
 */
final class LifeformCombatPhotographer
{
    public function __construct(
        private readonly LifeformBonusResolver $resolver,
        private readonly LifeformLevels $levels,
        private readonly LifeformRuleRevisions $revisions,
        private readonly LifeformDemography $demography,
    ) {
    }

    /**
     * Ce qu une flotte de ce joueur apporte a ses tirs : ses unites, rien du corps.
     *
     * `$at` est l instant d admission : les niveaux y sont **ramenes par la file des travaux**, pour qu une
     * recherche achevee entre l arrivee et son traitement n arme pas cette flotte (revue de Codex, §155.9).
     *
     * @throws UnknownAdmissionHistory quand l etat de cet instant ne peut pas etre etabli
     */
    public function ofPlayer(PlayerService $player, int|null $at = null): FrozenLifeformCombatBonuses
    {
        $bonus = $this->orSuspend(fn (): LifeformBonusSet => $this->resolver->forPlayer($player->getId(), $at), 'le compte ' . $player->getId());

        return new FrozenLifeformCombatBonuses($this->unitStatsOf($bonus), null, 0.0, 0.0, 0.0);
    }

    /**
     * Ce qu un corps apporte a sa defense : les unites de son proprietaire, et — pour une planete qui
     * porte une forme de vie — la protection de sa population, la lune, les debris et les epaves.
     *
     * `$at` est l instant photographie : le Bouclier planetaire, la lune, les debris et les epaves y sont
     * **ramenes** comme les unites le sont. Un Bouclier acheve entre l ouverture d un ralliement et le
     * passage du travailleur sauvait sinon une population qu il ne couvrait pas encore (revue de Codex).
     *
     * @throws UnknownAdmissionHistory quand l etat de cet instant ne peut pas etre etabli
     */
    public function ofBody(PlanetService $body, int|null $at = null): FrozenLifeformCombatBonuses
    {
        $proprietaire = $body->getPlayer();
        $quoi = 'le corps ' . $body->getPlanetId();
        $unites = $proprietaire === null
            ? []
            : $this->unitStatsOf($this->orSuspend(fn (): LifeformBonusSet => $this->resolver->forPlayer($proprietaire->getId(), $at), $quoi));
        if (!$body->isPlanet()) {
            // Une lune ne porte aucune forme de vie : ni population, ni lune, ni debris. Mais ses epaves se reparent au
            // chantier spatial de SA planete, que le moteur emprunte deja (`BattleEngine::calculateWreckField()`), et
            // l attaquant qui part d une lune emprunte deja les Nano-robots de la planete
            // (`LiveCombatApplicationContext::wreckRecoveryBonusFor()`) : le defenseur sur sa lune fait de meme (§164).
            $planete = $body->isMoon() ? $body->planet() : null;
            $epaves = $planete === null || !$planete->isPlanet()
                ? 0.0
                : $this->orSuspend(fn (): LifeformBonusSet => $this->resolver->forPlanet($planete->getPlanetId(), $at), $quoi)->fraction(LifeformEffect::WRECK_RECOVERY);

            return new FrozenLifeformCombatBonuses($unites, null, 0.0, 0.0, $epaves);
        }
        $etat = LifeformPlanet::query()->where('planet_id', $body->getPlanetId())->first();
        if ($etat === null) {
            return new FrozenLifeformCombatBonuses($unites, null, 0.0, 0.0, 0.0);
        }
        // **Un corps peuple dont la population de cet instant est inconnue n entre pas dans une bataille.** Ses pertes
        // civiles se prennent sur cette population (`LifeformCombatLosses`) : un reglement qui ne la connait pas
        // suspendrait de toute facon, et il vaut mieux le dire a l admission, quand rien n est encore gele. La
        // lecture des bonus, elle, ne reclame plus cette population quand aucune technologie n est posee (§173) :
        // la garantie vit donc ici, ou elle a toujours eu son sens.
        if ($at !== null && $this->demography->stateAt($etat, $at) === null) {
            throw new UnknownAdmissionHistory(
                'La population du corps ' . $body->getPlanetId() . ' a l instant ' . $at . ' n est pas reconstituable : '
                . 'les pertes civiles de cette bataille ne pourraient pas etre prises.'
            );
        }
        $niveaux = $at === null
            ? $this->levels->buildingLevelsOf($body->getPlanetId())
            : $this->levels->levelsAt($body->getPlanetId(), LifeformKind::Building, $at);
        $profil = PlanetLifeformProfile::fromLevels(Species::from((int)$etat->species), $niveaux, $this->revisions->live()->demography());
        $planete = $this->orSuspend(fn (): LifeformBonusSet => $this->resolver->forPlanet($body->getPlanetId(), $at), $quoi);

        return new FrozenLifeformCombatBonuses(
            $unites,
            max(0.0, min(1.0, $profil->protectedShare)),
            $planete->fraction(LifeformEffect::MOON_CHANCE),
            $planete->fraction(LifeformEffect::DEBRIS_RECOVERY),
            $planete->fraction(LifeformEffect::WRECK_RECOVERY),
        );
    }

    /**
     * Traduit un etat de formes de vie introuvable en l anomalie que le socle des combats sait suspendre.
     *
     * La photographie ne decide de rien : elle dit « je ne sais pas », et le chemin d arrivee ou d ouverture
     * fait ce qu il fait deja pour un historique de classe manquant. La conversion vit ici, a la frontiere,
     * pour que le socle n ait rien a apprendre des formes de vie.
     *
     * @param callable(): LifeformBonusSet $lecture
     * @throws UnknownAdmissionHistory
     */
    private function orSuspend(callable $lecture, string $quoi): LifeformBonusSet
    {
        try {
            return $lecture();
        } catch (LifeformHistoryUnavailable $manque) {
            throw new UnknownAdmissionHistory(
                $quoi . ' ne peut pas etre gele a son admission : ' . $manque->getMessage(),
                0,
                $manque
            );
        }
    }

    /**
     * @return array<string, float>
     */
    private function unitStatsOf(LifeformBonusSet $bonus): array
    {
        $unites = [];
        // Le pour cent se pose en millioniemes exacts, comme la lecture vivante (`PlayerService::getLifeformUnitStatsPercent()`,
        // journal §157) : 0,3 x 3 / 100 x 100 vaut 0,8999999999999999, et le combat durable tirait un point sous l attaque
        // instantanee et sous l infobulle (audit des bonus, journal §164).
        foreach (ObjectService::getShipObjects() as $vaisseau) {
            $pourcent = round($bonus->fraction(LifeformEffect::SHIP_STATS, $vaisseau->machine_name) * 100, 6);
            if ($pourcent > 0) {
                $unites[$vaisseau->machine_name] = $pourcent;
            }
        }
        $defenses = round($bonus->fraction(LifeformEffect::DEFENCE_STATS) * 100, 6);
        if ($defenses > 0) {
            $unites[FrozenLifeformCombatBonuses::DEFENCE] = $defenses;
        }

        return $unites;
    }
}
