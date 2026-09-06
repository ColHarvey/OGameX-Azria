<?php

namespace OGame\Services;

use Illuminate\Support\Facades\DB;
use OGame\Combat\Enums\HonorPolicy;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Highscore;
use OGame\Models\User;

/**
 * Les points d'honneur : ce qu'une bataille coute ou rapporte a la reputation d'un joueur.
 *
 * ## La regle, telle qu'OGame la pose
 *
 * Un combat rapporte de l'honneur **seulement s'il est equilibre** : chaque camp doit peser au moins
 * la moitie de l'autre au classement militaire. En dehors de cette fourchette, l'attaquant qui
 * ecrase un plus faible **perd** ce qu'il aurait gagne. C'est ce qui fait descendre un joueur sous
 * zero, et c'est ce qui donne son sens au statut de bandit.
 *
 *     points = (valeur des unites detruites) ^ 0,9 / 1000
 *
 * L'exposant est ce qui empeche une seule grande bataille d'ecraser tout le reste : detruire cent
 * fois plus ne rapporte que soixante-trois fois plus.
 *
 * ## Ce que l'honneur change ensuite
 *
 * Il ne se depense pas ; il se lit. Un joueur tombe sous le seuil devient **bandit**, et celui qui
 * l'attaque emporte tout son stock au lieu de la moitie. A l'inverse une **cible honorable** —
 * quelqu'un dont l'honneur est positif — se pille aux trois quarts.
 *
 * La composition avec les taux de classe etait deja arretee, et elle vaut toujours :
 *
 *     taux effectif = max(taux de classe, taux d'honneur)
 *
 * Un bandit attaque par un Decouvreur reste a 100 %, pas a 175 %.
 *
 * ## Ce que ce service ne fait pas
 *
 * Il ne lit ni n'ecrit pendant la bataille : il travaille sur des nombres que l'appelant lui donne,
 * et rend une decision. C'est le reglement qui l'applique, dans sa transaction, avec ses verrous.
 * Cette separation est ce qui permet de l'eprouver sans univers ni base.
 */
class HonorService
{
    /**
     * L'exposant de la formule officielle : il amortit les tres grandes batailles.
     */
    public const float EXPONENT = 0.9;

    /**
     * Le diviseur de la formule officielle, comme pour les points militaires.
     */
    public const int DIVISOR = 1000;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Les points que vaut une destruction, avant tout signe.
     *
     * **Toujours positif ou nul**, et arrondi vers le bas : c'est une magnitude, pas un verdict.
     * Le signe se decide ailleurs, selon que le combat etait honorable ou non.
     *
     * @param int $destroyedValue La valeur en ressources des unites detruites.
     * @return int
     */
    public function magnitudeOf(int $destroyedValue): int
    {
        if ($destroyedValue <= 0) {
            return 0;
        }

        return (int)floor($destroyedValue ** self::EXPONENT / self::DIVISOR);
    }

    /**
     * Ce combat est-il honorable, au sens du classement militaire ?
     *
     * Chaque camp doit peser au moins la fraction reglee de l'autre. La comparaison est **dans les
     * deux sens** : un petit qui attaque un geant n'est pas plus honorable qu'un geant qui ecrase un
     * petit — c'est l'ecart qui compte, pas sa direction.
     *
     * Un camp sans aucun point militaire ne rend jamais le combat honorable : il n'y a rien a
     * mesurer, et une division le dirait mal.
     */
    public function isHonorableFight(int $attackerMilitary, int $defenderMilitary): bool
    {
        if ($attackerMilitary <= 0 || $defenderMilitary <= 0) {
            return false;
        }

        $fraction = $this->settings->honorFightRatioPercent() / 100;

        return $defenderMilitary >= $attackerMilitary * $fraction
            && $attackerMilitary >= $defenderMilitary * $fraction;
    }

    /**
     * Le total d'honneur d'un joueur, lu sur sa ligne.
     */
    public function pointsOf(User $user): int
    {
        return (int)($user->honor_points ?? 0);
    }

    /**
     * Ce joueur est-il un bandit ?
     */
    public function isOutlaw(User $user): bool
    {
        return $this->settings->honorSystemEnabled()
            && $this->pointsOf($user) <= $this->settings->honorOutlawThreshold();
    }

    /**
     * Ce joueur est-il une cible honorable ?
     *
     * Un honneur strictement positif. Zero ne l'est pas : c'est l'etat de depart de tout le monde,
     * et en faire une cible honorable donnerait a chaque joueur neuf un statut qu'il n'a pas gagne.
     */
    public function isHonorableTarget(User $user): bool
    {
        return $this->settings->honorSystemEnabled() && $this->pointsOf($user) > 0;
    }

    /**
     * La politique de pillage que le statut du defenseur impose.
     *
     * Elle se combine au taux de classe **par maximum**, jamais par addition : la regle etait deja
     * ecrite dans `HonorPolicy` avant que le systeme n'existe, et elle ne change pas.
     */
    public function lootPolicyAgainst(User $defender): HonorPolicy
    {
        if (!$this->settings->honorSystemEnabled()) {
            return HonorPolicy::Disabled;
        }

        if ($this->isOutlaw($defender)) {
            return HonorPolicy::Outlaw;
        }

        return $this->isHonorableTarget($defender) ? HonorPolicy::HonorableTarget : HonorPolicy::Neutral;
    }

    /**
     * La valeur en ressources d'un ensemble d'unites, au prix de base du jeu.
     *
     * **Le deuterium compte**, comme au classement militaire : c'est la meme monnaie, et l'exclure
     * ferait valoir un Faucheur moins que son cout.
     */
    public function valueOf(UnitCollection $units): int
    {
        $total = 0;

        foreach ($units->units as $unit) {
            if ($unit->amount <= 0) {
                continue;
            }

            $prix = ObjectService::getObjectRawPrice($unit->unitObject->machine_name);
            $total += (int)floor($prix->sum()) * $unit->amount;
        }

        return $total;
    }

    /**
     * La valeur des unites que l'attaquant a reellement detruites.
     *
     * **Deux exclusions, et chacune a sa raison.** Les vaisseaux civils ne comptent pas : detruire
     * des transporteurs et des sondes n'est pas un fait d'armes, et les compter ferait de la chasse
     * aux cargos la meilleure source d'honneur du jeu. Les defenses reconstruites non plus : le
     * defenseur les a retrouvees, elles n'ont pas ete perdues.
     *
     * @param UnitCollection $lost Ce que le defenseur a perdu.
     * @param UnitCollection $rebuilt Ce qu'il a reconstruit apres la bataille.
     * @return int
     */
    public function destroyedValueOf(UnitCollection $lost, UnitCollection $rebuilt): int
    {
        $civils = [];

        foreach (ObjectService::getCivilShipObjects() as $objet) {
            $civils[$objet->machine_name] = true;
        }

        $total = 0;

        foreach ($lost->units as $unit) {
            $nom = $unit->unitObject->machine_name;

            if (isset($civils[$nom])) {
                continue;
            }

            $detruites = $unit->amount - $rebuilt->getAmountByMachineName($nom);

            if ($detruites <= 0) {
                continue;
            }

            $total += (int)floor(ObjectService::getObjectRawPrice($nom)->sum()) * $detruites;
        }

        return $total;
    }

    /**
     * Ce que cette bataille change a l'honneur de l'attaquant.
     *
     * **Seul l'attaquant bouge.** L'honneur mesure ce qu'un joueur choisit d'attaquer ; celui qui
     * subit une attaque n'a rien decide, et le faire varier punirait la victime. Un joueur qui
     * n'attaque jamais reste donc a zero, et c'est le comportement d'OGame.
     *
     * Contre une base pilotee par le serveur, il peut gagner mais **jamais perdre** (decision de
     * Keven) : recompenser la chasse aux pirates sans transformer les petites bases en piege.
     *
     * @param int $destroyedValue
     * @param int $attackerMilitary
     * @param int $defenderMilitary
     * @param bool $defenderIsServerDriven Le defenseur est-il un PNJ ou le compte systeme ?
     * @return int Positif, negatif ou nul.
     */
    public function attackerOutcome(
        int $destroyedValue,
        int $attackerMilitary,
        int $defenderMilitary,
        bool $defenderIsServerDriven = false,
    ): int {
        if (!$this->settings->honorSystemEnabled()) {
            return 0;
        }

        $ampleur = $this->magnitudeOf($destroyedValue);

        if ($ampleur === 0) {
            return 0;
        }

        if ($this->isHonorableFight($attackerMilitary, $defenderMilitary)) {
            return $ampleur;
        }

        return $defenderIsServerDriven ? 0 : -$ampleur;
    }

    /**
     * Ecrit le changement sur la ligne du joueur, sans jamais relire ce qu'il vient d'ecrire.
     *
     * **Une addition faite en base**, comme le credit de ressources : deux batailles qui se reglent
     * dans la meme seconde ne doivent pas s'effacer l'une l'autre. Un zero n'ecrit rien.
     */
    public function credit(int $userId, int $change): void
    {
        if ($change === 0) {
            return;
        }

        User::query()->whereKey($userId)->update([
            'honor_points' => DB::raw('honor_points + ' . $change),
        ]);
    }

    /**
     * Les points militaires d'un joueur, tels que le classement les connait.
     *
     * Zero si le classement ne l'a pas encore vu — un compte tout neuf, ou un calcul pas encore
     * passe. `isHonorableFight()` traite ce zero comme « non mesurable », donc non honorable.
     */
    public function militaryPointsOf(int $userId): int
    {
        return (int)(Highscore::query()->where('player_id', $userId)->value('military') ?? 0);
    }
}
