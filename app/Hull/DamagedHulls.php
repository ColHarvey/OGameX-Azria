<?php

namespace OGame\Hull;

use InvalidArgumentException;

/**
 * Les degats que porte un groupe d unites, hors combat.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI CE N EST PAS UNE LIGNE PAR VAISSEAU
 *
 * Le jeu n a **aucune identite de vaisseau** : « 20 croiseurs » est le nombre 20, sur une colonne
 * entiere. Rien ne distingue le douzieme croiseur du treizieme, et rien ne doit le faire — ce serait
 * une table de plusieurs millions de lignes pour une information que personne ne lit unite par unite.
 *
 * L encodage retenu est un **histogramme** : par type de vaisseau, combien d unites portent quel
 * niveau de degats. Il a ete mesure contre les deux autres candidats sur le meme etat de fin de
 * combat, a trois echelles (journal §118.4) :
 *
 *   effectif x7,4  →  entrees de l histogramme x1,7  (82 → 136, soit 802 → 1 344 octets)
 *
 * La raison de cette croissance sous-lineaire est que le nombre de paliers est borne par les
 * **valeurs de degats atteignables**, pas par le nombre de vaisseaux : passe un certain effectif,
 * les unites retombent sur des paliers deja occupes.
 *
 * ------------------------------------------------------------------------------------
 * POURQUOI DES POINTS DE BASE, ET NON LA COQUE ABSOLUE
 *
 * La coque d une unite vaut `integrite / 10 x (1 + blindage x 0,1)` : elle **depend d une recherche
 * qui progresse**. Stocker 4 860 points de coque poserait la question de ce que devient cette unite
 * quand le blindage passe de 8 a 10, et la reponse serait arbitraire.
 *
 * Ce qui est stocke est donc la **part manquante**, en points de base (0 a 10 000), c est-a-dire
 * exactement ce qui ne depend d aucune technologie. Trois consequences, toutes voulues :
 *
 *   - une recherche de blindage profite a **toute** la flotte, abimees comprises, comme dans le jeu ;
 *   - la valeur stockee ne peut jamais devenir incoherente avec la coque du moment ;
 *   - le devis de reparation — `prix x part manquante x k` — se lit **directement** dans la donnee,
 *     sans reconstruire quoi que ce soit.
 *
 * Le format des points de base est deja celui du depot (`lootRateInBasisPoints`, le tir rapide en
 * centiemes de pour-cent) : ce n est pas une convention de plus.
 *
 * ------------------------------------------------------------------------------------
 * CE QUI N EST JAMAIS STOCKE ICI
 *
 * **Une unite intacte.** Elle est deja entierement decrite par la colonne entiere de son type, et
 * l inscrire ici la compterait deux fois. L invariant qui en decoule est verifie a chaque ecriture :
 * pour un type donne, la somme des nombres est **inferieure ou egale** a l effectif de ce type.
 *
 * **Le bouclier.** `PhpBattleEngine` le remet a plein a la fin de chaque round : une unite qui
 * survit a un combat a toujours son bouclier entier. Il n y a rien a persister de ce cote.
 *
 * ------------------------------------------------------------------------------------
 * L ORDRE EST UNE DECISION, PAS UN DETAIL D IMPLEMENTATION
 *
 * Deux regles de jeu tranchees le 10 septembre 2026 vivent dans cette classe, et une seule idee les
 * porte : **les plus intactes d abord**.
 *
 *   - Au depart d une flotte, ce sont les unites les moins abimees qui partent (`takeMostIntact()`) —
 *     ce qu un joueur ferait s il choisissait, et cela garde les abimees pres du dock qui les repare.
 *   - A l entree dans un champ de bataille, les unites d un meme type se rangent par degats
 *     **croissants** (`damageSequenceFor()`), donc les plus intactes en tete.
 *
 * L ordre n a aucun effet statistique — une cible se tire uniformement parmi les positions — mais il
 * decide **quelle** unite un tirage donne touche. Il doit donc etre identique dans les deux moteurs,
 * sans quoi la parite PHP/Rust tombe.
 */
final class DamagedHulls
{
    /**
     * L echelle des degats. Une unite intacte vaut 0, une unite entierement detruite vaudrait
     * `FULL_DAMAGE` — mais celle-la n existe pas : elle serait morte, et une morte n est pas stockee.
     */
    public const int FULL_DAMAGE = 10000;

    /**
     * @param array<string, array<int, int>> $paliers type => (degats en points de base => nombre),
     *                                                degats tries **croissants** (les plus intactes
     *                                                d abord), types tries par nom.
     */
    private function __construct(private readonly array $paliers)
    {
    }

    /**
     * Aucun degat : tout est intact.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Construit depuis des paliers deja connus, en les validant.
     *
     * @param array<string, array<int, int>> $paliers
     */
    public static function of(array $paliers): self
    {
        $propres = [];

        foreach ($paliers as $type => $niveaux) {
            if (!is_string($type) || $type === '') {
                throw new InvalidArgumentException('A damaged-hull record carries a unit type that is not a name.');
            }

            if (!is_array($niveaux)) {
                throw new InvalidArgumentException(sprintf('The damaged-hull levels of "%s" are not a record.', $type));
            }

            $retenus = [];

            foreach ($niveaux as $degats => $nombre) {
                $degats = self::asDamage($degats, $type);
                $nombre = self::asCount($nombre, $type);

                if ($nombre === 0) {
                    continue;
                }

                $retenus[$degats] = ($retenus[$degats] ?? 0) + $nombre;
            }

            if ($retenus === []) {
                continue;
            }

            // Les plus intactes d abord : c est la regle de jeu, et elle est portee par le tri.
            ksort($retenus);
            $propres[$type] = $retenus;
        }

        ksort($propres);

        return new self($propres);
    }

    /**
     * Relit une valeur venue de la base, **sans rien deviner**.
     *
     * C est une porte de confiance : aucun fichier du depot ne declare `strict_types`, donc une
     * signature `int` accepterait `1.5`, `'1'` et `true` en les transformant en `1`. Le refus se
     * tient ici, par `is_int()`, parce que c est ici que des nombres venus d une colonne `json`
     * entrent dans le domaine.
     */
    public static function fromStorage(mixed $brut): self
    {
        if ($brut === null || $brut === '') {
            return self::none();
        }

        if (is_string($brut)) {
            $decode = json_decode($brut, true);

            if (!is_array($decode)) {
                throw new InvalidArgumentException('A stored damaged-hull record is not decodable JSON.');
            }

            $brut = $decode;
        }

        if (!is_array($brut)) {
            throw new InvalidArgumentException('A stored damaged-hull record is neither null, a string nor a record.');
        }

        return self::of($brut);
    }

    /**
     * La forme a ecrire en base. `null` signifie « tout intact », et c est ce qui rend la migration
     * des donnees existantes triviale : une colonne vide decrit exactement le monde d avant.
     *
     * @return array<string, array<int, int>>|null
     */
    public function toStorage(): array|null
    {
        return $this->paliers === [] ? null : $this->paliers;
    }

    public function isEmpty(): bool
    {
        return $this->paliers === [];
    }

    /**
     * Combien d unites de ce type sont endommagees.
     */
    public function damagedCountOf(string $type): int
    {
        return array_sum($this->paliers[$type] ?? []);
    }

    /**
     * Les types qui portent au moins une unite endommagee.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->paliers);
    }

    /**
     * Les paliers de ce type : degats en points de base => nombre, **les plus intactes d abord**.
     *
     * @return array<int, int>
     */
    public function levelsOf(string $type): array
    {
        return $this->paliers[$type] ?? [];
    }

    /**
     * Tous les paliers.
     *
     * @return array<string, array<int, int>>
     */
    public function all(): array
    {
        return $this->paliers;
    }

    /**
     * Les degats totaux portes, en « unites entierement perdues » equivalentes — la mesure qui sert
     * au devis, et la seule qui ait un sens pour comparer deux flottes.
     */
    public function damageShareOf(string $type): float
    {
        $total = 0;

        foreach ($this->paliers[$type] ?? [] as $degats => $nombre) {
            $total += $degats * $nombre;
        }

        return $total / self::FULL_DAMAGE;
    }

    /**
     * Reunit deux ensembles de degats — une flotte qui atterrit sur un corps, deux flottes qui
     * fusionnent. Aucune unite n est soignee ni perdue au passage.
     */
    public function merge(self $autre): self
    {
        $fusion = $this->paliers;

        foreach ($autre->paliers as $type => $niveaux) {
            foreach ($niveaux as $degats => $nombre) {
                $fusion[$type][$degats] = ($fusion[$type][$degats] ?? 0) + $nombre;
            }
        }

        return self::of($fusion);
    }

    /**
     * Ajoute une unite endommagee. Une unite intacte (`0`) n est pas stockee : voir l en-tete.
     */
    public function withUnit(string $type, int $degats): self
    {
        if ($degats <= 0) {
            return $this;
        }

        $paliers = $this->paliers;
        $paliers[$type][$degats] = ($paliers[$type][$degats] ?? 0) + 1;

        return self::of($paliers);
    }

    /**
     * Retire des unites endommagees de ce type, **les plus abimees d abord**.
     *
     * C est le complement exact de `takeMostIntact()` : ce qui part etant le plus intact, ce qui
     * disparait — une perte au combat, une unite qui quitte le dock reparee — se retire par l autre
     * bout. Le nombre reellement retire est rendu, car il peut etre inferieur au nombre demande.
     *
     * @return array{0: self, 1: int} l ensemble restant, et le nombre effectivement retire
     */
    public function withoutMostDamaged(string $type, int $combien): array
    {
        if ($combien <= 0 || !isset($this->paliers[$type])) {
            return [$this, 0];
        }

        $niveaux = $this->paliers[$type];
        krsort($niveaux);

        $retires = 0;
        $restants = [];

        foreach ($niveaux as $degats => $nombre) {
            $aRetirer = min($nombre, $combien - $retires);
            $retires += $aRetirer;

            if ($nombre - $aRetirer > 0) {
                $restants[$degats] = $nombre - $aRetirer;
            }
        }

        $paliers = $this->paliers;

        if ($restants === []) {
            unset($paliers[$type]);
        } else {
            $paliers[$type] = $restants;
        }

        return [self::of($paliers), $retires];
    }

    /**
     * **La regle de depart, tranchee le 10 septembre 2026 : les plus intactes partent d abord.**
     *
     * Un corps porte 20 croiseurs dont 8 abimes, et 10 partent : les 12 intactes suffisent, donc
     * aucune abimee ne bouge. Si 15 partent, les 12 intactes partent et les 3 **moins** abimees
     * suivent.
     *
     * Les deux autres regles envisageables ont ete ecartees et le document dit pourquoi : « les plus
     * abimees d abord » envoie au combat ce qui y survivra le moins et eloigne du dock ce qui doit y
     * aller ; « au prorata » est neutre mais fragmente l histogramme des deux cotes a chaque depart.
     *
     * @param int $present l effectif **total** de ce type sur le corps, intactes comprises
     * @return array{0: self, 1: self} ce qui part, ce qui reste
     */
    public function takeMostIntact(string $type, int $combien, int $present): array
    {
        if ($combien <= 0) {
            return [self::none(), $this];
        }

        $niveaux = $this->paliers[$type] ?? [];
        $abimees = array_sum($niveaux);

        if ($abimees > $present) {
            throw new InvalidArgumentException(sprintf(
                'More %s are recorded as damaged (%d) than are present (%d): the invariant is broken.',
                $type,
                $abimees,
                $present
            ));
        }

        if ($combien > $present) {
            throw new InvalidArgumentException(sprintf(
                'Cannot take %d %s from a body that holds %d.',
                $combien,
                $type,
                $present
            ));
        }

        // Les intactes partent en premier, et elles suffisent le plus souvent.
        $intactes = $present - $abimees;
        $aPrendreDAbimees = max(0, $combien - $intactes);

        if ($aPrendreDAbimees === 0) {
            return [self::none(), $this];
        }

        // Les moins abimees d abord : `$niveaux` est deja trie par degats croissants.
        $parties = [];
        $restantes = [];
        $prises = 0;

        foreach ($niveaux as $degats => $nombre) {
            $prend = min($nombre, $aPrendreDAbimees - $prises);

            if ($prend > 0) {
                $parties[$degats] = $prend;
                $prises += $prend;
            }

            if ($nombre - $prend > 0) {
                $restantes[$degats] = $nombre - $prend;
            }
        }

        $reste = $this->paliers;

        if ($restantes === []) {
            unset($reste[$type]);
        } else {
            $reste[$type] = $restantes;
        }

        return [
            self::of($parties === [] ? [] : [$type => $parties]),
            self::of($reste),
        ];
    }

    /**
     * **L ordre d entree dans un champ de bataille**, pour un type et un effectif donnes.
     *
     * Rend une liste de degats, une entree par unite, **les plus intactes d abord** : d abord les
     * zeros des unites intactes, puis les paliers par degats croissants.
     *
     * L ordre n a aucun effet statistique — une cible se tire uniformement parmi les positions
     * restantes — mais il decide **quelle** unite un tirage donne touche. Les deux moteurs doivent
     * donc produire exactement cette suite, sans quoi la parite tombe au premier tir.
     *
     * @return array<int, int> un niveau de degats par unite, longueur exactement `$present`
     */
    public function damageSequenceFor(string $type, int $present): array
    {
        $niveaux = $this->paliers[$type] ?? [];
        $abimees = array_sum($niveaux);

        if ($abimees > $present) {
            throw new InvalidArgumentException(sprintf(
                'More %s are recorded as damaged (%d) than enter the field (%d).',
                $type,
                $abimees,
                $present
            ));
        }

        $suite = array_fill(0, $present - $abimees, 0);

        foreach ($niveaux as $degats => $nombre) {
            for ($i = 0; $i < $nombre; $i++) {
                $suite[] = $degats;
            }
        }

        return $suite;
    }

    /**
     * La coque avec laquelle une unite entre au combat, depuis sa coque pleine et ses degats.
     *
     * **Cette formule est la frontiere entre les deux mondes**, et elle doit etre reproduite a
     * l identique en Rust. Le plancher a 1 est structurel : une unite qui entre au combat est
     * vivante, et une coque nulle la ferait mourir avant le premier tir.
     *
     * **`floor` plutot que `round`, et c est un choix mesure.** L aller-retour degats → coque →
     * degats est exact partout sauf aux deux extremes, ou il derive de **1 point de base** — un
     * centieme de pour-cent. `round` supprimerait la derive du bas mais rendrait **intacte** une
     * unite a 1 point de base de degats : « abime » deviendrait « neuf » gratuitement, ce qui est
     * une faute de conservation. `floor` biaise donc d un cheveu vers l abime, et c est le bon sens
     * du biais. Il faudrait dix mille combats successifs pour qu une unite en souffre.
     */
    public static function hullFromDamage(int $coquePleine, int $degats): int
    {
        if ($degats <= 0) {
            return $coquePleine;
        }

        if ($degats >= self::FULL_DAMAGE) {
            return 1;
        }

        return max(1, (int)floor($coquePleine * (self::FULL_DAMAGE - $degats) / self::FULL_DAMAGE));
    }

    /**
     * Les degats d une unite, depuis sa coque restante et sa coque pleine — le chemin inverse.
     *
     * Rend `0` pour une unite intacte, qui n a alors rien a faire dans un histogramme.
     */
    public static function damageFromHull(int $coqueRestante, int $coquePleine): int
    {
        if ($coquePleine <= 0 || $coqueRestante >= $coquePleine) {
            return 0;
        }

        if ($coqueRestante <= 0) {
            return self::FULL_DAMAGE;
        }

        return (int)round((1 - $coqueRestante / $coquePleine) * self::FULL_DAMAGE);
    }

    private static function asDamage(mixed $valeur, string $type): int
    {
        // Une clef de tableau PHP venue d un `json_decode` associatif est deja un entier quand elle
        // ressemble a un entier ; une clef de chaine numerique passe aussi par ici.
        if (is_string($valeur) && $valeur !== '' && ctype_digit($valeur)) {
            $valeur = (int)$valeur;
        }

        if (!is_int($valeur)) {
            throw new InvalidArgumentException(sprintf('A damage level of "%s" is not an integer.', $type));
        }

        if ($valeur <= 0 || $valeur >= self::FULL_DAMAGE) {
            throw new InvalidArgumentException(sprintf(
                'A damage level of "%s" is %d, outside 1..%d: an intact unit is not stored, and a destroyed one does not exist.',
                $type,
                $valeur,
                self::FULL_DAMAGE - 1
            ));
        }

        return $valeur;
    }

    private static function asCount(mixed $valeur, string $type): int
    {
        if (is_string($valeur) && $valeur !== '' && ctype_digit($valeur)) {
            $valeur = (int)$valeur;
        }

        if (!is_int($valeur)) {
            throw new InvalidArgumentException(sprintf('A damaged-unit count of "%s" is not an integer.', $type));
        }

        if ($valeur < 0) {
            throw new InvalidArgumentException(sprintf('A damaged-unit count of "%s" is negative.', $type));
        }

        return $valeur;
    }
}
