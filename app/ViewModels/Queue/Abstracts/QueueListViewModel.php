<?php

namespace OGame\ViewModels\Queue\Abstracts;

use OGame\Queues\QueueCapacity;

class QueueListViewModel
{
    /**
     * Constructor.
     *
     * @param array<QueueViewModel> $queue
     */
    public function __construct(
        /**
         * List of queue items.
         */
        public array $queue
    ) {
    }

    /**
     * Les travaux qui peuvent **attendre** dans cette file, celui en cours non compris. L interface et le serveur
     * lisent la meme regle (`QueueCapacity`) ; le service pose ici ce qu elle rend pour ce joueur.
     */
    public int $waitingAllowed = QueueCapacity::WAITING_BASE;

    /**
     * Get amount of items in the queue.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Les travaux qui attendent. Les files qui distinguent le travail en cours le redefinissent.
     */
    public function waitingCount(): int
    {
        return count($this->queue);
    }

    /**
     * La file est pleine quand elle porte deja tout ce qui peut **attendre** — le travail en cours ne compte pas.
     */
    public function isQueueFull(): bool
    {
        return $this->waitingCount() >= $this->waitingAllowed;
    }
}
