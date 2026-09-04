<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use OGame\Combat\Allocation\AppliedLootShares;
use OGame\Combat\Allocation\FrozenLootAllocation;
use OGame\Combat\Allocation\FrozenLootPotential;
use OGame\Combat\Allocation\LootAllocatorRegistry;
use OGame\Combat\Allocation\LootSettlement;
use OGame\Combat\Allocation\RemainingTargetStock;
use OGame\Combat\Allocation\SettledBattleResult;
use OGame\Combat\Allocation\SurvivingFleetCapacity;
use OGame\Combat\Application\FrozenCombatApplicationContext;
use OGame\Combat\Enums\CombatState;
use OGame\Combat\Exceptions\CorruptedFrozenApplicationContext;
use OGame\Combat\Exceptions\MismatchedCombatIdentity;
use OGame\Combat\Exceptions\UnsettleableAtThisScale;
use OGame\Combat\Replay\BattleResultCodec;
use OGame\Combat\Support\CombatParticipantKey;
use OGame\Combat\Support\FrozenCombatVersionSet;
use OGame\Combat\Support\ResourceBoundary;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameMissions\Abstracts\GameMission;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\AttackerFleetResult;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use OGame\Models\Planet;
use OGame\Models\WreckField;
use RuntimeException;

/**
 * Le reglement d'un combat durable : une transaction, sous les verrous, sur des nombres exacts.
 *
 * ## Ce que ce service fait, et ce qu'il ne refait pas
 *
 * Il ne recalcule rien et n'applique rien lui-meme. Le moteur a calcule le resultat a la cloture ;
 * la resolution existante sait deja retirer les unites perdues, deposer les debris, creer les
 * retours, ecrire le rapport et prevenir chacun. Ecrire une seconde application du resultat aurait
 * ete un second moteur de decision, avec ses propres ecarts a decouvrir un par un.
 *
 * Ce service fait donc la seule chose que le chemin instantane ne sait pas faire : **regler le butin
 * sur ce qui reste**, des heures apres la photographie. Il relit le resultat fige, gele le potentiel,
 * relit le restant sous verrou, en tire l'applique et sa repartition, **ecrit ces nombres avant tout
 * debit**, puis remet a la resolution une copie du resultat dont le butin est l'applique et dont
 * chaque part est celle qu'il a calculee. La resolution debite alors exactement l'applique, embarque
 * exactement les parts, et fige le rapport sur ces memes nombres — sans savoir qu'elle regle une
 * bataille ancienne.
 *
 * ## L'ordre des verrous
 *
 * Celui que la migration de barriere fixe : barriere -> instance -> union -> missions par
 * identifiant croissant -> **tous les corps celestes touches**, par identifiant croissant. Une
 * jointure ou une fermeture qui le suit ne peut pas nous attendre pendant que nous l'attendons.
 *
 * La derniere famille n'est pas seulement la cible : les retours creditent les corps d'origine, un
 * champ d'epaves peut s'y deposer. Deux combats reciproques — A qui attaque B et B qui attaque A —
 * tenaient chacun sa cible puis attendaient l'origine de l'autre. Les prendre tous dans un ordre
 * total ferme cette porte.
 *
 * ## L'idempotence
 *
 * Tout est dans une transaction, et l'etat du combat est relu sous verrou avant d'agir. Un travail
 * relivre apres le commit retrouve `Resolved` et repart sans rien faire ; une panne avant le commit
 * ne laisse rien — ni potentiel, ni debit, ni retour, ni rapport — et la relivraison recommence
 * depuis un combat encore `Active`.
 *
 * ## Une borne qui refuse, au lieu d'une borne qui se dit
 *
 * Le potentiel et l'applique sont persistes en entiers exacts sur l'instance. Mais les **soldes des
 * corps et les cargaisons des missions vivent en colonnes flottantes** — une decision du depot
 * amont, prise pour accepter de tres grandes fortunes. Au-dela de 2^53, deux montants voisins
 * deviennent le meme nombre *dans la colonne* : aucun vecteur entier interne, aucun plan de
 * repartition ne peut y changer quoi que ce soit.
 *
 * Ce service ne fait donc pas semblant : quand la frontiere de conversion a dit que la precision
 * etait degradee, le reglement **s'arrete** (`UnsettleableAtThisScale`) et le combat part en
 * quarantaine. Debiter « a peu pres » reviendrait a prendre a l'un ce qu'on rend a l'autre sans le
 * dire. En deca de cette echelle, les entiers traversent `Resources` sans perte, et la comptabilite
 * est exacte de bout en bout.
 */
final class CombatSettlementService
{
    public function __construct(
        private CombatResolutionService $resolution,
        private CombatRosterReader $roster = new CombatRosterReader(),
        private LootAllocatorRegistry|null $allocators = null,
    ) {
    }

    /**
     * Regle le combat, ou explique pourquoi il n'y avait rien a regler.
     *
     * @param int $combatInstanceId
     * @param GameMission $missionDeJeu Porte le type de vitesse qui determine la duree des retours.
     * @param Closure $creerRetour Cree une mission retour ; delegue a GameMission::startReturn().
     * @param int $now L'instant du reglement, ecrit sur l'instance.
     */
    public function settle(int $combatInstanceId, GameMission $missionDeJeu, Closure $creerRetour, int $now): CombatSettlementOutcome
    {
        return DB::transaction(function () use ($combatInstanceId, $missionDeJeu, $creerRetour, $now): CombatSettlementOutcome {
            // 1. La barriere, par l'identifiant de combat. Elle peut manquer — un combat purge —
            // et ce n'est pas a elle de dire si le combat existe : c'est l'instance qui le dit.
            $barriere = CelestialBodyCombatBarrier::query()
                ->where('combat_instance_id', $combatInstanceId)
                ->lockForUpdate()
                ->first();

            // 2. L'instance, et son etat relu sous verrou : c'est lui qui rend le reglement idempotent.
            $combat = CombatInstance::query()->whereKey($combatInstanceId)->lockForUpdate()->first();

            if ($combat === null) {
                return CombatSettlementOutcome::unknownCombat();
            }

            switch ($combat->status) {
                case CombatState::Resolved:
                    return CombatSettlementOutcome::alreadySettled();
                case CombatState::Cancelled:
                    return CombatSettlementOutcome::cancelled();
                case CombatState::Rallying:
                    return CombatSettlementOutcome::stillRallying();
                case CombatState::Active:
                    break;
                default:
                    // **`Resolving` ne survit pas a une transaction.** Cet etat n'existe qu'entre
                    // deux ecritures de la meme transaction : le trouver persiste veut dire qu'une
                    // application s'est interrompue sans etre annulee — ce qui ne devrait pas
                    // arriver. Reappliquer a l'aveugle debiterait peut-etre deux fois ; le refus
                    // amene le combat a l'exploitation, qui tranchera sur pieces.
                    throw new RuntimeException(
                        'Le combat ' . $combat->id . ' est persiste en « ' . $combat->status->value
                        . ' » : une application s est interrompue sans etre annulee, et rien ne dit '
                        . 'ce qui a ete ecrit. Aucune reapplication automatique.'
                    );
            }

            // **Une instance active sans barriere est une contradiction, pas un cas normal.** La
            // barriere est le « ce corps est pris » du systeme : sans elle, un autre combat a pu
            // s'ouvrir sur le meme corps pendant celui-ci, et les deux se debiteraient.
            if ($barriere === null) {
                throw new MismatchedCombatIdentity(
                    'le combat ' . $combat->id . ' est « ' . $combat->status->value . ' » sans barriere : '
                    . 'plus rien ne tient le corps ' . $combat->target_planet_id . ' pendant qu il se bat'
                );
            }

            if ((int)$barriere->target_body_id !== (int)$combat->target_planet_id) {
                throw new MismatchedCombatIdentity(
                    'la barriere du combat ' . $combat->id . ' garde le corps ' . $barriere->target_body_id
                    . ' alors que le combat vise le corps ' . $combat->target_planet_id
                );
            }

            // **Le combat dure jusqu'a son echeance.** La regler avant la couperait court : le
            // defenseur perdrait le temps qu'on lui avait promis pour depenser ou renforcer.
            if ($combat->ends_at === null || $now < $combat->ends_at) {
                return CombatSettlementOutcome::stillFighting();
            }

            if ($combat->battle_result === null) {
                throw new RuntimeException('Le combat ' . $combat->id . ' est actif sans resultat : il n a jamais ete engage.');
            }

            // 3. L'union, puis les missions par identifiant croissant.
            if ($combat->union_id !== null) {
                FleetUnion::query()->whereKey($combat->union_id)->lockForUpdate()->first();
            }

            $this->lockMissionsOf($combat);

            // 4. **Tous les corps celestes de cette application, par identifiant croissant.**
            //
            // La cible n'est pas la seule ligne de `planets` que l'application touche : les retours
            // creditent les corps d'origine, un champ d'epaves s'y depose. Ne verrouiller que la
            // cible laissait un interblocage reel — deux combats reciproques, A qui attaque B et B
            // qui attaque A : chacun tient sa cible, puis attend l'origine de l'autre, qui est la
            // cible du premier.
            //
            // L'ordre croissant est ce qui ferme cette porte : deux transactions qui prennent les
            // memes lignes dans le meme ordre ne peuvent pas s'attendre mutuellement. La cible n'est
            // donc plus « en dernier » — elle est a sa place dans l'ordre, et c'est cette place qui
            // compte.
            $corps = $this->celestialBodyIdsOf($combat);

            $lignes = Planet::query()
                ->whereIn('id', $corps)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // 5. **Le champ de debris de la cible**, s'il existe. L'application y ajoute les debris de la
            // bataille, et un recyclage concurrent le lit puis l'ecrit : sans verrou, l'un des deux
            // perdrait sa mise a jour. Meme ordre partout — barriere, instance, union, missions,
            // corps, debris — et une seule ligne, celle des coordonnees de la cible.
            $champDebris = DebrisField::query()
                ->where('galaxy', $combat->galaxy)
                ->where('system', $combat->system)
                ->where('planet', $combat->position)
                ->lockForUpdate()
                ->first();

            $ligneCible = $lignes->get((int)$combat->target_planet_id);

            if (!$ligneCible instanceof Planet) {
                throw new RuntimeException('Le combat ' . $combat->id . ' vise un corps ' . $combat->target_planet_id . ' qui n existe plus.');
            }

            // 6. **Les champs d'epaves du defenseur a ces coordonnees.** L'application en cree un, ou
            // etend celui qui vit deja ; une seconde bataille sur le meme corps, ou une reparation
            // lancee par le joueur, touchent les memes lignes. Elles ferment l'inventaire : barriere,
            // instance, union, missions, corps, debris, epaves — toujours dans cet ordre, et par
            // identifiant croissant a l'interieur de chaque famille.
            //
            // Une lune depose ses epaves sur sa planete ; les deux partagent leurs coordonnees, et
            // c'est le proprietaire du corps vise qui les possede.
            WreckField::query()
                ->where('galaxy', $combat->galaxy)
                ->where('system', $combat->system)
                ->where('planet', $combat->position)
                ->where('owner_player_id', $ligneCible->user_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // **Apres le verrou, jamais avant.** Le lecteur charge le corps a neuf : un service
            // charge avant le verrou porterait un solde d'avant une depense concurrente, et la
            // resolution le sauverait tel quel apres le debit — rendant au defenseur ce qu il a paye.
            $effectif = $this->roster->forCombat($combat);
            $result = BattleResultCodec::fromStorage($combat->battle_result);

            // **L'enveloppe d'abord.** Le document dit de quel combat il parle : le combat, la cible,
            // l'initiatrice, les participants inscrits, la photographie de l'ouverture, les cinq
            // versions. Chacun est compare a ce que l'instance porte maintenant, sous verrou. Vivre
            // sur la meme ligne ne prouve rien d'une ligne a moitie ecrite ou reparee a la main.
            BattleResultCodec::identityOf($combat->battle_result)->assertDescribes($combat);

            // **Le resultat decrit-il bien ces flottes-la ?** Tout vient de l'instance — le resultat
            // de sa colonne, l'effectif de ses participants — mais les deux ont ete ecrits a des
            // moments differents, et rien n'empeche qu'une ligne ait bouge entre les deux. Appliquer
            // une bataille qui ne parle pas des memes flottes ferait un retour a une flotte qui n'a
            // pas combattu, ou en oublierait une qui l'a fait.
            $this->assertTheResultDescribesThisCombat($combat, $result, $effectif);

            // **Les faits d'application viennent de la cloture**, pas du monde courant — et ils
            // doivent decrire exactement cet effectif et cette echeance. Une photographie d'un autre
            // combat, ou reparee a la main, se refuse avant tout debit.
            $contexte = FrozenCombatApplicationContext::fromStorage($combat->frozen_settings);
            $contexte->assertCovers($effectif);

            if ($contexte->applicationInstant() !== (int)$combat->ends_at) {
                throw new CorruptedFrozenApplicationContext(
                    'l instant d application fige (' . $contexte->applicationInstant() . ') n est pas l echeance du combat '
                    . $combat->id . ' (' . $combat->ends_at . ') : un champ d epaves serait date d un autre moment',
                    $combat->frozen_settings
                );
            }

            // **Tout vient du combat**, jamais des courantes : un reglement sous une autre version
            // que celle de l'ouverture serait une autre bataille.
            $versions = FrozenCombatVersionSet::fromInstance($combat);

            // Le resultat d'abord : c'est lui qui est verifie contre le combat. Un resultat calcule
            // sous d'autres versions est refuse avant qu'on cherche l'allocateur qu'il faudrait.
            $potentiel = FrozenLootPotential::frozenFrom($result, $versions);
            $allocation = FrozenLootAllocation::fromFrozenSet($versions, $this->allocators);
            $restant = RemainingTargetStock::readFrom($ligneCible, CombatParticipantKey::forPlanet($ligneCible->id));
            // **L'exactitude ne se promet que la ou le stockage la porte.** Les soldes et les
            // cargaisons vivent en colonnes flottantes : au-dela de 2^53, deux montants voisins
            // deviennent le meme nombre, et aucun vecteur entier interne n'y change quoi que ce
            // soit — la perte est dans la colonne. Un combat a cette echelle s'arrete ici et part
            // en quarantaine, plutot que de debiter un nombre qu'il ne saura pas rendre.
            $constate = $potentiel->diagnostics->mergedWith($restant->diagnostics);

            if ($constate->includes(ResourceNormalizationDiagnostics::PRECISION_DEGRADED)) {
                throw new UnsettleableAtThisScale(
                    $combat->id,
                    $potentiel->diagnostics->includes(ResourceNormalizationDiagnostics::PRECISION_DEGRADED)
                        ? 'le butin potentiel fige'
                        : 'le solde restant de la cible'
                );
            }

            $reglement = LootSettlement::of($potentiel->amounts, $restant->amounts);

            $capacites = array_map(
                static fn (AttackerFleetResult $flotte): SurvivingFleetCapacity => SurvivingFleetCapacity::fromFleetResult($flotte),
                $result->attackerFleetResults
            );
            $parts = AppliedLootShares::of($reglement->applied, $capacites, $combat->mission_id, $allocation);

            // **Ce qui sera ecrit doit tenir dans la colonne, pas seulement ce qui a ete lu.** Les
            // cargaisons de retour et le champ de debris sont des colonnes flottantes elles aussi :
            // au-dela de 2^53, l'unite se perd a l'ecriture, apres que tout a ete calcule juste.
            $this->assertTheWritesFitTheStorage($combat, $result, $parts, $champDebris);

            // **Les nombres avant le debit.** Si tout ce qui suit echoue, la transaction les efface
            // avec le reste ; s'il reussit, ils sont ce sur quoi tout a ete ecrit, et la relecture
            // du combat les trouve a cote du rapport.
            $this->moveTo($combat, CombatState::Resolving);
            $combat->fill($potentiel->toColumns($now));
            $combat->applied_loot_metal = $reglement->applied->metal;
            $combat->applied_loot_crystal = $reglement->applied->crystal;
            $combat->applied_loot_deuterium = $reglement->applied->deuterium;
            $combat->save();

            $issue = $this->resolution->resolve(
                $effectif->initiator,
                SettledBattleResult::of($result, $reglement->applied, $parts),
                $effectif->target,
                $effectif->targetOwner,
                $effectif->attackers,
                $effectif->initiatorOwner,
                $effectif->defenders,
                (int)$effectif->initiator->planet_id_from,
                $missionDeJeu,
                $creerRetour,
                $allocation,
                $contexte,
            );

            $this->moveTo($combat, CombatState::Resolved);
            $combat->loot_settled_at = $now;
            $combat->battle_report_id = $issue->battleReportId;
            $combat->save();

            // **La barriere se leve avec le combat.** Elle est le « ce corps est pris » du systeme,
            // et son unicite par corps est ce qui arbitre la course a l'ouverture : la laisser
            // derriere un combat termine rendrait ce corps inattaquable pour toujours — aucune
            // nouvelle barriere ne pourrait etre posee, et l'ouverture rendrait indefiniment la
            // bataille d'hier. Rien n'est perdu : ce qui s'est passe vit dans l'instance, ses
            // participants et son rapport.
            $barriere->delete();

            return CombatSettlementOutcome::settled(
                $reglement,
                $parts,
                $issue->battleReportId,
                $potentiel->diagnostics->mergedWith($restant->diagnostics)->mergedWith($issue->diagnostics)
            );
        });
    }

    /**
     * Les corps celestes que l'application de ce combat touche, par identifiant croissant.
     *
     * La cible, qu'on debite, et les corps d'ou les flottes sont parties : les retours les
     * crediteront, un champ d'epaves peut s'y deposer. Les lire ici, avant tout verrou de ligne,
     * evite l'oeuf et la poule — l'effectif complet ne se charge qu'apres.
     *
     * @return array<int, int>
     */
    private function celestialBodyIdsOf(CombatInstance $combat): array
    {
        $missions = array_merge(
            $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_ATTACKER),
            $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_DEFENDER)
        );

        $corps = FleetMission::query()
            ->whereIn('id', $missions)
            ->whereNotNull('planet_id_from')
            ->pluck('planet_id_from')
            ->map(static fn (mixed $id): int => (int)$id)
            ->all();

        $corps[] = (int)$combat->target_planet_id;

        $corps = array_values(array_unique($corps));
        sort($corps);

        return $corps;
    }

    /**
     * Refuse d'appliquer un resultat qui ne decrit pas les flottes inscrites a ce combat.
     */
    /**
     * Les montants que l'application va ecrire tiennent-ils dans leurs colonnes ?
     *
     * Le potentiel fige et le solde restant sont verifies a la lecture ; ici ce sont les **ecritures**
     * qui restent : la cargaison de retour de chaque flotte (survivante, plus sa part, plus au pire
     * les debris qu'un Faucheur ramasserait) et le champ de debris de la cible (existant, plus ceux
     * de la bataille). Une borne large plutot que fine : refuser un combat qu'on aurait su ecrire a
     * cette echelle ne coute rien, ecrire un nombre degrade couterait une unite a quelqu'un.
     */
    private function assertTheWritesFitTheStorage(
        CombatInstance $combat,
        BattleResult $result,
        AppliedLootShares $parts,
        DebrisField|null $champDebris,
    ): void {
        foreach ($result->attackerFleetResults as $flotte) {
            $part = $parts->forFleet($flotte->fleetMissionId);

            foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
                $retour = $flotte->survivingCargo->{$ressource}->get()
                    + $part->{$ressource}
                    + $result->debris->{$ressource}->get();

                if ($retour >= ResourceBoundary::EXACT_INTEGER_LIMIT) {
                    throw new UnsettleableAtThisScale(
                        $combat->id,
                        'la cargaison de retour de la flotte ' . $flotte->fleetMissionId . ' (' . $ressource . ')'
                    );
                }
            }
        }

        foreach (['metal', 'crystal', 'deuterium'] as $ressource) {
            $existant = $champDebris === null ? 0.0 : (float)$champDebris->{$ressource};

            if ($existant + $result->debris->{$ressource}->get() >= ResourceBoundary::EXACT_INTEGER_LIMIT) {
                throw new UnsettleableAtThisScale($combat->id, 'le champ de debris de la cible (' . $ressource . ')');
            }
        }
    }

    private function assertTheResultDescribesThisCombat(CombatInstance $combat, BattleResult $result, CombatRoster $roster): void
    {
        $dansLeResultat = array_map(
            static fn (AttackerFleetResult $flotte): int => $flotte->fleetMissionId,
            $result->attackerFleetResults
        );
        sort($dansLeResultat);

        $inscrites = array_map(
            static fn (AttackerFleet $flotte): int => $flotte->fleetMissionId,
            $roster->attackers
        );
        sort($inscrites);

        if ($dansLeResultat !== $inscrites) {
            throw new MismatchedCombatIdentity(
                'la bataille figee porte les flottes ' . implode(', ', $dansLeResultat)
                . ' alors que le combat ' . $combat->id . ' en inscrit ' . implode(', ', $inscrites)
            );
        }

        if (!in_array($combat->mission_id, $dansLeResultat, true)) {
            throw new MismatchedCombatIdentity(
                'la mission initiatrice ' . $combat->mission_id . ' du combat ' . $combat->id
                . ' n a pas combattu dans la bataille figee'
            );
        }

        if ($result->attackerPlanetId !== (int)$roster->initiator->planet_id_from) {
            throw new MismatchedCombatIdentity(
                'la bataille figee est partie du corps ' . $result->attackerPlanetId
                . ' alors que la mission initiatrice ' . $roster->initiator->id . ' est partie du corps '
                . $roster->initiator->planet_id_from
            );
        }

        if ($roster->target->getPlanetId() !== (int)$combat->target_planet_id) {
            throw new MismatchedCombatIdentity(
                'l effectif vise le corps ' . $roster->target->getPlanetId()
                . ' alors que le combat ' . $combat->id . ' vise le corps ' . $combat->target_planet_id
            );
        }
    }

    /**
     * Verrouille les missions du combat par identifiant croissant.
     *
     * L'initiatrice en fait partie : c'est elle que la resolution marque traitee et dont elle cree
     * le retour. Le lecteur d'effectif la trouve inscrite parmi les attaquants.
     */
    private function lockMissionsOf(CombatInstance $combat): void
    {
        $identifiants = array_merge(
            $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_ATTACKER),
            $this->roster->missionIdsOf($combat, CombatParticipant::SIDE_DEFENDER)
        );

        sort($identifiants);

        FleetMission::query()
            ->whereIn('id', array_values(array_unique($identifiants)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Fait passer le combat a l'etat vise, si la machine d'etats le permet.
     */
    private function moveTo(CombatInstance $combat, CombatState $target): void
    {
        if (!$combat->status->canTransitionTo($target)) {
            throw new RuntimeException(
                'Le combat ' . $combat->id . ' ne peut pas passer de « ' . $combat->status->value
                . ' » a « ' . $target->value . ' ».'
            );
        }

        $combat->status = $target;
    }
}
