<?php

namespace OGame\Lifeforms\Presentation;

use OGame\Lifeforms\Bonuses\LifeformBonusContribution;
use OGame\Lifeforms\Bonuses\LifeformBonusResolver;
use OGame\Lifeforms\Catalogue\LifeformCatalogue;
use OGame\Lifeforms\Research\LifeformExperience;
use OGame\Lifeforms\Species;
use OGame\Models\Lifeforms\LifeformSpeciesProgress;
use OGame\Models\Planet;
use OGame\Services\ObjectService;
use OGame\Services\PlayerService;
use RuntimeException;

/**
 * Ce que la page des bonus montre : les niveaux d experience du compte, puis chaque effet avec son total
 * et le detail qui le compose, planete par planete (journal §155.7).
 *
 * La page officielle est batie ainsi : un bloc d experience par espece, puis un bloc par effet, chacun
 * deplie en categories « Colonie [1:105:11] » portant une table « Emplacement / Niveau / Technologie /
 * Total ». Les nombres viennent du resolveur — **totaux et detail d une meme promenade** — et rien n est
 * recalcule ici : ce presentateur groupe et nomme, il ne decide pas.
 */
final class LifeformBonusPage
{
    public function __construct(private readonly LifeformBonusResolver $resolver)
    {
    }

    /**
     * Les quatre especes avec leur niveau, leur progression et leur bonus.
     *
     * @return array<int, array{species: Species, name: string, level: int, progress: int, needed: int, bonus: float, discovered: bool}>
     */
    public function experienceOf(PlayerService $player): array
    {
        $points = [];
        foreach (LifeformSpeciesProgress::query()->where('user_id', $player->getId())->get() as $ligne) {
            $points[(int)$ligne->species] = ['points' => (int)$ligne->experience, 'discovered' => $ligne->discovered_at !== null];
        }

        $resultat = [];
        foreach (Species::cases() as $espece) {
            $acquis = $points[$espece->value]['points'] ?? 0;
            [$dans, $requis] = LifeformExperience::progressOf($acquis);
            $nom = __('t_lifeforms.species.' . $espece->machineName());
            $resultat[] = [
                'species' => $espece,
                'name' => is_string($nom) ? $nom : $espece->machineName(),
                'level' => LifeformExperience::levelOf($acquis),
                'progress' => $dans,
                'needed' => $requis,
                'bonus' => LifeformExperience::bonusFraction(LifeformExperience::levelOf($acquis)) * 100,
                'discovered' => $points[$espece->value]['discovered'] ?? false,
            ];
        }

        return $resultat;
    }

    /**
     * Les effets que le compte porte, chacun avec son total applique et son detail par planete.
     *
     * @return array<int, array{key: string, label: string, total: float, capped: bool, planets: array<int, array{name: string, coordinates: string, total: float, rows: array<int, array{slot: int, level: int, title: string, percent: float}>}>}>
     */
    public function effectsOf(PlayerService $player): array
    {
        $applique = $this->resolver->forPlayer($player->getId());
        $contributions = $this->resolver->contributionsOf($player->getId());
        if ($contributions === []) {
            return [];
        }

        $corps = [];
        foreach (Planet::query()->where('user_id', $player->getId())->get(['id', 'name', 'galaxy', 'system', 'planet']) as $planete) {
            $corps[(int)$planete->id] = ['name' => (string)$planete->name, 'coordinates' => $planete->galaxy . ':' . $planete->system . ':' . $planete->planet];
        }

        /** @var array<string, array<int, array<int, LifeformBonusContribution>>> $parEffet */
        $parEffet = [];
        foreach ($contributions as $contribution) {
            $parEffet[$contribution->key()][$contribution->planetId][] = $contribution;
        }

        $resultat = [];
        foreach ($parEffet as $clef => $planetes) {
            [$code, $cible] = self::split($clef);
            $brut = 0.0;
            $lignes = [];
            foreach ($planetes as $planetId => $contributionsDeLaPlanete) {
                $sousTotal = 0.0;
                $rows = [];
                foreach ($contributionsDeLaPlanete as $contribution) {
                    $sousTotal += $contribution->fraction * 100;
                    $rows[] = [
                        'slot' => $contribution->slot,
                        'level' => $contribution->level,
                        'title' => self::titleOfObject($contribution->objectId),
                        'percent' => $contribution->fraction * 100,
                    ];
                }
                $brut += $sousTotal;
                usort($rows, static fn (array $a, array $b): int => $a['slot'] <=> $b['slot']);
                $lignes[$planetId] = [
                    'name' => $corps[$planetId]['name'] ?? '?',
                    'coordinates' => $corps[$planetId]['coordinates'] ?? '?',
                    'total' => $sousTotal,
                    'rows' => $rows,
                ];
            }
            $total = $applique->fraction($code, $cible) * 100;
            $resultat[] = [
                'key' => $clef,
                'label' => self::labelOf($code, $cible),
                'total' => $total,
                // Le plafond a mordu : la somme du detail depasse ce qui s applique, et la page le dit.
                'capped' => $brut - $total > 0.0001,
                'planets' => $lignes,
            ];
        }

        usort($resultat, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $resultat;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function split(string $key): array
    {
        $morceaux = explode(':', $key, 2);

        return [$morceaux[0], $morceaux[1] ?? null];
    }

    private static function labelOf(string $code, string|null $target): string
    {
        $effet = __('t_lifeforms_ui.effects.' . $code);
        $nom = is_string($effet) ? $effet : $code;
        if ($target === null) {
            return $nom;
        }

        return $nom . ' — ' . self::titleOfTarget($target);
    }

    /**
     * Le nom lisible d une cible : un objet du jeu, ou une classe de personnage.
     */
    private static function titleOfTarget(string $target): string
    {
        try {
            return ObjectService::getObjectByMachineName($target)->title;
        } catch (RuntimeException) {
            $classe = __('t_ingame.characterclass.' . $target . '.name');

            return is_string($classe) && !str_contains($classe, 't_ingame.') ? $classe : $target;
        }
    }

    private static function titleOfObject(int $objectId): string
    {
        $objet = LifeformCatalogue::byId($objectId);
        $titre = __('t_lifeforms.' . $objet->machineName . '.title');

        return is_string($titre) ? $titre : $objet->machineName;
    }
}
