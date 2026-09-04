<?php

namespace OGame\Combat\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OGame\Combat\Exceptions\MovementLocksOutdated;
use OGame\Models\CelestialBodyCombatBarrier;
use OGame\Models\CombatInstance;
use OGame\Models\CombatParticipant;
use OGame\Models\FleetMission;
use OGame\Models\FleetUnion;
use RuntimeException;

/**
 * La porte unique par laquelle passe tout mouvement decide pour une flotte liee a un combat.
 *
 * ## Pourquoi une seule porte
 *
 * Plusieurs chemins decident du sort d'une meme flotte, et ils tournent en meme temps : le
 * **rappel** lance par le joueur, le **demi-tour** d'une flotte que le combat refuse,
 * l'**expiration** du stationnement, l'**arrivee** qui rattache une vague a un combat, et les
 * **jointures d'union** qui changent ce a quoi la flotte est liee. Chacun lisait ses faits sans
 * verrou, puis ecrivait.
 *
 * Le defaut n'etait pas theorique. Une Defense ACS refusee et un rappel simultane pouvaient creer
 * **deux missions retour pour une seule flotte** : le rappel ne regarde pas la disposition, et la
 * disposition ne regarde pas le rappel. Les vaisseaux existaient deux fois. Un rappel qui lisait
 * la mission juste avant qu'une arrivee lui rattache son combat laissait partir une flotte que la
 * bataille comptait deja.
 *
 * ## Ce que la porte fait, et ce qu'elle ne fait pas
 *
 * Elle **ne decide rien** : les regles restent ou elles sont. Elle ouvre la section critique, prend
 * les verrous **dans l'ordre global du systeme** et **relit la mission sous verrou** avant de
 * laisser decider. La relecture est le coeur : un modele charge avant la porte decrit un passe.
 *
 * ## L'ordre des verrous, et ce qui arrive quand les liens bougent
 *
 * **Barriere -> instance -> union -> missions**, chaque famille par identifiant croissant — celui
 * que la migration de barriere fixe et que le reglement, la fermeture et l'annulation suivent.
 *
 * Les instances et les unions a tenir se calculent depuis le modele recu, **avant** la relecture :
 * l'ordre l'impose. Si une jointure ou un rattachement a change ces liens entre-temps, la porte
 * tient l'ancien et s'appreterait a decider sur le nouveau. Elle le verifie apres la relecture, et
 * quand un lien courant n'est pas tenu, elle **relache tout et recommence depuis la barriere** avec
 * les liens a jour — jamais en acquerant le verrou manquant apres la mission, ce qui inverserait
 * l'ordre.
 *
 * ## Qui possede la transaction
 *
 * « Relacher tout » n'est vrai que pour une transaction racine. Imbriquee dans une transaction
 * exterieure, une reprise ne ferait qu'un retour au point de sauvegarde, et MariaDB **garde** les
 * verrous pris avant ce point : la porte recommencerait en tenant encore l'ancien lien, dans un
 * ordre qu'elle ne controle plus. La porte ne recommence donc **que lorsqu'elle possede la
 * transaction**. Imbriquee, elle fait une seule prise, et une divergence remonte au proprietaire de
 * la transaction — qui, tenant deja la mission, ne devrait jamais la voir.
 *
 * Quand la porte renonce, elle le dit au journal d'exploitation avant de lever : le travail sera
 * repris au passage suivant, avec l'attente que ce passage impose, et personne ne bouclera sans
 * alerte.
 *
 * `lockForUpdate()` ne compile a rien sous SQLite : ce que les essais locaux montrent est la
 * **relecture**, la forme des requetes et la reprise. La preuve d'interblocage et de stabilite est
 * MariaDB — y compris ce que vaut un `FOR UPDATE` sur une barriere absente, qui n'est un verrou de
 * portee que sous des hypotheses de moteur, d'isolation et d'index a verifier la-bas.
 */
final class FleetMovementGate
{
    /**
     * Combien de fois la porte recommence depuis la barriere quand un lien a change sous elle.
     */
    private const int ATTEMPTS = 3;

    /**
     * L'attente avant une reprise, multipliee par le numero de la tentative.
     */
    private const int BACKOFF_MICROSECONDS = 20_000;

    /**
     * @param int $rootLevel Le niveau de transaction auquel la porte possede la transaction. Zero
     *        en production ; un essai qui enveloppe tout dans sa propre transaction le dit ici,
     *        pour que la porte le traite comme la racine qu'il est pour lui.
     */
    public function __construct(private readonly int $rootLevel = 0)
    {
    }

    /**
     * Ouvre la section critique, puis laisse decider sur une mission relue sous verrou.
     *
     * @template TValeur
     * @param Closure(FleetMission): TValeur $decider Ce que l'appelant veut decider, sur la mission tenue.
     * @param array<int, int> $alsoHoldingUnionIds Des unions que la decision touchera sans que la
     *        mission y soit encore liee — celle qu'une jointure s'apprete a rejoindre. Elles entrent
     *        dans l'ordre global a leur place, avec les autres.
     * @return TValeur
     *
     * @throws MovementLocksOutdated Si les liens de la mission changent plus vite que la porte ne
     *         les rattrape, ou si, imbriquee, elle en trouve un que la transaction ne tient pas.
     */
    public function decideUnderLock(FleetMission $mission, Closure $decider, array $alsoHoldingUnionIds = []): mixed
    {
        if (DB::transactionLevel() > $this->rootLevel) {
            // **Imbriquee, une seule prise.** Un retour au point de sauvegarde ne relacherait pas
            // les verrous de la transaction exterieure ; recommencer ici serait un mensonge.
            try {
                return DB::transaction(fn (): mixed => $this->attempt($mission, $decider, $alsoHoldingUnionIds));
            } catch (MovementLocksOutdated $perime) {
                $this->giveUp($perime, 1);

                throw $perime;
            }
        }

        for ($tentative = 1; ; $tentative++) {
            try {
                return DB::transaction(fn (): mixed => $this->attempt($mission, $decider, $alsoHoldingUnionIds));
            } catch (MovementLocksOutdated $perime) {
                if ($tentative >= self::ATTEMPTS) {
                    $this->giveUp($perime, $tentative);

                    throw $perime;
                }

                // **Reprise depuis la barriere, avec les liens a jour.** La transaction est deja
                // relachee ; on attend un peu, puis on recharge le modele hors verrou, puisque
                // c'est de lui que la porte deduit ce qu'elle doit tenir.
                usleep(self::BACKOFF_MICROSECONDS * $tentative);
                $mission->refresh();
            }
        }
    }

    /**
     * Le signal d'exploitation, avant de lever : une porte qui renonce ne le fait jamais en silence.
     */
    private function giveUp(MovementLocksOutdated $perime, int $tentatives): void
    {
        Log::warning('Porte des mouvements : un lien de la mission a change sous les verrous, abandon.', [
            'fleet_mission_id' => $perime->fleetMissionId,
            'lien' => $perime->lien,
            'tenu' => $perime->tenu,
            'courant' => $perime->courant,
            'tentatives' => $tentatives,
            'imbriquee' => DB::transactionLevel() > $this->rootLevel,
        ]);
    }

    /**
     * Une prise des verrous, dans l'ordre, puis la decision.
     *
     * @template TValeur
     * @param Closure(FleetMission): TValeur $decider
     * @param array<int, int> $alsoHoldingUnionIds
     * @return TValeur
     */
    private function attempt(FleetMission $mission, Closure $decider, array $alsoHoldingUnionIds): mixed
    {
        // 1. La barriere du corps vise. Elle est le « ce corps est pris » du systeme, et le
        // reglement la prend en premier : la prendre ailleurs en second remettrait les deux sens
        // de rotation que l'ordre global existe pour interdire.
        $barriere = $mission->planet_id_to === null
            ? null
            : CelestialBodyCombatBarrier::query()
                ->where('target_body_id', $mission->planet_id_to)
                ->lockForUpdate()
                ->first();

        // 2. Les instances qui lient cette flotte, par identifiant croissant.
        $instancesTenues = $this->combatsThatBindIt($mission, $barriere);

        $instances = CombatInstance::query()
            ->whereIn('id', $instancesTenues)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 3. Les unions, par identifiant croissant. Celle de la flotte compte autant que celles
        // des combats — un rappel retire la flotte de son union, et une union videe se supprime —
        // et celle qu'une jointure vise, que la flotte ne porte pas encore.
        $unions = [];

        if ($mission->union_id !== null) {
            $unions[(int)$mission->union_id] = true;
        }

        foreach ($instances as $instance) {
            if ($instance->union_id !== null) {
                $unions[(int)$instance->union_id] = true;
            }
        }

        foreach ($alsoHoldingUnionIds as $identifiant) {
            $unions[(int)$identifiant] = true;
        }

        $unionsTenues = array_keys($unions);
        sort($unionsTenues);

        FleetUnion::query()->whereIn('id', $unionsTenues)->orderBy('id')->lockForUpdate()->get();

        // 4. La mission, relue sous verrou. **C'est elle que l'appelant doit lire**, pas celle
        // qu'il tenait en entrant : entre les deux, un demi-tour a pu la renvoyer, une retenue a
        // pu l'inscrire, un reglement a pu la traiter.
        $tenue = FleetMission::query()->whereKey($mission->id)->lockForUpdate()->first();

        if (!$tenue instanceof FleetMission) {
            throw new RuntimeException('La mission ' . $mission->id . ' a disparu avant que son mouvement soit decide.');
        }

        // **Les liens relus sont-ils ceux que l'on tient ?** Sinon, une jointure ou un
        // rattachement est passe entre le calcul et la relecture. Decider maintenant, ce serait
        // decider sur un combat ou une union jamais verrouilles ; prendre le verrou ici, ce serait
        // le prendre apres la mission. On relache tout, et on recommence depuis la barriere.
        $this->refuseIfALinkIsNotHeld($tenue, $mission, $instancesTenues, $unionsTenues);

        return ($decider)($tenue);
    }

    /**
     * @param array<int, int> $instancesTenues
     * @param array<int, int> $unionsTenues
     */
    private function refuseIfALinkIsNotHeld(FleetMission $tenue, FleetMission $recue, array $instancesTenues, array $unionsTenues): void
    {
        if ((int)$tenue->planet_id_to !== (int)$recue->planet_id_to) {
            throw new MovementLocksOutdated($tenue->id, 'le corps vise', $recue->planet_id_to === null ? null : (int)$recue->planet_id_to, $tenue->planet_id_to === null ? null : (int)$tenue->planet_id_to);
        }

        if ($tenue->combat_instance_id !== null && !in_array((int)$tenue->combat_instance_id, $instancesTenues, true)) {
            throw new MovementLocksOutdated($tenue->id, 'le combat', null, (int)$tenue->combat_instance_id);
        }

        if ($tenue->union_id !== null && !in_array((int)$tenue->union_id, $unionsTenues, true)) {
            throw new MovementLocksOutdated($tenue->id, 'l union', $recue->union_id === null ? null : (int)$recue->union_id, (int)$tenue->union_id);
        }
    }

    /**
     * Les combats dont l'etat decide du sort de cette flotte.
     *
     * Les memes liens que les regles existantes lisent — le combat qui tient le corps vise, celui
     * que l'arrivee a pose sur la mission, ceux ou la fermeture l'a inscrite — sans filtre d'etat :
     * c'est justement l'etat qui peut changer sous les pieds de l'appelant.
     *
     * @return array<int, int>
     */
    private function combatsThatBindIt(FleetMission $mission, CelestialBodyCombatBarrier|null $barriere): array
    {
        $identifiants = [];

        if ($barriere !== null) {
            $identifiants[(int)$barriere->combat_instance_id] = true;
        }

        if ($mission->combat_instance_id !== null) {
            $identifiants[(int)$mission->combat_instance_id] = true;
        }

        $inscriptions = CombatParticipant::query()
            ->where('fleet_mission_id', $mission->id)
            ->pluck('combat_instance_id')
            ->all();

        foreach ($inscriptions as $identifiant) {
            $identifiants[(int)$identifiant] = true;
        }

        $liste = array_keys($identifiants);
        sort($liste);

        return $liste;
    }
}
