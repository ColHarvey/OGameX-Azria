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
     * L'age qu'une alliance doit avoir pour que sa premiere classe soit offerte.
     */
    public const int FREE_FIRST_CHOICE_AFTER_DAYS = 14;

    /**
     * La classe deja lue pour un joueur, pendant cette requete.
     *
     * @var array<int, AllianceClass|null>
     */
    private array $luePourJoueur = [];

    public function __construct(
        private readonly DarkMatterService $darkMatterService,
        private readonly AllianceService $allianceService,
        private readonly SettingsService $settings,
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
     * Le multiplicateur de production miniere : 1,05 pour une alliance de Commercants.
     *
     * **La forme est celle des classes de personnage** : un multiplicateur, pas un pourcentage, et
     * `1.0` quand il n'y a rien a donner. Le point d'application soustrait `1.0` pour obtenir la
     * part supplementaire ; un bonus nul y devient donc zero sans cas particulier.
     */
    public function getMineProductionBonus(User $user): float
    {
        return $this->isTraders($user) ? 1.05 : 1.0;
    }

    /**
     * Le multiplicateur de production d'energie : 1,05 pour une alliance de Commercants.
     */
    public function getEnergyProductionBonus(User $user): float
    {
        return $this->isTraders($user) ? 1.05 : 1.0;
    }

    /**
     * Le multiplicateur de vitesse des transporteurs : 1,10 pour une alliance de Commercants.
     */
    public function getTransporterSpeedBonus(User $user): float
    {
        return $this->isTraders($user) ? 1.10 : 1.0;
    }

    /**
     * Le multiplicateur de vitesse d une expedition : 1,10 pour une alliance de Chercheurs.
     *
     * **Jusqu a la destination**, dit la promesse faite au joueur : c est le vol aller qui est plus
     * rapide, pas le sejour ni le retour.
     */
    public function getExpeditionSpeedBonus(User $user): float
    {
        return $this->isResearchers($user) ? 1.10 : 1.0;
    }

    /**
     * Le multiplicateur de vitesse vers un membre de la meme alliance : 1,10 pour les Guerriers.
     *
     * **Ce bonus depend de la destination**, pas seulement du vaisseau : il ne vaut que si le corps
     * vise appartient a un membre de l alliance. Le proprietaire de la cible est donc demande, et
     * `null` — un corps inhabite, un champ de debris, un point de l espace — ne le donne jamais.
     */
    public function getAlliedFlightSpeedBonus(User $user, int|null $targetUserId): float
    {
        if ($targetUserId === null || !$this->isWarriors($user)) {
            return 1.0;
        }

        $notre = $user->alliance_id === null ? 0 : (int)$user->alliance_id;

        if ($notre === 0) {
            return 1.0;
        }

        // La cible doit etre dans la MEME alliance : un allie n est pas un membre.
        $sienne = (int)DB::table('users')->where('id', $targetUserId)->value('alliance_id');

        return $sienne === $notre ? 1.10 : 1.0;
    }

    /**
     * Le multiplicateur de capacite de stockage : 1,10 pour une alliance de Commercants.
     *
     * La promesse distingue le stockage **planetaire** du stockage **lunaire**, au meme taux. Les
     * deux methodes existent separement pour que le jour ou les taux divergeraient, le point
     * d application n ait pas a etre retouche.
     */
    public function getPlanetStorageBonus(User $user): float
    {
        return $this->isTraders($user) ? 1.10 : 1.0;
    }

    public function getMoonStorageBonus(User $user): float
    {
        return $this->isTraders($user) ? 1.10 : 1.0;
    }

    /**
     * Les niveaux de recherche de combat offerts : +1 pour une alliance de Guerriers.
     *
     * Le meme contrat que `CharacterClassService::getAdditionalCombatResearchLevels()`, et les deux
     * s additionnent : un General dans une alliance de Guerriers gagne les deux.
     */
    public function getAdditionalCombatResearchLevels(User $user): int
    {
        return $this->isWarriors($user) ? 1 : 0;
    }

    /**
     * Les niveaux de recherche d espionnage offerts : +1 pour une alliance de Guerriers.
     */
    public function getAdditionalEspionageResearchLevels(User $user): int
    {
        return $this->isWarriors($user) ? 1 : 0;
    }

    /**
     * La part de cases supplementaires d une planete colonisee : +5 % pour les Chercheurs.
     */
    public function getPlanetSizeBonus(User $user): float
    {
        return $this->isResearchers($user) ? 1.05 : 1.0;
    }

    /**
     * L espionnage peut-il analyser un systeme entier ? Reserve aux Guerriers.
     */
    public function mayScanWholeSystems(User $user): bool
    {
        return $this->isWarriors($user);
    }

    /**
     * La Phalange peut-elle analyser un systeme entier ? Reserve aux Chercheurs.
     */
    public function mayPhalanxWholeSystems(User $user): bool
    {
        return $this->isResearchers($user);
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
        if (!$this->settings->allianceClassesEnabled()) {
            return false;
        }

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
     * Ce que coute a cette alliance le choix d'une classe, maintenant.
     *
     * **La premiere est gratuite passe quatorze jours d'existence** (decision de Keven, 12 septembre
     * 2026). Deux conditions, et les deux comptent : aucune classe n'a jamais ete choisie, et
     * l'alliance a l'age requis. Le delai ecarte l'alliance creee le matin pour la classe gratuite
     * et dissoute le soir.
     *
     * Le prix est **une lecture**, pas une decision prise a l'achat : la page l'affiche, le service
     * le debite, et les deux disent donc forcement la meme chose.
     */
    public function priceFor(Alliance $alliance): int
    {
        if ($alliance->alliance_class_selected_at !== null) {
            return AllianceClass::PRICE_IN_DARK_MATTER;
        }

        $naissance = $alliance->created_at;

        if ($naissance === null) {
            return AllianceClass::PRICE_IN_DARK_MATTER;
        }

        $age = Date::now()->diffInDays($naissance, true);

        return $age >= self::FREE_FIRST_CHOICE_AFTER_DAYS ? 0 : AllianceClass::PRICE_IN_DARK_MATTER;
    }

    /**
     * Choisir la classe d'une alliance, ou echouer en disant pourquoi.
     *
     * ## Tout se decide sous verrou, sur la ligne relue
     *
     * Les droits, la classe actuelle et le prix se lisaient autrefois **avant** la transaction, sur
     * le modele passe par l'appelant. Deux demandes simultanees lisaient alors toutes les deux une
     * alliance sans classe : **toutes deux obtenaient le premier choix offert**, ou deux achats
     * identiques etaient factures l'un apres l'autre sans que le second voie le premier (Codex, revue
     * du commit 9a03c95e). Le modele recu ne sert plus qu'a nommer l'alliance.
     *
     * ## L'ordre des verrous est celui du depot
     *
     * **L'alliance, puis le compte**, comme `AllianceMembershipChangeGuard` : la ligne de l'alliance
     * serialise les adhesions, donc l'appartenance relue ne peut plus changer sous nous ; celle du
     * compte serialise le solde. `debit()` reprend ensuite le verrou du compte, deja tenu dans la
     * meme transaction. **La dissolution suit le meme ordre** depuis le 12 septembre 2026 : elle
     * ecrivait les comptes avant de supprimer l'alliance, et s'interbloquait avec un choix de classe
     * concurrent. Creation, exclusion, depart et transfert n'ecrivent pas les deux lignes.
     *
     * **Sous SQLite, `lockForUpdate()` ne compile a rien** : les essais de ce poste prouvent la
     * relecture et la forme ; la course de deux processus reels appartient au bac MariaDB.
     *
     * **Le paiement et l'ecriture vivent dans la meme transaction.** Un debit qui reussirait sans
     * que la classe soit posee volerait de la matiere noire ; l'inverse la donnerait.
     *
     * @throws Exception quand le droit manque, la monnaie manque, ou la classe est deja celle-la
     */
    public function choose(User $user, Alliance $alliance, AllianceClass $classe): void
    {
        if (!$this->settings->allianceClassesEnabled()) {
            throw new Exception(__('t_ingame.alliance.class_not_open'));
        }

        DB::transaction(function () use ($user, $alliance, $classe): void {
            $verrouillee = Alliance::query()->whereKey((int)$alliance->id)->lockForUpdate()->first();

            if (!$verrouillee instanceof Alliance) {
                throw new Exception(__('t_ingame.alliance.class_not_allowed'));
            }

            $compte = User::query()->whereKey((int)$user->id)->lockForUpdate()->first();

            if (!$compte instanceof User || !$this->mayChooseFor($compte, $verrouillee)) {
                throw new Exception(__('t_ingame.alliance.class_not_allowed'));
            }

            if ($this->classOfAlliance($verrouillee) === $classe) {
                throw new Exception(__('t_ingame.alliance.class_already_selected'));
            }

            $prix = $this->priceFor($verrouillee);

            if ($prix > 0 && !$this->darkMatterService->canAfford($compte, $prix)) {
                throw new Exception(__('t_ingame.alliance.class_not_enough_dark_matter', [
                    'price' => number_format($prix, 0, ',', '.'),
                ]));
            }

            // **Gratuit veut dire aucune ecriture**, pas un debit de zero : une ligne de depense a
            // zero dans le journal de matiere noire ferait croire a un achat.
            if ($prix > 0) {
                $this->darkMatterService->debit(
                    $compte,
                    $prix,
                    DarkMatterTransactionType::ALLIANCE_CLASS->value,
                    'Alliance class set to ' . $classe->getName()
                );
            }

            /*
             * **Ecrit par la requete, pas par le modele.** Seules les deux colonnes de la classe sont
             * touchees : sauver un modele ecraserait ce qu'une autre requete aurait ecrit sur les
             * autres colonnes.
             */
            DB::table('alliances')->where('id', (int)$verrouillee->id)->update([
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
