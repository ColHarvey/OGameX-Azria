<?php

namespace OGame\Patrol\Combat;

use OGame\Combat\Support\LootContext;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameMissions\BattleEngine\State\BattleFieldState;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * Reprendre une bataille en espace libre la ou elle s est arretee, et jouer la suite.
 *
 * ------------------------------------------------------------------------------------
 * CE QUE LE DOCUMENT NE SUFFISAIT PAS A FAIRE
 *
 * L etat de champ persiste porte les unites, leurs coques entamees et les deux bandes. Il ne
 * suffit pourtant pas a rejouer un round : le moteur lit aussi `$this->attackers` et
 * `$this->defenders`.
 *
 * La lecture de la boucle tranche ce qu il faut leur rendre : **elle n en tire que le
 * `fleetMissionId`**, pour tenir les comptes par flotte. Jamais leurs unites, jamais leur
 * joueur, jamais une valeur vivante. Ce sont donc des identites qu il faut reconstruire, pas
 * des flottes completes.
 *
 * ------------------------------------------------------------------------------------
 * D OU VIENNENT CES IDENTITES — ET SURTOUT, D OU ELLES NE VIENNENT PAS
 *
 * **Jamais du monde vivant.** Une flotte relue en base aurait pu changer de composition, de
 * cargaison ou de proprietaire depuis l ouverture, et la bataille reprise ne serait plus la
 * meme.
 *
 * Les attaquantes viennent de la **photographie economique** que le contexte de butin porte
 * deja : elle a ete prise a l ouverture, elle est gelee avec le combat, et elle decrit chaque
 * flotte par son identite, son proprietaire, sa composition de depart et sa cargaison. C est
 * aussi ce que le moteur compare a sa construction (`ensureItBindsTo`) : reconstruire les
 * flottes depuis cette meme source garantit qu elles s accordent, au lieu de l esperer.
 *
 * **La composition de depart, et non la courante**, est ce qu il faut : la liaison compare ce
 * qui etait la a l ouverture. Prendre les survivants du round precedent la ferait echouer des
 * la premiere perte.
 *
 * Les defenseuses viennent du champ lui-meme : leurs identites sont portees par chaque unite.
 * La garnison — identifiant zero — est toujours presente, meme vide, parce que la boucle tient
 * un compte par flotte et qu une flotte disparue du champ garde ses lignes.
 *
 * ------------------------------------------------------------------------------------
 * LE JOUEUR EST VOLONTAIREMENT LAISSE VIDE
 *
 * `AttackerFleet::$player` n est jamais lu pendant les rounds : les niveaux sont deja cuits
 * dans les nombres de chaque unite. Lui inventer un joueur serait un mensonge — un joueur nul,
 * ou pire un joueur vivant — qui pourrait nourrir en silence un calcul ajoute demain.
 *
 * La propriete est donc laissee **non initialisee**. PHP leve alors une `Error` nommee si
 * quelqu un la lit, ce qui est exactement le comportement voulu : bruyant, jamais faux.
 */
final class SpatialFieldResumption extends PhpBattleEngine
{
    /**
     * Le moteur pret a continuer, monte sur des identites reconstruites depuis les faits geles.
     */
    public static function of(
        BattleFieldState $etat,
        PlanetService $site,
        SettingsService $settings,
        LootContext $loot,
    ): self {
        return new self(
            self::attackersFrom($loot),
            $site,
            self::defendersFrom($etat),
            $settings,
            $loot,
        );
    }

    /**
     * Joue au plus ce nombre de rounds depuis cet etat.
     *
     * @return array<int, mixed> Les rounds joues **par cet appel**.
     */
    public function play(BattleFieldState $etat, int $rounds): array
    {
        return $this->playRounds($etat, $rounds);
    }

    /**
     * Les flottes attaquantes, telles que l ouverture les a photographiees.
     *
     * @return array<int, AttackerFleet>
     */
    private static function attackersFrom(LootContext $loot): array
    {
        $flottes = [];

        foreach ($loot->snapshot['fleets'] ?? [] as $faits) {
            if (!is_array($faits)) {
                throw new RuntimeException('A frozen loot snapshot carries a fleet that is not a record.');
            }

            $flotte = new AttackerFleet();
            $flotte->fleetMissionId = (int)($faits['fleet_mission_id'] ?? 0);
            $flotte->ownerId = (int)($faits['owner_id'] ?? 0);
            $flotte->isInitiator = (bool)($faits['is_initiator'] ?? false);
            $flotte->units = self::unitsFrom(is_array($faits['units'] ?? null) ? $faits['units'] : []);
            $flotte->fleetMission = null;

            $porte = is_array($faits['carried'] ?? null) ? $faits['carried'] : [];
            $flotte->cargoResources = new Resources(
                (int)($porte['metal'] ?? 0),
                (int)($porte['crystal'] ?? 0),
                (int)($porte['deuterium'] ?? 0),
                0,
            );

            // `player` reste non initialise : voir l en-tete de classe.
            $flottes[] = $flotte;
        }

        if ($flottes === []) {
            throw new RuntimeException(
                'A resumed free-space battle has no attacking fleet: the frozen loot snapshot named none, '
                . 'and per-fleet bookkeeping would silently lose every attacker loss.'
            );
        }

        return $flottes;
    }

    /**
     * Les flottes defenseuses, par les identites que portent les unites du champ.
     *
     * @return array<int, DefenderFleet>
     */
    private static function defendersFrom(BattleFieldState $etat): array
    {
        $identites = [];

        foreach ($etat->defenderUnits as $unite) {
            $identites[$unite->fleetMissionId] = $unite->ownerId;
        }

        // **La garnison existe toujours**, meme vide : le moteur l exige a l ouverture, et la boucle
        // tient un compte par flotte. L omettre ici ferait diverger la reprise de la premiere passe.
        if (!array_key_exists(0, $identites)) {
            $identites[0] = 0;
        }

        ksort($identites);

        $flottes = [];

        foreach ($identites as $mission => $proprietaire) {
            $flotte = new DefenderFleet();
            $flotte->fleetMissionId = (int)$mission;
            $flotte->ownerId = (int)$proprietaire;
            $flotte->units = new UnitCollection();
            $flotte->fleetMission = null;

            // `player` reste non initialise, pour la meme raison que cote attaquant.
            $flottes[] = $flotte;
        }

        return $flottes;
    }

    /**
     * @param array<mixed> $composition
     */
    private static function unitsFrom(array $composition): UnitCollection
    {
        $unites = new UnitCollection();

        foreach ($composition as $nom => $nombre) {
            if (!is_string($nom) || !is_int($nombre) || $nombre < 1) {
                continue;
            }

            $unites->addUnit(ObjectService::getUnitObjectByMachineName($nom), $nombre);
        }

        return $unites;
    }
}
