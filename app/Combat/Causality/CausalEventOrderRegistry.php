<?php

namespace OGame\Combat\Causality;

use InvalidArgumentException;
use OGame\Combat\Exceptions\UnknownCausalEventOrderVersion;

/**
 * Les ordres causals connus, et celui qui sert aux nouveaux combats.
 *
 * ## `current()` ne s'appelle qu'une fois
 *
 * A **l'ouverture logique durable** du combat, dans la transaction qui la fixe. La version est
 * alors persistee avec l'instance, entre dans les faits geles et dans leur empreinte.
 *
 * Ensuite, plus jamais : tout worker, tout rejeu, tout rechargement passe par
 * `forVersion($combat->causal_event_order_version)`. Un worker reveille apres le deploiement d'une
 * v2 ne doit pas convertir en v2 une ouverture deja fixee sous v1 au seul motif qu'il a travaille
 * en retard.
 *
 * ## Une version inconnue arrete le traitement
 *
 * Aucun repli vers la version courante. Appliquer une autre regle a un combat deja ouvert
 * reordonnerait ses effets simultanes — une defense achevee cesserait d'etre detruite par le
 * missile de la meme seconde, ou l'inverse — sans que personne ne l'ait demande.
 */
final readonly class CausalEventOrderRegistry
{
    /**
     * @param array<string, CausalEventOrder> $orders Les implementations reconnues, par version.
     * @param string $defaultVersion La version inscrite sur les nouveaux combats.
     */
    private function __construct(
        private array $orders,
        private string $defaultVersion,
    ) {
    }

    /**
     * Le registre du jeu.
     */
    public static function default(): self
    {
        return self::of([new CausalEventOrderV1()], CausalEventOrderV1::VERSION);
    }

    /**
     * Un registre construit sur mesure.
     *
     * @param array<int, CausalEventOrder> $orders
     * @param string $defaultVersion
     * @return self
     */
    public static function of(array $orders, string $defaultVersion): self
    {
        $connus = [];

        foreach ($orders as $order) {
            $version = $order->version();

            if (isset($connus[$version])) {
                throw new InvalidArgumentException(
                    'Deux ordres se reclament de la version « ' . $version . ' » : une version ne peut designer '
                    . 'qu un seul classement, sans quoi elle ne dit plus rien de ce qui a ete calcule.'
                );
            }

            $connus[$version] = $order;
        }

        if (!isset($connus[$defaultVersion])) {
            throw new InvalidArgumentException(
                'La version par defaut « ' . $defaultVersion . ' » ne figure pas parmi les ordres reconnus : les '
                . 'nouveaux combats se reclameraient d un classement que rien ne sait appliquer.'
            );
        }

        return new self($connus, $defaultVersion);
    }

    /**
     * L'ordre qui servira aux nouveaux combats.
     *
     * **A n'appeler qu'a l'ouverture logique durable.** Partout ailleurs, `forVersion()`.
     */
    public function current(): CausalEventOrder
    {
        return $this->orders[$this->defaultVersion];
    }

    /**
     * La version inscrite sur les nouveaux combats.
     */
    public function currentVersion(): string
    {
        return $this->defaultVersion;
    }

    /**
     * L'ordre d'une version persistee.
     *
     * @param string $version
     * @return CausalEventOrder
     */
    public function forVersion(string $version): CausalEventOrder
    {
        return $this->orders[$version]
            ?? throw new UnknownCausalEventOrderVersion(
                'Aucun ordre causal ne porte la version « ' . $version . ' ». Les versions reconnues sont : '
                . implode(', ', $this->knownVersions()) . '. Appliquer un autre classement reordonnerait les '
                . 'effets simultanes d un combat deja ouvert.'
            );
    }

    /**
     * Les versions que ce registre sait appliquer.
     *
     * @return array<int, string>
     */
    public function knownVersions(): array
    {
        return array_keys($this->orders);
    }
}
