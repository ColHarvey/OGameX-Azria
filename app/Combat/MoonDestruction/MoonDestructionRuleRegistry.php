<?php

namespace OGame\Combat\MoonDestruction;

use InvalidArgumentException;
use OGame\Combat\Exceptions\UnknownMoonDestructionRuleVersion;

/**
 * Les regles de destruction connues, et celle qui sert aux nouveaux plans.
 *
 * ## Le meme principe que le pipeline de pillage
 *
 * Persister `moon_destruction_odds_v1` ne protege un plan que si cette version **existe encore** le
 * jour ou une v2 arrive. Comparer la version persistee a la version courante ne protege rien : le
 * jour ou la constante change, tous les plans en cours deviennent illisibles.
 *
 * Le registre separe donc la **version par defaut** — celle qui sera inscrite sur les nouveaux plans
 * — des **implementations reconnues**, celles qu'on sait encore appliquer.
 *
 * ## Ce que l'echeance ne consulte pas
 *
 * Ni le registre courant, ni le hasard, ni le diametre vivant de la lune. Les resultats sont geles :
 * appliquer un plan revient a le relire. Le registre ne sert qu'a **produire** un plan, et a relire
 * les chances d'un ancien plan quand un audit veut les recalculer.
 *
 * ## Pourquoi immuable
 *
 * Un registre global modifiable permettrait a un test de faire devenir v2 la version courante, et le
 * suivant heriterait de cet etat. Ici, un test construit **son** registre et demande explicitement la
 * version qu'il veut : la demonstration ne laisse aucune trace.
 */
final readonly class MoonDestructionRuleRegistry
{
    /**
     * @param array<string, MoonDestructionRule> $rules Les implementations reconnues, par version.
     * @param string $defaultVersion La version inscrite sur les nouveaux plans.
     */
    private function __construct(
        private array $rules,
        private string $defaultVersion,
    ) {
    }

    /**
     * Le registre du jeu.
     */
    public static function default(): self
    {
        return self::of([new MoonDestructionRuleV1()], MoonDestructionRuleV1::VERSION);
    }

    /**
     * Un registre construit sur mesure.
     *
     * @param array<int, MoonDestructionRule> $rules
     * @param string $defaultVersion
     * @return self
     */
    public static function of(array $rules, string $defaultVersion): self
    {
        $connues = [];

        foreach ($rules as $rule) {
            $version = $rule->version();

            if (isset($connues[$version])) {
                throw new InvalidArgumentException(
                    'Deux implementations se reclament de la version « ' . $version . ' » : une version ne peut '
                    . 'designer qu une seule regle, sans quoi elle ne dit plus rien de ce qui a ete calcule.'
                );
            }

            $connues[$version] = $rule;
        }

        if (!isset($connues[$defaultVersion])) {
            throw new InvalidArgumentException(
                'La version par defaut « ' . $defaultVersion . ' » ne figure pas parmi les implementations '
                . 'reconnues : les nouveaux plans se reclameraient d une regle que rien ne sait appliquer.'
            );
        }

        return new self($connues, $defaultVersion);
    }

    /**
     * La regle qui servira aux nouveaux plans.
     */
    public function current(): MoonDestructionRule
    {
        return $this->rules[$this->defaultVersion];
    }

    /**
     * La version inscrite sur les nouveaux plans.
     */
    public function currentVersion(): string
    {
        return $this->defaultVersion;
    }

    /**
     * La regle d'une version persistee.
     *
     * **Jamais de repli sur la version courante.** Un plan calcule sous une regle doit etre relu
     * sous cette regle-la ; lui en appliquer une autre changerait ses chances sans que personne ne
     * l'ait demande. Une version inconnue est un refus explicite, jamais un choix par defaut.
     *
     * @param string $version
     * @return MoonDestructionRule
     */
    public function forVersion(string $version): MoonDestructionRule
    {
        return $this->rules[$version]
            ?? throw new UnknownMoonDestructionRuleVersion(
                'Aucune regle de destruction ne porte la version « ' . $version . ' ». Les versions reconnues '
                . 'sont : ' . implode(', ', $this->knownVersions()) . '. Appliquer une autre regle changerait '
                . 'le resultat d un plan deja calcule.'
            );
    }

    /**
     * Les versions que ce registre sait appliquer.
     *
     * @return array<int, string>
     */
    public function knownVersions(): array
    {
        return array_keys($this->rules);
    }
}
