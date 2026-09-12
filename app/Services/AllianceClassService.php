<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Models\Alliance;
use OGame\Models\AllianceRank;
use OGame\Models\User;

/**
 * La classe d'une alliance, et ce qu'elle vaut a ses membres.
 *
 * ## Ce service repond a une seule question, et il la repond vite
 *
 * « Ce joueur beneficie-t-il de telle classe d'alliance ? » Elle est posee sur les chemins les plus
 * chauds du jeu — le calcul d'une production, la vitesse d'un vol, l'ouverture d'un combat. La
 * reponse est donc **mise en cache par requete** : un joueur ne change pas d'alliance au milieu
 * d'un calcul, et sans ce cache la production d'une planete irait chercher l'alliance autant de
 * fois qu'elle compte de batiments.
 *
 * ## Le modele est celui des classes de personnage, deliberement
 *
 * `CharacterClassService` porte des methodes de bonus nommees par ce qu'elles donnent
 * (`getMineProductionBonus`, `getTransporterSpeedBonus`...), et le moteur les lit a un point
 * unique par bonus. Ce service porte **les memes noms**, pour que le point d'application additionne
 * deux valeurs comparables au lieu d'apprendre deux vocabulaires.
 *
 * ## Ce qu'un bonus d'alliance n'est pas
 *
 * Il ne remplace jamais celui de la classe de personnage : les deux se cumulent, comme sur OGame
 * officiel. Un joueur Commercant dans une alliance de Commercants gagne les deux.
 */
class AllianceClassService
{
    /**
     * La classe deja lue pour un joueur, pendant cette requete.
     *
     * @var array<int, AllianceClass|null>
     */
    private array $luePourJoueur = [];

    public function __construct(
        private readonly DarkMatterService $darkMatterService,
        private readonly AllianceService $allianceService,
    ) {
    }

    /**
     * La classe dont ce joueur beneficie, ou rien.
     *
     * **Rien, c'est aussi le cas du joueur sans alliance** : il n'y a pas d'etat intermediaire, et
     * quitter une alliance fait perdre le bonus des la requete suivante — la lecture part de
     * `users.alliance_id`, jamais d'une copie posee sur le joueur.
     */
    public function classOf(User $user): AllianceClass|null
    {
        $id = (int)$user->id;

        if (array_key_exists($id, $this->luePourJoueur)) {
            return $this->luePourJoueur[$id];
        }

        $allianceId = $user->alliance_id === null ? 0 : (int)$user->alliance_id;

        if ($allianceId === 0) {
            return $this->luePourJoueur[$id] = null;
        }

        $nom = DB::table('alliances')->where('id', $allianceId)->value('alliance_class');

        return $this->luePourJoueur[$id] = $this->fromStoredName($nom);
    }

    public function isWarriors(User $user): bool
    {
        return $this->classOf($user) === AllianceClass::WARRIORS;
    }

    public function isTraders(User $user): bool
    {
        return $this->classOf($user) === AllianceClass::TRADERS;
    }

    public function isResearchers(User $user): bool
    {
        return $this->classOf($user) === AllianceClass::RESEARCHERS;
    }

    /**
     * La classe que porte cette alliance, ou rien.
     */
    public function classOfAlliance(Alliance $alliance): AllianceClass|null
    {
        return $this->fromStoredName($alliance->alliance_class);
    }

    /**
     * Ce membre a-t-il le droit de choisir la classe de son alliance ?
     *
     * **Le droit existait deja** : `AllianceRank::PERMISSION_MANAGE_CLASSES` est declare depuis la
     * creation des rangs, et n'etait lu nulle part. Le fondateur l'a toujours, comme partout
     * ailleurs dans ce module.
     */
    public function mayChooseFor(User $user, Alliance $alliance): bool
    {
        if ($user->alliance_id === null || (int)$user->alliance_id !== (int)$alliance->id) {
            return false;
        }

        if ((int)$alliance->founder_user_id === (int)$user->id) {
            return true;
        }

        $membre = $this->allianceService->getAllianceMember((int)$alliance->id, (int)$user->id);

        return $membre !== null && $membre->hasPermission(AllianceRank::PERMISSION_MANAGE_CLASSES);
    }

    /**
     * Choisir la classe d'une alliance, ou echouer en disant pourquoi.
     *
     * **Le paiement et l'ecriture vivent dans la meme transaction.** Un debit qui reussirait sans
     * que la classe soit posee volerait 400 000 de matiere noire a un joueur ; l'inverse la lui
     * donnerait. `debit()` ouvre deja sa propre transaction et verrouille la ligne du compte :
     * imbriquee dans celle-ci, elle ne relache rien avant la validation la plus exterieure.
     *
     * @throws Exception quand le droit manque, la monnaie manque, ou la classe est deja celle-la
     */
    public function choose(User $user, Alliance $alliance, AllianceClass $classe): void
    {
        if (!$this->mayChooseFor($user, $alliance)) {
            throw new Exception(__('t_ingame.alliance.class_not_allowed'));
        }

        if ($this->classOfAlliance($alliance) === $classe) {
            throw new Exception(__('t_ingame.alliance.class_already_selected'));
        }

        if (!$this->darkMatterService->canAfford($user, AllianceClass::PRICE_IN_DARK_MATTER)) {
            throw new Exception(__('t_ingame.alliance.class_not_enough_dark_matter', [
                'price' => number_format(AllianceClass::PRICE_IN_DARK_MATTER, 0, ',', '.'),
            ]));
        }

        DB::transaction(function () use ($user, $alliance, $classe): void {
            $this->darkMatterService->debit(
                $user,
                AllianceClass::PRICE_IN_DARK_MATTER,
                DarkMatterTransactionType::ALLIANCE_CLASS->value,
                'Alliance class set to ' . $classe->getName()
            );

            /*
             * **Ecrit par la requete, pas par le modele.** Le modele de l'alliance a pu etre charge
             * avant le debit ; le sauver ecraserait ce qu'une autre requete aurait ecrit entre-temps
             * sur les autres colonnes. Seules les deux colonnes de la classe sont touchees.
             */
            DB::table('alliances')->where('id', (int)$alliance->id)->update([
                'alliance_class' => $classe->name,
                'alliance_class_selected_at' => (int)Date::now()->timestamp,
                'updated_at' => Date::now(),
            ]);
        });

        // Ce que le cache de cette requete croyait savoir n'est plus vrai.
        $this->luePourJoueur = [];
        $alliance->refresh();
    }

    /**
     * Le nom stocke en base rendu a sa classe, ou rien si la colonne ne dit rien d'utilisable.
     *
     * **Une porte de confiance** : la valeur vient d'une colonne que d'autres outils peuvent
     * ecrire. Un nom inconnu ne leve pas — il ne donne simplement aucun bonus.
     */
    private function fromStoredName(mixed $nom): AllianceClass|null
    {
        if (!is_string($nom) || $nom === '') {
            return null;
        }

        foreach (AllianceClass::cases() as $classe) {
            if ($classe->name === $nom) {
                return $classe;
            }
        }

        return null;
    }
}
