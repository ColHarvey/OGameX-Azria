<?php

namespace OGame\GameMissions\BattleEngine;

use OGame\Combat\Exceptions\RustEngineContractMismatch;

/**
 * Ce que la bibliotheque Rust a repondu, juge avant d'etre lu comme une bataille.
 *
 * ## Pourquoi une classe a part
 *
 * Le jugement ne depend pas de FFI : il porte sur une chaine. Le tenir hors du moteur permet de
 * l'eprouver sur un poste sans bibliotheque, avec les quatre reponses qu'il refuse et celle qu'il
 * admet — la ou le moteur lui-meme ne peut etre eprouve que la ou le `.so` existe.
 *
 * ## Ce qui est refuse
 *
 * Un document illisible ; un document d'erreur — le moteur Rust ne laisse plus passer de panique,
 * une entree qu'il ne lit pas ou une version qu'il ne parle pas reviennent ainsi, avec leur raison ;
 * un document d'une autre version, meme lisible, parce qu'il ne dit pas ce que ce client attend ;
 * un document sans round.
 */
final class RustEngineAnswer
{
    /**
     * Le document de bataille, ou un refus qui nomme la raison.
     *
     * @return array<string, mixed>
     */
    public static function battleOutputFrom(string $json, int $expectedSchema): array
    {
        $document = json_decode($json, true);

        if (!is_array($document)) {
            throw RustEngineContractMismatch::becauseTheAnswerIs('le document ne se lit pas (' . json_last_error_msg() . ')');
        }

        if (isset($document['error'])) {
            throw RustEngineContractMismatch::becauseTheAnswerIs(is_string($document['error']) ? $document['error'] : 'erreur sans message');
        }

        if (($document['schema'] ?? null) !== $expectedSchema) {
            throw RustEngineContractMismatch::becauseTheAnswerIs('le document est au schema ' . var_export($document['schema'] ?? null, true) . ', ce client attend le schema ' . $expectedSchema);
        }

        if (!isset($document['rounds']) || !is_array($document['rounds'])) {
            throw RustEngineContractMismatch::becauseTheAnswerIs('le document ne porte aucun round');
        }

        return $document;
    }
}
