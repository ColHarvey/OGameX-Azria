<?php

namespace OGame\Patrol\Combat;

use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Hull\DamagedHulls;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Patrol\Geometry\SpatialPoint;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use RuntimeException;

/**
 * Le lieu d'un combat en espace libre : un point, et personne qui l'habite.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI UNE PLANETE ALORS QU'IL N'Y EN A PAS
 *
 * `BattleEngine` demande un `PlanetService` defenseur. Il ne s'en sert pas comme d'un
 * decor : il y lit le **joueur** qui defend (donc ses technologies et sa classe), la
 * garnison, l'existence d'une lune, le stock a piller et le niveau du chantier spatial
 * qui decide des epaves. Toutes ces questions ont une reponse en espace libre — elles ont
 * simplement toutes la meme : il n'y a rien ici.
 *
 * Recrire le moteur pour qu'il accepte « pas de corps » serait un second moteur de
 * decision pour une seule question. Le jeu a deja le geste : l'expedition combat une
 * flotte pirate au milieu de nulle part par `NPCPlanetService`, une planete synthetique.
 * Celle-ci suit le meme chemin, et va plus loin : elle n'emprunte **aucune** ligne de la
 * table `planets`.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI REND CETTE CLASSE SURE
 *
 * `PlanetService` accepte d'etre construit **sans planete** des lors qu'un joueur est
 * fourni : sa propriete `$planet` reste alors non initialisee. Toute methode heritee qui
 * la lirait leverait une `Error` — bruyante, jamais silencieuse. Chaque methode que le
 * moteur appelle est donc redefinie ici, et redefinie pour dire « rien ».
 *
 * Le danger serait qu'une methode non redefinie soit appelee un jour. C'est un risque
 * assume et **borne par le bruit** : la panne serait immediate et nommee, jamais une
 * valeur fausse. `SpatialCombatSiteTest` inventorie les appels du moteur et exige que
 * chacun soit couvert.
 *
 * ------------------------------------------------------------------------------------
 * LE PROPRIETAIRE VIENT DE LA PATROUILLE, JAMAIS D'UN CORPS
 *
 * Une patrouille garde son proprietaire meme si sa base d'attache change de mains — le
 * defaut a deja ete rencontre et ferme dans ce chantier (§114.25 du journal). Le joueur
 * est donc passe explicitement, et rien ici ne le deduit d'une planete.
 *
 * Une patrouille peut d'ailleurs n'avoir **aucune** base : `patrols.home_planet_id` est
 * nullable. Emprunter la base pour construire le site aurait donc echoue sur ce cas-la.
 */
final class SpatialCombatSite extends PlanetService
{
    /**
     * @param PlayerService $owner Le proprietaire de la patrouille qui defend ce point.
     * @param SpatialPoint $point Le point tenu, en coordonnees internes stables.
     * @param int $galaxy La galaxie du systeme ou se tient le combat.
     * @param int $system Le systeme ou se tient le combat.
     */
    public function __construct(
        PlayerServiceFactory $playerServiceFactory,
        SettingsService $settings,
        private readonly PlayerService $owner,
        private readonly SpatialPoint $point,
        private readonly int $galaxy,
        private readonly int $system,
    ) {
        // Le joueur est fourni, donc aucune planete n'est chargee : c'est la seule forme du
        // constructeur parent qui ne touche pas la table `planets`.
        parent::__construct($playerServiceFactory, $settings, $owner);
    }

    /**
     * Le point tenu, tel que le serveur le connait.
     */
    public function point(): SpatialPoint
    {
        return $this->point;
    }

    /**
     * Le proprietaire qui defend — celui de la patrouille, jamais celui d'un corps voisin.
     *
     * **Le type de retour se resserre**, et c'est une garantie de plus : le parent peut n'avoir
     * aucun joueur, un point spatial en a toujours un — il est exige au constructeur. Un appelant
     * qui verifierait la nullite ici verifierait quelque chose d'impossible.
     */
    public function getPlayer(): PlayerService
    {
        return $this->owner;
    }

    /**
     * **Aucune garnison.** Les unites de la patrouille sont une flotte, pas une garnison :
     * elles entrent dans le combat par `DefenderFleet`, avec leur mission pour identite. Les
     * compter ici les ferait combattre deux fois.
     */
    public function getShipUnits(): UnitCollection
    {
        return new UnitCollection();
    }

    /**
     * Aucune defense : on ne pose pas de lanceur de missiles sur le vide.
     */
    public function getDefenseUnits(): UnitCollection
    {
        return new UnitCollection();
    }

    /**
     * Aucun degat, parce qu il n y a aucune unite a ce point.
     *
     * **La surcharge est necessaire, pas decorative** : la version heritee lit
     * `$this->planet->damaged_hulls`, et ce site n a pas de ligne de planete — la propriete typee
     * n est jamais initialisee, et PHP leverait une `Error`. Les unites d une patrouille voyagent
     * sur son segment, pas sur le lieu ; leurs degats aussi.
     */
    public function damagedHulls(): DamagedHulls
    {
        return DamagedHulls::none();
    }

    /**
     * Aucun batiment, aucun vaisseau au sol. Le moteur lit ainsi le chantier spatial, qui
     * decide de la part d'epaves : sans chantier, c'est le plancher du jeu qui s'applique.
     */
    public function getObjectLevel(string $machine_name): int
    {
        return 0;
    }

    public function getObjectAmount(string $machine_name): int
    {
        return 0;
    }

    /**
     * **Aucune lune, et jamais de lune creee.** Le moteur decide de la formation d'une lune
     * depuis les debris ; en espace libre les debris existent (ils sont recoltables) mais il
     * n'y a pas d'orbite ou poser un corps, et la revue 117 ne prevoit rien de tel.
     */
    public function isMoon(): bool
    {
        return false;
    }

    public function hasMoon(): bool
    {
        return false;
    }

    /**
     * **Rien a piller au sol.** Ce que porte la patrouille est sa cargaison, et le butin d'un
     * combat en espace libre se decide par le contexte de butin — reserve de carburant protegee,
     * excedent pillable (revue 121, R6). Rendre un stock ici ferait apparaitre des ressources
     * que personne ne possede.
     */
    public function getResources(): Resources
    {
        return new Resources(0, 0, 0, 0);
    }

    /**
     * Un point ne perd rien : il n'a rien.
     *
     * Le moteur debite le defenseur du deuterium d'une retraite tactique. En espace libre il
     * n'y a pas de sol vers lequel fuir, et cette methode ne doit donc jamais etre atteinte
     * avec un montant. Un debit non nul est une contradiction, et se dit.
     */
    public function deductResources(Resources $resources, bool $save_planet = true): void
    {
        if ($resources->sum() > 0) {
            $this->refuseWrite('pay ' . $resources->sum() . ' units of resources');
        }
    }

    /**
     * ------------------------------------------------------------------------------------
     * CE SITE EST UN ADAPTATEUR DE LECTURE, ET IL LE RESTE
     *
     * Toute la surface d'ecriture de `PlanetService` est refusee ici. Ce n'est pas une
     * precaution de style : sans ces refus, un chemin qui croirait tenir une planete
     * ecrirait dans un corps **fictif** — au mieux sans effet, au pire sur la ligne d'une
     * vraie planete si un jour ce site en empruntait une.
     *
     * Les refus sont bruyants. Un chemin qui ecrit sur le vide est une faute de conception,
     * pas un cas a absorber : le silence la rendrait invisible jusqu'a ce qu'un joueur perde
     * des unites que personne n'a debitees.
     *
     * **La reparation des defenses ne peut pas s'appliquer ici**, et cela ne tient pas a un
     * refus mais a la forme du jeu : `DefenseRepairService::calculateRepairedDefenses()` ne
     * recoit que les defenses **detruites**, et un point libre n'en porte aucune. Il n'y a
     * donc rien a relever, quelle que soit la valeur du taux.
     */
    private function refuseWrite(string $geste): never
    {
        throw new RuntimeException(
            'A spatial point was asked to ' . $geste . ': free space is read-only, and writing to a '
            . 'body that does not exist would either vanish or land on a real planet.'
        );
    }

    public function save(): void
    {
        $this->refuseWrite('save itself');
    }

    public function addResources(Resources $resources, bool $save_planet = true): void
    {
        $this->refuseWrite('receive ' . $resources->sum() . ' units of resources');
    }

    public function addResourcesAtomic(Resources $resources): void
    {
        $this->refuseWrite('receive ' . $resources->sum() . ' units of resources atomically');
    }

    public function addUnit(string $machine_name, int $amount, bool $save_planet = true): void
    {
        $this->refuseWrite('receive ' . $amount . ' × ' . $machine_name);
    }

    public function removeUnits(UnitCollection $units, bool $save_planet): void
    {
        $this->refuseWrite('lose ' . $units->getAmount() . ' units from the ground');
    }

    /**
     * Un point ne garde aucune coque entamee : il n a pas d unites a lui.
     *
     * Le reglement ecrit les degats des survivants de la garnison. Ici la garnison est vide par
     * construction, donc il n a rien a ecrire — mais s il essayait, il faudrait l entendre plutot
     * que de laisser une ecriture disparaitre dans le vide.
     */
    public function writeDamagedHulls(DamagedHulls $degats, bool $save_planet = true): void
    {
        if (!$degats->isEmpty()) {
            $this->refuseWrite('record damaged hulls for units it does not hold');
        }
    }

    /**
     * **Zero, et c'est un choix lisible.** `CombatParticipantKey::forBody()` traduit un
     * identifiant nul en `body:unidentified`, un nom reserve qu'aucune ligne ne porte : la
     * garnison d'un combat spatial ne peut donc jamais etre confondue avec un corps reel.
     * Elle ne perd rien non plus, n'ayant aucune unite.
     */
    public function getPlanetId(): int
    {
        return 0;
    }

    /**
     * Le nom que porterait ce lieu dans un rapport : ses coordonnees internes.
     */
    public function getPlanetName(): string
    {
        return $this->galaxy . ':' . $this->system . ':' . $this->point->x . '/' . $this->point->y;
    }

    /**
     * Les coordonnees du systeme. **La position vaut zero**, et c'est exact : un point libre
     * n'occupe aucune des quinze positions, et pretendre le contraire ferait tomber ses debris
     * dans le champ d'une planete voisine — ce que la revue 120 interdit explicitement.
     */
    public function getPlanetCoordinates(): Coordinate
    {
        return new Coordinate($this->galaxy, $this->system, 0);
    }

    /**
     * Un point n'a pas de planete mere : il est deja le lieu.
     *
     * Le moteur ne demande la planete mere que d'une lune, et `isMoon()` rend faux ici. Rendre
     * `$this` plutot que lever garde le chemin du chantier spatial lisible.
     */
    public function planet(): PlanetService
    {
        return $this;
    }

    /**
     * Un point ne se detruit pas : il n'existe pas assez pour cela.
     */
    public function isDestroyed(): bool
    {
        return false;
    }
}
