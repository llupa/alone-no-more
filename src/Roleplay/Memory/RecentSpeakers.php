<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use App\Herding\ProtectedBots;
use App\World\Model\Actor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Companions who have said something lately, and must not be sent away.
 *
 * A bot vanishing between your question and its answer reads as the world breaking rather than
 * as housekeeping.
 *
 * Expressed as something the herder may ask for, rather than something the herder goes and
 * fetches: it used to reach into the conversation store directly, which meant the crowd layer
 * knew what a conversation was. It does not need to. It needs to know that some bots are spoken
 * for, and why is none of its business.
 */
final readonly class RecentSpeakers implements ProtectedBots
{
    public function __construct(
        private ConversationStoreInterface $conversations,
        #[Autowire(env: 'int:ANM_HERD_PROTECT_SPOKEN_WITHIN')]
        private int $withinSeconds = 600,
    ) {
    }

    /**
     * @return int[]
     */
    public function protectedFor(Actor $player): array
    {
        return $this->conversations->recentlyActiveBots($this->withinSeconds);
    }
}
