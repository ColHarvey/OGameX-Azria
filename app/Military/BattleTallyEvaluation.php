<?php

namespace OGame\Military;

/**
 * Les « detruits » et les « perdus » d une bataille, round par round, a partir de ses faits.
 *
 * ## Les regles (decisions du 14 septembre 2026)
 *
 * - **Perdus** : la valeur des pertes definitives de chaque participant — pour la garnison, apres reparation des
 *   defenses, la reparation etant repartie sur les rounds au prorata des pertes du meme type, sans jamais depasser
 *   les pertes d un round.
 * - **Detruits** : round par round, les pertes definitives du camp adverse sont partagees entre les participants
 *   **presents au debut du round**, au prorata de la valeur de leurs forces a cet instant, par plus grand reste, egalite
 *   departagee par la clef de participant.
 * - Les forces d un round sont « depart, moins pertes anterieures au premier round, moins pertes des rounds
 *   precedents » ; pour les flottes attaquantes elles sont confrontees aux restants que le moteur a figes.
 * - Une manoeuvre de Hamill sans victime nommee, une unite hors catalogue, un participant sans proprietaire ou une
 *   somme des rounds qui ne recouvre pas les pertes definitives laissent **toute** la bataille en attente, sans credit
 *   partiel. Jamais un credit hors round.
 *
 * ## Pure
 *
 * Rien n est lu dans le monde : les faits portent les prix, la version de ponderation vient de l evenement. La meme
 * evaluation sert au moment du reglement et a la reprise.
 */
final class BattleTallyEvaluation
{
    public const string HAMILL_VICTIM_UNNAMED = 'hamill_victim_unnamed';

    public const string UNKNOWN_UNIT_FAMILY = 'unknown_unit_family';

    public const string PARTICIPANT_WITHOUT_OWNER = 'participant_without_owner';

    public const string AMBIGUOUS_PARTICIPANT = 'ambiguous_participant';

    public const string INCOHERENT_BATTLE = 'incoherent_battle';

    public function evaluate(BattleTallyFacts $facts, string $version): BattleTallyOutcome
    {
        $participants = [];

        foreach ($facts->participants as $participant) {
            if (isset($participants[$participant['key']])) {
                return BattleTallyOutcome::pending(self::AMBIGUOUS_PARTICIPANT, 'deux participants portent la clef ' . $participant['key']);
            }

            $participants[$participant['key']] = $participant;
        }

        if ($participants === []) {
            return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'aucun participant');
        }

        foreach ($participants as $clef => $participant) {
            if ($participant['owner'] === null) {
                return BattleTallyOutcome::pending(self::PARTICIPANT_WITHOUT_OWNER, 'le participant ' . $clef . ' n a pas de proprietaire');
            }
        }

        if ($facts->hamillTriggered && $facts->hamill === null) {
            return BattleTallyOutcome::pending(self::HAMILL_VICTIM_UNNAMED, 'la manoeuvre de Hamill a retire une Etoile de la Mort que le moteur n attribue a aucun participant');
        }

        if ($facts->hamill !== null) {
            $victime = $facts->hamill['victim'];
            $auteur = $facts->hamill['author'];

            if (!$facts->hamillTriggered) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'une manoeuvre de Hamill nommee sans manoeuvre declenchee');
            }

            if (!isset($participants[$victime]) || $participants[$victime]['side'] !== BattleTallyFacts::SIDE_DEFENDER) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'la victime de la manoeuvre de Hamill (' . $victime . ') n est pas une flotte defensive de la bataille');
            }

            if (!isset($participants[$auteur]) || $participants[$auteur]['side'] !== BattleTallyFacts::SIDE_ATTACKER) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'l auteur de la manoeuvre de Hamill (' . $auteur . ') n est pas une flotte attaquante de la bataille');
            }

            if (self::normalise($facts->preRoundLosses[$victime] ?? []) !== ['deathstar' => 1]) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'les pertes anterieures au premier round de la victime de Hamill ne sont pas exactement une Etoile de la Mort');
            }
        }

        $inconnues = $this->unknownUnits($facts, $version);

        if ($inconnues !== []) {
            return BattleTallyOutcome::pending(self::UNKNOWN_UNIT_FAMILY, 'unites hors catalogue pour la version ' . $version . ' : ' . implode(', ', $inconnues));
        }

        $sansPrix = $this->unitsWithoutPrice($facts);

        if ($sansPrix !== []) {
            return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'prix brut absent pour : ' . implode(', ', $sansPrix));
        }

        $reparationsParRound = $this->repairsPerRound($facts, $participants);

        if (is_string($reparationsParRound)) {
            return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, $reparationsParRound);
        }

        $forces = [];
        $cumul = [];

        foreach ($participants as $clef => $participant) {
            $anterieures = $facts->preRoundLosses[$clef] ?? [];
            $presentes = self::minus($participant['start'], $anterieures);

            if ($presentes === null) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'les pertes anterieures au premier round de ' . $clef . ' depassent son depart');
            }

            $forces[$clef] = $presentes;
            $cumul[$clef] = $anterieures;
        }

        $detruits = array_fill_keys(array_keys($participants), 0);

        foreach ($facts->rounds as $rang => $pertesDuRound) {
            $ecart = $this->attackerForcesDisagreeWithTheEngine($facts, $participants, $forces, $rang);

            if ($ecart !== null) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, $ecart);
            }

            $valeurDesForces = [BattleTallyFacts::SIDE_ATTACKER => [], BattleTallyFacts::SIDE_DEFENDER => []];

            foreach ($participants as $clef => $participant) {
                $valeur = $this->valueOf($forces[$clef], $facts->prices, $version);

                if ($valeur > 0) {
                    $valeurDesForces[$participant['side']][$clef] = $valeur;
                }
            }

            $pertesDefinitives = [BattleTallyFacts::SIDE_ATTACKER => 0, BattleTallyFacts::SIDE_DEFENDER => 0];

            foreach ($pertesDuRound as $clef => $unites) {
                if (!isset($participants[$clef])) {
                    return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'le round ' . ($rang + 1) . ' porte des pertes pour ' . $clef . ', qui ne participe pas');
                }

                $definitives = $clef === $facts->bodyKey && isset($reparationsParRound[$rang])
                    ? self::minus($unites, $reparationsParRound[$rang])
                    : $unites;

                if ($definitives === null) {
                    return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'la reparation du round ' . ($rang + 1) . ' depasse les pertes de la garnison');
                }

                $pertesDefinitives[$participants[$clef]['side']] += $this->valueOf($definitives, $facts->prices, $version);

                $restantes = self::minus($forces[$clef], $unites);

                if ($restantes === null) {
                    return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'les pertes de ' . $clef . ' au round ' . ($rang + 1) . ' depassent ses forces');
                }

                $forces[$clef] = $restantes;
                $cumul[$clef] = self::plus($cumul[$clef], $unites);
            }

            foreach ([BattleTallyFacts::SIDE_ATTACKER => BattleTallyFacts::SIDE_DEFENDER, BattleTallyFacts::SIDE_DEFENDER => BattleTallyFacts::SIDE_ATTACKER] as $camp => $adverse) {
                $total = $pertesDefinitives[$adverse];

                if ($total === 0) {
                    continue;
                }

                if ($valeurDesForces[$camp] === []) {
                    return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'le camp ' . $adverse . ' perd au round ' . ($rang + 1) . ' sans qu aucune force du camp ' . $camp . ' y soit presente');
                }

                foreach (LargestRemainder::split($total, $valeurDesForces[$camp]) as $clef => $part) {
                    $detruits[$clef] += $part;
                }
            }
        }

        $valeurs = [];

        foreach ($participants as $clef => $participant) {
            if (self::normalise($cumul[$clef]) !== self::normalise($participant['lost'])) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'les pertes des rounds de ' . $clef . ' ne recouvrent pas ses pertes definitives');
            }

            $pertes = $clef === $facts->bodyKey ? self::minus($participant['lost'], $facts->repaired) : $participant['lost'];

            if ($pertes === null) {
                return BattleTallyOutcome::pending(self::INCOHERENT_BATTLE, 'les defenses reparees depassent les pertes de la garnison');
            }

            $valeurs[$clef] = [
                'owner' => $participant['owner'],
                'npc' => $participant['npc'],
                'destroyed' => $detruits[$clef],
                'lost' => $this->valueOf($pertes, $facts->prices, $version),
            ];
        }

        // **L Etoile prise par la manoeuvre est un evenement nomme** : sa valeur va a l auteur, en « detruits », et
        // nulle part ailleurs — elle n a fait partie d aucun round, et la victime la compte une fois en « perdus ».
        $hamill = null;

        if ($facts->hamill !== null) {
            $auteur = $participants[$facts->hamill['author']];
            $hamill = [
                'author' => $facts->hamill['author'],
                'owner' => $auteur['owner'],
                'npc' => $auteur['npc'],
                'destroyed' => $this->valueOf(['deathstar' => 1], $facts->prices, $version),
            ];
        }

        return BattleTallyOutcome::computed($valeurs, $hamill);
    }

    /**
     * @return list<string>
     */
    private function unknownUnits(BattleTallyFacts $facts, string $version): array
    {
        $inconnues = [];

        foreach ($this->everyUnitName($facts) as $nom) {
            if (MilitaryValue::weightOf($nom, $version) === null) {
                $inconnues[] = $nom;
            }
        }

        return $inconnues;
    }

    /**
     * @return list<string>
     */
    private function unitsWithoutPrice(BattleTallyFacts $facts): array
    {
        $sansPrix = [];

        foreach ($this->everyUnitName($facts) as $nom) {
            if (!isset($facts->prices[$nom])) {
                $sansPrix[] = $nom;
            }
        }

        return $sansPrix;
    }

    /**
     * @return list<string>
     */
    private function everyUnitName(BattleTallyFacts $facts): array
    {
        $noms = [];

        foreach ($facts->participants as $participant) {
            $noms += array_fill_keys(array_keys($participant['start']), true);
            $noms += array_fill_keys(array_keys($participant['lost']), true);
        }

        foreach ($facts->rounds as $round) {
            foreach ($round as $unites) {
                $noms += array_fill_keys(array_keys($unites), true);
            }
        }

        foreach ($facts->attackerShipsPerRound as $round) {
            foreach ($round as $unites) {
                $noms += array_fill_keys(array_keys($unites), true);
            }
        }

        foreach ($facts->preRoundLosses as $unites) {
            $noms += array_fill_keys(array_keys($unites), true);
        }

        $noms += array_fill_keys(array_keys($facts->repaired), true);

        $liste = array_keys($noms);
        sort($liste);

        return $liste;
    }

    /**
     * La reparation de chaque type de defense, repartie sur les rounds au prorata des pertes de ce type, sans jamais
     * depasser les pertes d un round. Rend la raison de l incoherence, s il y en a une.
     *
     * @param array<string, array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}> $participants
     * @return array<int, array<string, int>>|string
     */
    private function repairsPerRound(BattleTallyFacts $facts, array $participants): array|string
    {
        if ($facts->repaired === []) {
            return [];
        }

        if (!isset($participants[$facts->bodyKey])) {
            return 'des defenses ont ete reparees sans garnison parmi les participants';
        }

        $parRound = [];

        foreach ($facts->repaired as $type => $reparees) {
            if ($reparees <= 0) {
                continue;
            }

            $pertesParRound = [];

            foreach ($facts->rounds as $rang => $round) {
                $perdues = $round[$facts->bodyKey][$type] ?? 0;

                if ($perdues > 0) {
                    $pertesParRound[$rang] = $perdues;
                }
            }

            if ($reparees > array_sum($pertesParRound)) {
                return 'la reparation de ' . $type . ' (' . $reparees . ') depasse ce que la garnison en a perdu dans les rounds (' . array_sum($pertesParRound) . ')';
            }

            foreach (LargestRemainder::split($reparees, $pertesParRound) as $rang => $part) {
                if ($part > 0) {
                    $parRound[(int)$rang][$type] = $part;
                }
            }
        }

        return $parRound;
    }

    /**
     * Les forces d une flotte attaquante au debut d un round sont exactement les restants que le moteur a figes a la
     * fin du round precedent ; un ecart dit que les faits ne se lisent pas comme le moteur les a ecrits.
     *
     * @param array<string, array{key: string, side: string, owner: int|null, npc: bool, start: array<string, int>, lost: array<string, int>}> $participants
     * @param array<string, array<string, int>> $forces
     */
    private function attackerForcesDisagreeWithTheEngine(BattleTallyFacts $facts, array $participants, array $forces, int $rang): string|null
    {
        if ($rang === 0 || !isset($facts->attackerShipsPerRound[$rang - 1])) {
            return null;
        }

        foreach ($facts->attackerShipsPerRound[$rang - 1] as $clef => $restants) {
            if (!isset($participants[$clef])) {
                return 'le moteur fige des restants pour ' . $clef . ', qui ne participe pas';
            }

            if (self::normalise($restants) !== self::normalise($forces[$clef])) {
                return 'les forces de ' . $clef . ' au round ' . ($rang + 1) . ' ne sont pas les restants que le moteur a figes a la fin du round ' . $rang;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $unites
     * @param array<string, int> $prix
     */
    private function valueOf(array $unites, array $prix, string $version): int
    {
        $valeur = 0;

        foreach ($unites as $nom => $nombre) {
            if ($nombre <= 0) {
                continue;
            }

            $valeur += $prix[$nom] * $nombre * (int)MilitaryValue::weightOf($nom, $version);
        }

        return $valeur;
    }

    /**
     * @param array<string, int> $unites
     * @param array<string, int> $retirees
     * @return array<string, int>|null `null` si une quantite deviendrait negative.
     */
    private static function minus(array $unites, array $retirees): array|null
    {
        $reste = $unites;

        foreach ($retirees as $nom => $nombre) {
            if ($nombre <= 0) {
                continue;
            }

            $reste[$nom] = ($reste[$nom] ?? 0) - $nombre;

            if ($reste[$nom] < 0) {
                return null;
            }
        }

        return self::normalise($reste);
    }

    /**
     * @param array<string, int> $unites
     * @param array<string, int> $ajoutees
     * @return array<string, int>
     */
    private static function plus(array $unites, array $ajoutees): array
    {
        $somme = $unites;

        foreach ($ajoutees as $nom => $nombre) {
            $somme[$nom] = ($somme[$nom] ?? 0) + $nombre;
        }

        return self::normalise($somme);
    }

    /**
     * Sans les quantites nulles, dans l ordre des noms : deux collections egales se comparent alors par `===`.
     *
     * @param array<string, int> $unites
     * @return array<string, int>
     */
    private static function normalise(array $unites): array
    {
        $propre = array_filter($unites, static fn (int $nombre): bool => $nombre > 0);
        ksort($propre);

        return $propre;
    }
}
