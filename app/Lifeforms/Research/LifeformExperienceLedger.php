<?php

namespace OGame\Lifeforms\Research;

use OGame\Lifeforms\Discovery\LifeformDiscoveryOutcome;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformDiscovery;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;

/**
 * L experience d un compte dans chaque espece, **au present ou a un instant passe**.
 *
 * ## Pourquoi elle se rembobine, alors qu on la disait irreconstituable
 *
 * L experience ne bouge que par les decouvertes, et une decouverte est un **effet date** : sa ligne porte son
 * `settled_at` et, une fois reglee, l issue **telle qu elle a ete creditee** — `settleDue()` reecrit `outcome`
 * avec ce que le compte a reellement recu, la reserve d artefacts pouvant l avoir rabote. L experience a un
 * instant est donc le total courant **moins** ce que les vols regles apres cet instant ont apporte. Rien n est
 * devine ; c est la meme facon de faire que la file des travaux pour les niveaux, et que l historique pour les
 * emplacements.
 *
 * Cela compte parce que `PlayerService::update()` regle les vols echus **avant** que les flottes ne soient
 * traitees, dans la meme requete : sans ce rembobinage, une decouverte tombee entre l arrivee d une flotte
 * et son traitement augmentait retroactivement les bonus de cette flotte (relance de Codex, journal §155.11).
 *
 * Un vol encore en cours n a rien credite : seuls les `settled` comptent. Les deux conditions — le statut et
 * l instant de reglement — se recouvrent aujourd hui, `settleDue()` etant le seul ecrivain et posant les deux
 * ensemble sous le verrou du compte : muter le statut seul ne change donc rien, et cette mutation est declaree
 * **equivalente** plutot que comptee comme tuee (journal §155.11). Le filtre de statut reste : il coute une
 * comparaison et il tiendra le jour ou un vol annule gardera sa date.
 */
final class LifeformExperienceLedger
{
    /**
     * Les points de chaque espece pour ce compte, au present.
     *
     * @return array<int, int> points par valeur d espece
     */
    public function pointsOf(int $userId): array
    {
        $points = [];
        foreach (LifeformSpeciesProgress::query()->where('user_id', $userId)->get(['species', 'experience']) as $ligne) {
            $points[(int)$ligne->species] = (int)$ligne->experience;
        }

        return $points;
    }

    /**
     * Les memes, tels qu ils etaient a cet instant : le present moins les vols regles apres lui.
     *
     * @return array<int, int> points par valeur d espece, jamais negatifs
     */
    public function pointsAt(int $userId, int $at): array
    {
        $points = $this->pointsOf($userId);

        $apres = LifeformDiscovery::query()
            ->where('user_id', $userId)
            ->where('status', 'settled')
            ->where('settled_at', '>', $at)
            ->get(['outcome']);

        foreach ($apres as $vol) {
            $stocke = $vol->outcome;
            if (!is_array($stocke)) {
                continue;
            }
            $issue = LifeformDiscoveryOutcome::fromStorage($stocke);
            if ($issue->species === null || $issue->experience <= 0) {
                continue;
            }
            $espece = $issue->species->value;
            $points[$espece] = max(0, ($points[$espece] ?? 0) - $issue->experience);
        }

        return $points;
    }

    /**
     * Le bonus d experience d une espece, en fraction, au present ou a un instant.
     *
     * @param array<int, int> $points le tableau rendu par `pointsOf()` ou `pointsAt()`
     */
    public static function bonusOf(array $points, Species $species): float
    {
        return LifeformExperience::bonusFraction(LifeformExperience::levelOf($points[$species->value] ?? 0));
    }
}
