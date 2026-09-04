<?php

namespace OGame\Combat\Support;

use OGame\Combat\Exceptions\CorruptedResourceAmount;
use OGame\Combat\Exceptions\UnrepresentableResourceAmount;
use OGame\Exceptions\UnrepresentableWholeUnits;
use OGame\Support\WholeUnits;

/**
 * Le seul endroit autorise a recevoir un solde flottant venu de la base.
 *
 * ## Pourquoi une frontiere, et une seule
 *
 * Les colonnes de ressources sont des `double` : la production les fait avancer par fractions. Tout
 * ce qui entre dans un contexte gele, une empreinte, une reservation ou un resultat est converti en
 * unites entieres **ici**, et nulle part ailleurs. Une conversion dispersee finirait par diverger
 * d'un site a l'autre, et deux calculs du meme fait donneraient deux nombres.
 *
 * ## Sans etat, et sans journal
 *
 * Chaque conversion rend un resultat autonome. La frontiere ne conserve rien entre deux appels :
 * une liste accumulee ferait reapparaitre un diagnostic souleve sur le metal dans le rapport du
 * cristal converti juste apres, et reutiliser la meme instance transporterait l'appel precedent.
 *
 * Elle ne journalise pas non plus. Une seule resolution de combat traverse la distribution six
 * fois — le butin, les Faucheurs des deux camps, le plafonnement de leur place, et deux plafonds de
 * cargaison de retour. Un avertissement pose ici en produirait six pour une operation. Les
 * diagnostics remontent donc comme donnees, et l'orchestrateur le plus exterieur ecrit une fois.
 *
 * ## Trois categories, et pourquoi elles ne se confondent pas
 *
 * **Corrompu** — `NaN`, `INF`, `-INF`, une dette d'une unite ou plus. Ces valeurs ne decrivent
 * aucune quantite ; les convertir reviendrait a inventer un nombre.
 *
 * **Artefact d'arrondi** — strictement entre moins une unite et zero. Le moteur rencontre ces
 * soldes depuis toujours ; les refuser ferait echouer des combats qui se deroulent aujourd'hui sans
 * incident. Ils sont ramenes a zero, avec un diagnostic pour qu'ils restent visibles.
 *
 * **Precision degradee** — au-dela de deux puissance cinquante-trois, un `double` ne distingue plus
 * tous les entiers voisins. **Ce n'est pas une corruption** : la valeur est finie, positive, et
 * decrit une fortune reelle. La refuser donnerait a une planete assez riche une immunite economique
 * — plus aucune attaque, aucune collecte, aucun recyclage ne pourrait l'atteindre.
 *
 * La conversion a donc lieu, vers l'entier canonique que le `double` represente reellement. Elle ne
 * recupere pas l'unite deja perdue par la colonne, mais elle empeche toute **nouvelle** divergence
 * pendant le combat : deux lectures de la meme valeur donnent le meme entier.
 *
 * ## La borne dure, et pourquoi elle ne se compare pas a `PHP_INT_MAX`
 *
 * `PHP_INT_MAX` n'est pas representable exactement en `double` : le comparer a un flottant compare
 * en realite a `2^63`, et le test ment d'une unite. Le refus porte donc sur `>= 2^63` — exactement
 * representable, et hors du domaine d'un entier signe de soixante-quatre bits — puis la routine
 * verifie son **propre resultat** par un aller-retour.
 */
final class ResourceBoundary
{
    /**
     * La position metier par defaut, quand l'appelant n'en fournit aucune.
     *
     * Elle existe pour que la frontiere reste utilisable seule, dans un essai par exemple. En
     * production, chaque site passe la sienne : sans quoi deux incidents venus d'etapes differentes
     * porteraient la meme identite et seraient fondus en un.
     */
    public const string UNSPECIFIED_PHASE = 'unspecified';

    /**
     * La dette toleree, en unites, avant qu'un solde negatif ne devienne une corruption.
     *
     * Une unite : c'est la granularite des ressources pillables depuis `exact_loot_pipeline_v1`.
     * Elargir cette tolerance demanderait de mesurer les artefacts reels d'abord — pas de choisir
     * une valeur au juge.
     */
    public const float NEGATIVE_TOLERANCE = 1.0;

    /**
     * Le premier entier que deux puissance cinquante-trois ne separe plus de son voisin.
     */
    public const float EXACT_INTEGER_LIMIT = 9007199254740992.0;

    /**
     * Deux puissance soixante-trois : exactement representable, et hors du domaine entier signe.
     */
    public const float INTEGER_DOMAIN_LIMIT = 9223372036854775808.0;

    /**
     * Un solde de modele vivant, converti en unites entieres.
     *
     * @param float $amount
     * @param string $field Le nom du solde, pour nommer precisement ce qui est refuse.
     * @param string $phase Le moment fonctionnel de la conversion, pour situer un diagnostic.
     * @param string $subject La flotte ou le retour concerne, quand il y en a un.
     * @return NormalizedResourceAmount
     */
    public static function wholeUnitsOfLivingStock(float $amount, string $field, string $phase = ResourceBoundary::UNSPECIFIED_PHASE, string $subject = ''): NormalizedResourceAmount
    {
        return self::normalise($amount, $field, true, false, $phase, $subject);
    }

    /**
     * Un solde de modele vivant, arrondi vers le **haut**.
     *
     * La borne reservee par un combat persistant doit couvrir le butin reel : arrondir la ressource
     * vers le bas la rendrait parfois trop basse d'une unite, et le reglage n'aurait rien a
     * prelever. Les memes trois categories s'appliquent — seul le sens de l'arrondi change.
     *
     * @param float $amount
     * @param string $field
     * @param string $phase
     * @param string $subject
     * @return NormalizedResourceAmount
     */
    public static function ceilingUnitsOfLivingStock(float $amount, string $field, string $phase = ResourceBoundary::UNSPECIFIED_PHASE, string $subject = ''): NormalizedResourceAmount
    {
        return self::normalise($amount, $field, true, true, $phase, $subject);
    }

    /**
     * Un solde deja gele, converti en unites entieres.
     *
     * **Aucune tolerance ici.** La correction du petit artefact appartient a la frontiere des
     * modeles vivants ; un objet gele qui porterait un negatif affirmerait un fait que personne n'a
     * observe.
     *
     * @param float $amount
     * @param string $field
     * @param string $phase
     * @param string $subject
     * @return NormalizedResourceAmount
     */
    public static function wholeUnitsOfFrozenFact(float $amount, string $field, string $phase = ResourceBoundary::UNSPECIFIED_PHASE, string $subject = ''): NormalizedResourceAmount
    {
        return self::normalise($amount, $field, false, false, $phase, $subject);
    }

    /**
     * Une cargaison transportee par une flotte, convertie en unites entieres.
     *
     * **Aucun arrondi ici : une fraction est refusee.** Les trois autres entrees servent des soldes
     * qui avancent par fractions — la production d une planete, un partage de butin. Une cargaison
     * n avance pas : elle est posee entiere au depart de la flotte et rien ne la fait bouger en vol.
     * Une valeur fractionnaire dessus est donc une donnee abimee, jamais un artefact a corriger.
     *
     * Le silence que cette entree ferme est precis. La colonne d un retour est entiere : arrondir
     * une cargaison de `10.9` donnerait `10` dans la projection **et** `10` dans le retour cree, la
     * comparaison serait satisfaite, et neuf dixiemes d unite auraient disparu sans trace. Le refus
     * arrete l operation entiere, et la flotte reste ou elle est plutot que de rentrer amputee.
     *
     * Les autres categories ne changent pas : non fini et negatif restent des corruptions, et
     * au-dela de deux puissance cinquante-trois la valeur passe avec son diagnostic — la ne vivent
     * que des entiers, donc le refus de fraction n y mord jamais.
     *
     * @param float $amount
     * @param string $field
     * @param string $phase
     * @param string $subject
     * @return NormalizedResourceAmount
     */
    public static function wholeUnitsOfCarriedCargo(float $amount, string $field, string $phase = ResourceBoundary::UNSPECIFIED_PHASE, string $subject = ''): NormalizedResourceAmount
    {
        return self::normalise($amount, $field, false, false, $phase, $subject, true);
    }

    /**
     * La normalisation, commune aux quatre entrees.
     *
     * @param float $amount
     * @param string $field
     * @param bool $tolerateArtifact Si un negatif inferieur a une unite est ramene a zero.
     * @param bool $roundUp Le sens de l'arrondi.
     * @param string $phase
     * @param string $subject
     * @param bool $refuseFraction Si une valeur non entiere est refusee au lieu d'etre arrondie.
     * @return NormalizedResourceAmount
     */
    private static function normalise(
        float $amount,
        string $field,
        bool $tolerateArtifact,
        bool $roundUp,
        string $phase,
        string $subject,
        bool $refuseFraction = false,
    ): NormalizedResourceAmount {
        if (!is_finite($amount)) {
            throw CorruptedResourceAmount::becauseItIsNotFinite($field, $amount);
        }

        if ($amount < 0.0) {
            if (!$tolerateArtifact || $amount <= -self::NEGATIVE_TOLERANCE) {
                throw CorruptedResourceAmount::becauseItIsMateriallyNegative(
                    $field,
                    $amount,
                    $tolerateArtifact ? self::NEGATIVE_TOLERANCE : 0.0
                );
            }

            return new NormalizedResourceAmount(
                0,
                ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
                    ResourceNormalizationDiagnostics::NEGATIVE_ARTIFACT_NORMALIZED,
                    $phase,
                    $subject,
                    $field,
                    0
                ))
            );
        }

        $arrondi = $roundUp ? ceil($amount) : floor($amount);

        // **Le refus vient apres le non fini et le negatif, et avant la conversion.** Il porte sur
        // l'ecart entre la valeur et son plancher : au-dela de deux puissance cinquante-trois cet
        // ecart est toujours nul, donc une fortune degradee traverse par le chemin ordinaire.
        if ($refuseFraction && $arrondi !== $amount) {
            throw CorruptedResourceAmount::becauseItIsNotAWholeUnit($field, $amount);
        }

        $entier = self::safeIntegerOf($arrondi, $field);

        if ($entier < 0) {
            // Un debordement de conversion se manifeste par un signe qui s'inverse.
            throw CorruptedResourceAmount::becauseTheConversionIsIncoherent($field, $amount);
        }

        if ($arrondi >= self::EXACT_INTEGER_LIMIT) {
            // La valeur reste reelle ; seule sa precision est degradee. On convertit, et on le dit.
            return new NormalizedResourceAmount(
                $entier,
                ResourceNormalizationDiagnostics::of(new ResourceDiagnostic(
                    ResourceNormalizationDiagnostics::PRECISION_DEGRADED,
                    $phase,
                    $subject,
                    $field,
                    $entier
                ))
            );
        }

        return new NormalizedResourceAmount($entier, ResourceNormalizationDiagnostics::none());
    }

    /**
     * Le montant arrondi converti en entier, ou un refus si la plateforme ne peut pas le porter.
     *
     * **Le refus porte sur `>= 2^63`, avant tout transtypage.** Deux puissance soixante-trois est
     * exactement representable en flottant mais ne tient pas dans un entier signe : le transtyper
     * donnerait une valeur negative. Le controle d'aller-retour qui suit couvre ce que cette borne
     * ne verrait pas.
     *
     * @param float $arrondi
     * @param string $field
     * @return int
     */
    private static function safeIntegerOf(float $arrondi, string $field): int
    {
        // **Le domaine entier est une question de plateforme, pas d'economie.** Il vivait ici, et le
        // credit d'un corps en avait besoin aussi : deux definitions auraient fini par diverger, et
        // la plus indulgente aurait fait autorite le jour ou un nombre passerait par elle. La
        // primitive neutre repond ; cette frontiere garde le sens metier — arrondi, tolerance,
        // diagnostics — et traduit le refus dans son propre vocabulaire.
        try {
            return WholeUnits::of($arrondi, $field);
        } catch (UnrepresentableWholeUnits $hors) {
            throw UnrepresentableResourceAmount::because($field, $arrondi, $hors);
        }
    }
}
