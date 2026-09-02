<?php

namespace OGame\Combat\Support;

use Illuminate\Support\Facades\Log;

/**
 * Le seul endroit qui ecrit ce que les conversions de ressources ont rencontre.
 *
 * ## Pourquoi la journalisation vit ici, et nulle part en amont
 *
 * La frontiere, le pipeline, la facade de distribution, les politiques et le moteur **rendent**
 * leurs diagnostics sans les ecrire. Trois raisons :
 *
 * - une seule resolution de combat traverse la distribution **six fois** — le butin, les Faucheurs
 *   des deux camps, le plafonnement de leur place, et deux plafonds de cargaison de retour. Un
 *   avertissement pose en amont produirait six lignes pour une operation ;
 * - un calculateur qui journalise n'est plus rejouable : rejouer les memes faits geles produirait
 *   un second journal, et recharger un resultat relancerait la normalisation ;
 * - l'orchestrateur est le seul a connaitre l'identite de l'operation, sans laquelle une trace ne
 *   sert a rien.
 *
 * ## Pourquoi seule une enveloppe scellee est acceptee
 *
 * Une collection brute ne porte que des identites **locales** : `attacker_reaper||metal` designe le
 * meme incident dans deux combats differents. Exiger l'enveloppe rend impossible, par le type, de
 * journaliser des diagnostics dont on ignore a quelle operation ils appartiennent.
 *
 * ## Ce que « une fois » signifie aujourd'hui
 *
 * Une fois **par execution de l'operation**. L'unicite entre deux livraisons d'un meme travail
 * differe appartient a l'idempotence du chemin persistant, qui n'existe pas encore : ne pas
 * confondre les deux.
 */
final class ResourceDiagnosticsJournal
{
    /**
     * Ecrit une fois ce que toute l'operation a rencontre.
     *
     * @param SealedResourceDiagnostics $scelle
     * @param array<string, mixed> $identity De quoi retrouver l'operation dans les journaux.
     * @return void
     */
    public static function report(SealedResourceDiagnostics $scelle, array $identity = []): void
    {
        if (!$scelle->any()) {
            return;
        }

        Log::warning('Frontiere des ressources : conversions signalees.', [
            'operation' => $scelle->operation->asString(),
            'operation_kind' => $scelle->operation->kind->value,
            'operation_id' => $scelle->operation->identifier,
            ...$identity,
            'diagnostics' => $scelle->diagnostics->groupedByCode(),
        ]);
    }
}
