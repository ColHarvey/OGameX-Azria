<?php

namespace OGame\Combat\Services;

use Illuminate\Support\Facades\Date;
use OGame\Combat\Enums\LootReservationState;
use OGame\Combat\Support\AttackerCargoShare;
use OGame\Combat\Support\LootPolicy;
use OGame\Models\CombatLootReservation;
use OGame\Models\Planet;
use OGame\Models\User;

/**
 * Ce que le defenseur ne pourra plus mettre a l'abri.
 *
 * ## Le probleme que la reservation resout
 *
 * Un combat dure deux heures, et le butin se calcule sur les ressources presentes **a la
 * photographie**. Sans rien d'autre, le defenseur passe ces deux heures a vider ses caisses : il
 * construit, il envoie des transports, et l'attaquant repart avec un butin calcule sur des
 * ressources qui n'existent plus.
 *
 * Geler toute la planete serait l'exces inverse : le defenseur ne pourrait plus rien construire
 * pendant deux heures, defenses comprises, et se verrait puni d'avoir ete attaque.
 *
 * **Seule la part pillable est immobilisee.** Ce qui est produit pendant la bataille appartient au
 * defenseur.
 *
 * ## Pourquoi a l'ouverture, et non a la fermeture
 *
 * `LootReservation::open()` le dit deja : attendre la fermeture laisserait au defenseur la duree du
 * ralliement pour depenser ce qui allait etre photographie. Soixante secondes suffisent a lancer un
 * transport.
 *
 * ## La borne monte, elle ne descend jamais
 *
 * A l'ouverture, on ne sait pas encore quelles flottes seront admises — donc pas si un Decouvreur
 * en fera partie. La borne est donc posee **au taux de base**, sans ponderation de fret, et la
 * fermeture la releve si les admis y donnent droit.
 *
 * C'est le sens de `ensureAtLeast()` : une borne plus basse est ignoree. Laisser le solde
 * disponible du defenseur remonter lui annoncerait que la composition adverse a change — un
 * renseignement qu'il n'a pas a obtenir avant le rapport.
 *
 * ## Ce que cette classe ne fait pas encore
 *
 * Elle ne releve pas la borne pour les Decouvreurs admis, et ne l'ajuste pas pour une cargaison
 * livree pendant le ralliement. Ces deux-la demandent le fret libre de chaque flotte, que la
 * fermeture ne connait pas sans passer par le moteur de bataille. Le dire evite de croire la
 * reservation terminee.
 */
final class LootReservationOpener
{
    /**
     * Le nombre de jours d'absence au-dela duquel une cible est inactive.
     *
     * Reprend `LiveLootContextFactory::INACTIVITY_THRESHOLD_DAYS` : deux seuils differents pour la
     * meme question donneraient deux taux pour un meme combat.
     */
    public const int INACTIVITY_THRESHOLD_DAYS = 7;

    /**
     * Immobilise la part pillable du corps vise, au moment de l'ouverture.
     *
     * **Idempotente.** Une seconde ouverture du meme combat ne cree pas une seconde reservation :
     * la colonne est unique, et cette methode rend celle qui existe. Deux reservations sur un meme
     * combat immobiliseraient deux fois les memes ressources, et la resolution en distribuerait le
     * double.
     *
     * @param int $combatInstanceId Le combat qui tient ces ressources.
     * @param int $targetBodyId Le corps dont la part pillable est immobilisee.
     * @param string $policyVersion La politique de taux **gelee avec le combat**.
     * @param int $openedAt L'instant de l'ouverture, en secondes.
     */
    public function openFor(
        int $combatInstanceId,
        int $targetBodyId,
        string $policyVersion,
        int $openedAt,
    ): CombatLootReservation {
        $corps = Planet::find($targetBodyId);

        $part = $corps === null
            ? ['metal' => 0, 'crystal' => 0, 'deuterium' => 0]
            : $this->lootableShareOf($corps, $policyVersion, $openedAt);

        return CombatLootReservation::query()->firstOrCreate(
            ['combat_instance_id' => $combatInstanceId],
            [
                'target_body_id' => $targetBodyId,
                'metal' => $part['metal'],
                'crystal' => $part['crystal'],
                'deuterium' => $part['deuterium'],
                'state' => LootReservationState::Open->value,
                'opened_at' => $openedAt,
            ]
        );
    }

    /**
     * La part pillable du stock, au taux de base de la politique gelee.
     *
     * **Arrondie vers le bas, composante par composante.** Immobiliser une unite de plus que ce qui
     * sera pillable la retirerait au defenseur sans qu'elle profite a personne.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function lootableShareOf(Planet $body, string $policyVersion, int $openedAt): array
    {
        $politique = new LootPolicy(
            $this->ownerIsInactiveAt($body, $openedAt),
            // **Aucun fret engage.** Les flottes admises ne sont pas encore connues ; la borne
            // posee ici est donc la plus basse defendable, et la fermeture la releve.
            AttackerCargoShare::none(),
            version: $policyVersion,
        );

        $taux = $politique->maximumRateInBasisPoints();

        return [
            'metal' => intdiv((int)$body->metal * $taux, 10_000),
            'crystal' => intdiv((int)$body->crystal * $taux, 10_000),
            'deuterium' => intdiv((int)$body->deuterium * $taux, 10_000),
        ];
    }

    /**
     * Si le proprietaire du corps est inactif a cet instant.
     *
     * La frontiere est **fermee du cote inactif** : a exactement sept jours, la cible est inactive.
     * Un corps sans proprietaire n'est ni actif ni inactif — il ne donne aucun bonus.
     */
    private function ownerIsInactiveAt(Planet $body, int $observedAt): bool
    {
        $proprietaire = User::find($body->user_id);

        if ($proprietaire === null) {
            return false;
        }

        $seuil = Date::createFromTimestamp($observedAt, 'UTC')
            ->subDays(self::INACTIVITY_THRESHOLD_DAYS)
            ->getTimestamp();

        return (int)$proprietaire->time <= $seuil;
    }
}
