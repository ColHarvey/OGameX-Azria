<?php

namespace OGame\Combat\Decisions;

use LogicException;
use OGame\Combat\Enums\CombatMissionKind;
use OGame\Combat\Enums\CombatReasonCode;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Enums\FlightLeg;
use OGame\Combat\Enums\InvariantCode;
use OGame\Combat\Enums\SnapshotObligation;
use OGame\Combat\Enums\TargetScope;
use OGame\Combat\Support\ReturnPlan;

/**
 * La regle d'arrivee, pour chacune des 396 situations possibles.
 *
 * ## L'erreur que cette classe existe pour ne pas commettre
 *
 * Une premiere version differait **tout** pendant `Resolving`, puis rejouait l'evenement tel quel.
 * Il retombait alors sur un corps devenu libre, et une attaque tardive y ouvrait un second combat :
 * exactement la file d'attente que le jeu refuse. Le report ne suspend donc jamais la decision, il
 * en differe seulement l'application — `deferUntilResolved()` **exige** sa continuation.
 *
 * La meme erreur se represente sous une autre forme : un worker en retard qui lirait l'etat
 * **courant** de la cible. `CombatSituation::$targetState` porte l'etat de **l'heure planifiee** de
 * l'evenement ; une attaque prevue pendant la bataille reste une attaque tardive, meme traitee une
 * heure apres la fin.
 *
 * ## Quatre facons pour une case d'etre tranchee
 *
 * Trois d'entre elles ne rendent pas une action immediate, et ce sont quand meme des decisions :
 *
 *     action immediate              -> la matrice sait, et dit
 *     SelectBy...Admission          -> le selecteur collectif du camp tranche, sous verrou
 *     SelectByEventOrder            -> l'ordre des evenements tranche, pas l'etat courant
 *     OutsideMatrixDomain           -> cette cible n'est pas un corps celeste, ou n'existe pas
 *
 * Une delegation ne vaut comme tranchee que si son consommateur existe et traite **exhaustivement**
 * ses resultats ; sans cela elle n'est qu'un trou sous un autre nom. `OpenCellCategory` les separe
 * du seul vrai trou, `MissingRule`.
 *
 * ## Pourquoi `JoinAttack` couvre aussi l'ouverture
 *
 * `CombatMissionAction` nomme **le camp que la flotte prend**, pas l'effet de bord sur l'instance.
 * Qu'une attaque ouvre le ralliement ou rejoigne celui d'une autre ne change rien a ce qui arrive
 * a la flotte. Ouvrir ou rejoindre se deduit de l'etat de la cible, et c'est l'orchestrateur qui le
 * fait — pas une action distincte.
 *
 * ## Ce que cette matrice ne dit pas
 *
 * Elle ne decrit que **le mouvement**. Ce qui entre dans la photographie releve de
 * `SnapshotDecision`, et les deux barrieres temporelles — engagement irrevocable avant l'ouverture,
 * effet planifie strictement avant la fermeture, une egalite comptant pour « apres » — s'y
 * appliquent. Une flotte peut se poser sans figurer dans la photo, et y figurer sans que son
 * mouvement change.
 *
 * Elle ne dit pas non plus ce qui a le droit de **partir**. Tout lancement visant un corps deja
 * verrouille est refuse cote serveur ; les arrivees ci-dessous couvrent les missions deja en vol et
 * les courses transactionnelles, jamais une permission de lancer apres l'ouverture.
 */
final class CombatDecisionMatrix
{
    /**
     * Ce qu'il advient d'une flotte qui arrive.
     *
     * @param CombatSituation $situation
     * @param ReturnPlan $returnPlan Ou la flotte se poserait si elle devait faire demi-tour. Il est
     *                               exige meme quand la case ne renvoie personne : le calculer sous
     *                               verrou avant de decider evite qu'une destination change entre la
     *                               decision et son application.
     * @return ArrivalDecision
     */
    public function verdictOf(CombatSituation $situation, ReturnPlan $returnPlan): ArrivalVerdict
    {
        $mouvement = $this->movementOf($situation, $returnPlan);

        return new ArrivalVerdict(
            $mouvement,
            $this->snapshotObligationOf($situation, $mouvement, $returnPlan)
        );
    }

    /**
     * Ce que devient la flotte, sans rien dire de la photographie.
     *
     * **Privee, et c'est le point.** Un appelant qui obtiendrait le mouvement seul pourrait lire
     * `AllowNormally` et conclure que rien d'autre ne reste a decider — puis inclure ou exclure la
     * flotte de son propre chef. Le verdict oblige a recevoir les deux.
     *
     * @param CombatSituation $situation
     * @param ReturnPlan $returnPlan
     * @return ArrivalDecision
     */
    private function movementOf(CombatSituation $situation, ReturnPlan $returnPlan): ArrivalDecision
    {
        // Une case qui ne peut pas exister se range plutot qu'elle n'echoue : la matrice enumere. Sur
        // un chemin vivant, c'est `CombatSituation::ensureItCanOccur()` qui leve, bien avant ici.
        if (!$situation->isPossible()) {
            return ArrivalDecision::outsideMatrixDomain(InvariantCode::SituationCannotOccur);
        }

        // Le plan resolu ne designe aucun corps : les recours ordonnes du jeu sont epuises. Cela ne
        // depend pas de l'etat de la cible — une flotte sans destination n a nulle part ou se poser,
        // combat ou non.
        if ($situation->scopeFor($returnPlan) === TargetScope::NoDestination) {
            return ArrivalDecision::cancelWithoutImpact(CombatReasonCode::NoReturnDestination);
        }

        // L'espace profond ne porte aucun corps celeste, donc aucun verrou. Un combat d'expedition
        // peut durer et retenir la flotte ; il ne releve pas de cette matrice-ci.
        if ($situation->scope() === TargetScope::DeepSpace) {
            return ArrivalDecision::outsideMatrixDomain(InvariantCode::NotACelestialBodyTarget);
        }

        return match ($situation->targetState) {
            null, CombatState::Resolved, CombatState::Cancelled => $this->onAFreeBody($situation),

            CombatState::Rallying => $this->beforeTheSnapshot($situation, $returnPlan),

            CombatState::Active => $this->afterTheSnapshot($situation, $returnPlan),

            // **La correction qui compte.** L'evenement attend que la resolution soit close, mais ce
            // qu'on en fera est deja decide : c'est celui d'un evenement tardif, jamais celui d'une
            // arrivee sur un corps libre.
            CombatState::Resolving => ArrivalDecision::deferUntilResolved(
                $this->afterTheSnapshot($situation, $returnPlan)
            ),
        };
    }

    /**
     * Ce qui reste a obtenir au sujet de la photographie.
     *
     * ## La garantie positive
     *
     * Interdire a la matrice de rendre `LandOutsideSnapshot` pendant le ralliement empechait le
     * mauvais resultat ; cela n'obligeait personne a en demander un bon. Toute arrivee qui **se
     * pose** pendant que la photographie n'est pas prise porte donc `RequiresCausalDecision`, et
     * le constructeur du verdict verifie la coherence.
     *
     * Les deux barrieres restent hors de cette classe : engagement irrevocable avant l'ouverture,
     * effet planifie strictement avant la fermeture, une egalite comptant pour « apres ». C'est le
     * reconciliateur causal qui les evalue, avec des faits que la situation ne porte pas.
     *
     * @param CombatSituation $situation
     * @param ArrivalDecision $movement
     * @param ReturnPlan $returnPlan
     * @return SnapshotObligation
     */
    private function snapshotObligationOf(
        CombatSituation $situation,
        ArrivalDecision $movement,
        ReturnPlan $returnPlan,
    ): SnapshotObligation {
        // Pas de combat a l'heure planifiee : il n'y a aucune photographie a rejoindre.
        if (!$situation->targetIsEngaged()) {
            return SnapshotObligation::NotConcerned;
        }

        // Un champ de debris, une position vide ou une flotte sans destination ne figurent dans
        // aucune photographie de corps celeste.
        if ($situation->scopeFor($returnPlan) !== TargetScope::CelestialBody) {
            return SnapshotObligation::NotConcerned;
        }

        // **La question n'est pas « depose-t-elle des vaisseaux ».** Un missile modifie des defenses
        // sans poser de flotte ; une admission encore a prononcer fera entrer une flotte entiere.
        if (!ArrivalVerdict::decisionMayTouchTheSnapshot($movement)) {
            return SnapshotObligation::NotConcerned;
        }

        return $situation->targetState === CombatState::Rallying
            ? SnapshotObligation::RequiresCausalDecision
            : SnapshotObligation::SettledOutsideSnapshot;
    }

    /**
     * Le corps vise ne porte aucun combat en cours.
     *
     * `locksTargetBody()` rend faux pour `Resolved` et `Cancelled`, et l'absence de combat ne
     * verrouille rien. Attention : `Resolved` ne vaut « libre » que pour un evenement **reellement
     * prevu apres la fin** du combat, et qui n'avait pas deja recu une continuation differee.
     *
     * @param CombatSituation $situation
     * @return ArrivalDecision
     */
    private function onAFreeBody(CombatSituation $situation): ArrivalDecision
    {
        if ($situation->leg === FlightLeg::Outbound && $situation->mission->opensCombat()) {
            return ArrivalDecision::joinAttack();
        }

        return ArrivalDecision::completeNormally();
    }

    /**
     * La fenetre de ralliement est ouverte : la photographie n'est pas encore prise.
     *
     * @param CombatSituation $situation
     * @param ReturnPlan $returnPlan
     * @return ArrivalDecision
     */
    private function beforeTheSnapshot(CombatSituation $situation, ReturnPlan $returnPlan): ArrivalDecision
    {
        // Tout vrai retour ayant une destination valide atterrit, quel qu'ait ete l'objet de son aller.
        // Son appartenance a la photographie depend des deux barrieres temporelles et se decide dans
        // `SnapshotDecision` ; il ne prolonge jamais la fenetre.
        if ($situation->leg === FlightLeg::Return) {
            return ArrivalDecision::completeNormally();
        }

        return match ($situation->mission) {
            // L'admission depend de faits collectifs et persistes : la liste figee a l'ouverture,
            // l'alliance de l'initiateur au moment de cette ouverture, les budgets du camp. Elle se
            // prend sous verrou — deux workers ne doivent jamais prendre ensemble la derniere place.
            CombatMissionKind::Attack,
            CombatMissionKind::AcsAttack,
            CombatMissionKind::MoonDestruction => ArrivalDecision::selectByAttackAdmission(),

            // Le camp defenseur a ses propres listes et ses propres budgets. Une Defense ACS refusee
            // repart : elle ne stationne jamais, immunisee, au-dessus d'un combat en cours.
            CombatMissionKind::AcsDefend => ArrivalDecision::selectByDefenceAdmission(),

            // La livraison est appliquee aux ressources de la cible : elles deviennent reservables et
            // pillables. Les transporteurs repartent normalement et ne deviennent jamais defenseurs.
            CombatMissionKind::Transport => ArrivalDecision::completeNormally(),

            // Flotte et cargaison rejoignent l'etat global de la cible, sans prolonger la fenetre.
            CombatMissionKind::Deployment => ArrivalDecision::completeNormally(),

            // Retour intact : ni espionnage, ni contre-espionnage, ni rapport. Le joueur ne recoit
            // qu'une raison stable disant que la cible est engagee.
            CombatMissionKind::Espionage => $this->sendItHome($returnPlan, CombatReasonCode::TargetCombatLocked),

            // Engage avant l'ouverture et prevu avant la fermeture : l'impact s'applique avant la
            // photographie. Cree apres l'ouverture malgre le verrou : anomalie, annulation et alerte.
            // Seul l'ordre des evenements distingue les deux.
            CombatMissionKind::Missile => ArrivalDecision::selectByEventOrder(),

            // Le champ de debris n'herite d'aucun verrou, mais l'ordre temporel s'impose : un
            // recycleur prevu avant la creation de nouveaux debris ne les recolte pas.
            CombatMissionKind::Recycle => ArrivalDecision::selectByEventOrder(),

            // La position a cesse d'etre libre pendant le vol. La colonisation echoue par ses propres
            // regles et la flotte revient ; elle ne cree jamais de colonie sur un corps verrouille.
            CombatMissionKind::Colonisation => $this->sendItHome($returnPlan, CombatReasonCode::PositionNoLongerFree),

            CombatMissionKind::Expedition => throw new LogicException(
                'Une expedition vise l espace profond : elle est ecartee par la portee avant d arriver ici.'
            ),
        };
    }

    /**
     * La photographie est prise : la bataille est en cours, ou son resultat s'applique.
     *
     * C'est aussi la **continuation** de tout evenement differe pendant `Resolving` : un evenement
     * qui arrive alors est un evenement tardif, et le rejouer comme une arrivee sur un corps libre
     * recreerait la file d'attente que le jeu refuse.
     *
     * @param CombatSituation $situation
     * @param ReturnPlan $returnPlan
     * @return ArrivalDecision
     */
    private function afterTheSnapshot(CombatSituation $situation, ReturnPlan $returnPlan): ArrivalDecision
    {
        // « Ce sont les vaisseaux du proprietaire qui rentrent chez lui. Les renvoyer serait absurde. »
        // Ils se posent hors photographie et sont entierement preserves — d'ou l'obligation d'appliquer
        // les pertes en difference sur les unites photographiees, jamais en remplacant le contenu du
        // corps celeste.
        if ($situation->leg === FlightLeg::Return) {
            return ArrivalDecision::landOutsideSnapshot(CombatReasonCode::OwnFleetComingHome);
        }

        return match ($situation->mission) {
            // Il n'y a ni file d'attente ni second combat automatique : une attaque arrivee apres la
            // fermeture repart par la mecanique normale de rappel.
            CombatMissionKind::Attack,
            CombatMissionKind::AcsAttack,
            CombatMissionKind::MoonDestruction => $this->sendItHome($returnPlan, CombatReasonCode::RallyClosed),

            // Trop tardive pour figurer dans la photographie, et pas question de stationner immunisee.
            CombatMissionKind::AcsDefend => $this->sendItHome($returnPlan, CombatReasonCode::RallyClosed),

            // Le proprietaire rentre chez lui : la flotte se pose hors photographie, preservee, et
            // reste verrouillee jusqu'a la fin du combat.
            CombatMissionKind::Deployment => ArrivalDecision::landOutsideSnapshot(CombatReasonCode::OwnFleetComingHome),

            // Livraison normale, mais hors photographie : ces ressources sont hors butin et hors
            // reservation de ce combat. Les transporteurs repartent.
            CombatMissionKind::Transport => ArrivalDecision::completeNormally(),

            // Retour intact, sans effet ni rapport.
            CombatMissionKind::Espionage => $this->sendItHome($returnPlan, CombatReasonCode::TargetCombatLocked),

            // « Un missile ne peut ni modifier une defense deja photographiee ni disparaitre sans
            // raison » : il attend le resultat, puis frappe ce qui reste — une seule fois.
            CombatMissionKind::Missile => ArrivalDecision::deferImpact(CombatReasonCode::RallyClosed),

            // Le recycleur ne recolte que ce qui existe selon son rang dans l'ordre des evenements.
            CombatMissionKind::Recycle => ArrivalDecision::selectByEventOrder(),

            CombatMissionKind::Colonisation => $this->sendItHome($returnPlan, CombatReasonCode::PositionNoLongerFree),

            CombatMissionKind::Expedition => throw new LogicException(
                'Une expedition vise l espace profond : elle est ecartee par la portee avant d arriver ici.'
            ),
        };
    }

    /**
     * Renvoyer une flotte, ou la faire disparaitre si elle n'a nulle part ou aller.
     *
     * Le choix se lit dans le plan, pas dans le genre d'acteur : un joueur dont la lune a ete
     * detruite a toujours une destination, et une flotte pilotee par le serveur peut en avoir une.
     * C'est `ReturnPlan` qui porte le fait, apres avoir epuise les recours ordonnes du jeu.
     *
     * @param ReturnPlan $returnPlan
     * @param CombatReasonCode $reason
     * @return ArrivalDecision
     */
    private function sendItHome(ReturnPlan $returnPlan, CombatReasonCode $reason): ArrivalDecision
    {
        if (!$returnPlan->isPossible()) {
            return ArrivalDecision::cancelWithoutImpact($reason);
        }

        return ArrivalDecision::returnToOrigin($returnPlan, $reason);
    }
}
