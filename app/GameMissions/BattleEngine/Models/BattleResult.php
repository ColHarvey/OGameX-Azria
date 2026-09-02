<?php

namespace OGame\GameMissions\BattleEngine\Models;

use OGame\Combat\Allocation\ExactLootAllocationV1;
use OGame\Combat\Policies\CargoWeightedV1;
use OGame\Combat\Support\ResourceNormalizationDiagnostics;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Resources;

/**
 * Class BattleResult.
 *
 * Model class that represents the result of a battle.
 */
class BattleResult
{
    /**
     * @var Resources The resources loot that the attacker player steals from the defender player's planet.
     */
    public Resources $loot;

    /**
     * @var Resources The debris generated as a result from the destroyed ships and/or defense after battle.
     */
    public Resources $debris;

    /**
     * @var array The wreck field data containing ships that can be repaired.
     */
    public array $wreckField = [];

    /**
     * @var bool Whether a moon already existed at defender's planet location before battle commenced.
     */
    public bool $moonExisted;

    /**
     * @var int The percentage chance of a moon appearing out of the debris field as a result of the battle.
     */
    public int $moonChance;

    /**
     * @var bool Whether a moon was created as a result of the battle. This is based on a dice roll using the moon chance.
     */
    public bool $moonCreated;

    /**
     * @var int The max. percentage of resources that the attacker player could steal from the defender player's planet.
     *
     * Arrondi vers le bas depuis `$lootRateInBasisPoints`, pour le rapport de combat.
     */
    public int $lootPercentage;

    /**
     * @var int Le taux reellement applique, en centiemes de pour-cent.
     *
     * La ponderation par le fret produit des taux comme 62,5 %, que le pour-cent entier
     * ci-dessus ne sait pas representer. C'est cette valeur-ci qui a servi au calcul.
     */
    public int $lootRateInBasisPoints = CargoWeightedV1::BASE_RATE;

    /**
     * @var string La version de la regle de pillage sous laquelle ce combat a ete calcule.
     *
     * Un combat garde la version sous laquelle il a commence : changer la formule plus tard ne doit
     * toucher que les combats suivants.
     */
    public string $lootPolicyVersion = CargoWeightedV1::VERSION;

    /**
     * @var string La version de la regle qui a reparti le butin entre les flottes.
     */
    public string $lootAllocatorVersion = ExactLootAllocationV1::VERSION;

    /**
     * @var array<string, mixed> Les faits geles qui ont produit le taux.
     *
     * Inactivite de la cible, fret total, fret des Decouvreurs, instant de la photographie. Le
     * rapport differe les lit ; il ne les redemande pas aux modeles vivants, qui auront change.
     */
    public array $lootFrozenFacts = [];

    /**
     * @var string L empreinte de la photographie qui a produit ces faits.
     *
     * Elle lie le resultat a la composition exacte qui a combattu : ces flottes, cette cible, cet
     * instant.
     */
    public string $lootSnapshotFingerprint = '';

    /**
     * @var ResourceNormalizationDiagnostics Ce que les conversions de ressources ont rencontre.
     *
     * Un artefact negatif ramene a zero, une precision degradee au-dela de deux puissance
     * cinquante-trois : ni l un ni l autre n arrete le combat, mais tous deux doivent rester
     * visibles. Ils voyagent avec le resultat jusqu a la mission, qui journalise une fois.
     */
    public ResourceNormalizationDiagnostics $resourceDiagnostics;

    /**
     * BattleResult constructor.
     *
     * Le seul champ initialise ici : une collection de diagnostics vide vaut mieux qu une propriete
     * non initialisee, que le moindre lecteur ferait exploser.
     */
    public function __construct()
    {
        $this->resourceDiagnostics = ResourceNormalizationDiagnostics::none();
    }

    /**
     * @var UnitCollection The units of attacker player at the start of the battle.
     */
    public UnitCollection $attackerUnitsStart;

    /**
     * @var UnitCollection The units that survived the battle from the attacker player.
     */
    public UnitCollection $attackerUnitsResult;

    /**
     * @var UnitCollection The units that were lost by the attacker player during the battle.
     */
    public UnitCollection $attackerUnitsLost;

    /**
     * @var Resources The resources in terms of ships that the attacker player lost during the battle.
     */
    public Resources $attackerResourceLoss;

    /**
     * @var UnitCollection The units of defender player at the start of the battle.
     */
    public UnitCollection $defenderUnitsStart;

    /**
     * @var UnitCollection The units survived the battle from the defender player.
     */
    public UnitCollection $defenderUnitsResult;

    /**
     * @var UnitCollection The units that were lost by the defender player during the battle.
     */
    public UnitCollection $defenderUnitsLost;

    /**
     * @var Resources The resources in terms of ships/defense that the defender player lost during the battle.
     */
    public Resources $defenderResourceLoss;

    /**
     * @var array<DefenderFleetResult> Per-fleet results for each defending fleet (planet owner + ACS defend fleets).
     * Empty array for backward compatibility with single-defender battles.
     */
    public array $defenderFleetResults = [];

    /**
     * @var array<AttackerFleetResult> Per-fleet results for each attacking fleet in ACS battles.
     * Empty array for backward compatibility with single-attacker battles.
     */
    public array $attackerFleetResults = [];

    /**
     * @var int The attacker player's weapon technology level.
     */
    public int $attackerWeaponLevel;

    /**
     * @var int The attacker player's shield technology level.
     */
    public int $attackerShieldLevel;

    /**
     * @var int The attacker player's armor technology level.
     */
    public int $attackerArmorLevel;

    /**
     * @var int The defender player's weapon technology level.
     */
    public int $defenderWeaponLevel;

    /**
     * @var int The defender player's shield technology level.
     */
    public int $defenderShieldLevel;

    /**
     * @var int The defender player's armor technology level.
     */
    public int $defenderArmorLevel;

    /**
     * @var array<BattleResultRound> The rounds of the battle.
     */
    public array $rounds;

    /**
     * @var UnitCollection The defense units that were repaired after battle.
     * According to game rules, approximately 70% of destroyed defenses are automatically
     * repaired and restored to the defender's planet after battle.
     */
    public UnitCollection $repairedDefenses;

    /**
     * @var int The planet ID from which the attacker launched the attack.
     */
    public int $attackerPlanetId;

    /**
     * @var bool Whether a Deathstar was destroyed by the Hamill Manoeuvre.
     * The Hamill Manoeuvre is a General class special ability where a Light Fighter
     * has a small chance to instantly destroy one Deathstar at the start of battle.
     */
    public bool $hamillManoeuvreTriggered = false;

    /**
     * @var int Attacker supremacy ratio displayed as 1:N in the combat report.
     */
    public int $tacticalRetreatRatio = 1;

    /**
     * @var int Raw attacker retreat-weighted fleet points.
     */
    public int $tacticalRetreatAttackerPoints = 0;

    /**
     * @var int Raw defender retreat-weighted fleet points.
     */
    public int $tacticalRetreatDefenderPoints = 0;

    /**
     * @var bool Whether the defending planet fleet fled before combat.
     */
    public bool $tacticalRetreatDefenderFled = false;

    /**
     * @var bool Whether the attacker also withdrew without fighting after defender flee.
     */
    public bool $tacticalRetreatAttackerAlsoRetreated = false;

    /**
     * @var int Deuterium spent (or that would be spent) for the tactical retreat.
     */
    public int $tacticalRetreatDeuteriumCost = 0;

    /**
     * @var UnitCollection|null Ships that fled combat but remain on the defender planet.
     */
    public ?UnitCollection $tacticalRetreatFleeingUnits = null;
}
