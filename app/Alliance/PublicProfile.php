<?php

namespace OGame\Alliance;

use Illuminate\Support\Facades\DB;
use OGame\Enums\AllianceClass;
use OGame\Services\AllianceClassService;
use OGame\Services\AllianceService;
use OGame\Services\BbCodeParserService;

/**
 * Ce qu une alliance montre a tout le monde, et rien d autre.
 *
 * ## Une seule source pour la page et la fenetre
 *
 * La fiche publique s ouvre en overlay depuis le classement et la Galaxie, et par son adresse directe
 * dans un onglet. Les deux enveloppes rendent le meme partiel a partir de ce meme tableau : il n y a
 * donc pas deux fiches qui pourraient se contredire.
 *
 * ## Rien d interne ne sort d ici
 *
 * `internal_text`, `application_text`, les rangs des membres, les candidatures : aucun n est lu. Un
 * tableau nomme est prefere a un modele passe a la vue, precisement pour que la vue ne puisse pas
 * atteindre une colonne reservee par accident.
 *
 * ## Ce qui n existe pas se dit, il ne s invente pas
 *
 * Sans ligne de classement ou sans rang publie : `rank` et `points` sont nuls, et la vue dit
 * « Non classee » — jamais un zero. Sans classe : `class` est nul. Un logo ou une page d accueil
 * dont l adresse n est pas une adresse http valide est **retire**, pas rendu tel quel.
 */
final class PublicProfile
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public function __construct(
        private readonly AllianceService $alliances,
        private readonly AllianceClassService $classes,
        private readonly BbCodeParserService $bbcode,
    ) {
    }

    /**
     * Le profil public d une alliance, ou rien si elle n existe pas.
     *
     * @return array{id: int, tag: string, name: string, logo: string|null, homepage: string|null, members: int, rank: int|null, points: int|null, class: AllianceClass|null, description: string, is_open: bool}|null
     */
    public function of(int $allianceId): array|null
    {
        if ($allianceId < 1) {
            return null;
        }

        $alliance = $this->alliances->getAllianceById($allianceId);

        if ($alliance === null) {
            return null;
        }

        $classement = DB::table('alliance_highscores')
            ->where('alliance_id', $allianceId)
            ->first(['general', 'general_rank']);

        $rang = null;
        $points = null;

        if ($classement !== null) {
            $rang = self::aRank($classement->general_rank);
            $points = $rang === null ? null : self::aWholeNumber($classement->general);
        }

        return [
            'id' => (int)$alliance->id,
            'tag' => (string)$alliance->alliance_tag,
            'name' => (string)$alliance->alliance_name,
            'logo' => self::anHttpImage($alliance->logo_url),
            'homepage' => self::anHttpUrl($alliance->homepage_url),
            'members' => $this->alliances->getAllianceMembers($allianceId)->count(),
            'rank' => $rang,
            'points' => $points,
            'class' => $this->classes->classOfAlliance($alliance),
            'description' => $this->bbcode->parse(trim((string)($alliance->external_text ?? ''))),
            'is_open' => (bool)$alliance->is_open,
        ];
    }

    /**
     * Une adresse http ou https, avec un hote, ou rien.
     */
    public static function anHttpUrl(mixed $value): string|null
    {
        if (!is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || mb_strlen($url) > 2048) {
            return null;
        }

        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $hote = parse_url($url, PHP_URL_HOST);

        return is_string($hote) && $hote !== '' ? $url : null;
    }

    /**
     * Une adresse d image : http ou https, et un chemin qui se termine par une extension d image.
     *
     * Une adresse quelconque rendue dans un `src` ne serait pas un script, mais elle ferait tourner un
     * chargement qui n aboutit jamais ; on refuse en amont ce qui ne peut pas etre une image.
     */
    public static function anHttpImage(mixed $value): string|null
    {
        $url = self::anHttpUrl($value);

        if ($url === null) {
            return null;
        }

        $chemin = parse_url($url, PHP_URL_PATH);
        $extension = is_string($chemin) ? strtolower((string)pathinfo($chemin, PATHINFO_EXTENSION)) : '';

        return in_array($extension, self::IMAGE_EXTENSIONS, true) ? $url : null;
    }

    /**
     * Un rang publie est un entier strictement positif ; zero et nul sont « hors classement ».
     */
    private static function aRank(mixed $value): int|null
    {
        $rang = self::aWholeNumber($value);

        return $rang !== null && $rang > 0 ? $rang : null;
    }

    private static function aWholeNumber(mixed $value): int|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int)$value;
        }

        return null;
    }
}
