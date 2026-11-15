<?php

declare(strict_types=1);

namespace App\Roleplay\Banter;

use App\Ai\AgentText;
use App\Director\PlayerCharacter;
use App\Roleplay\AgentProvider;
use App\Roleplay\Personality\Humour;
use App\Roleplay\Personality\PersonalityStore;
use App\Roleplay\ReplySanitiser;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Model\Actor;
use App\World\Model\ChatChannel;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The world muttering to itself.
 *
 * A populated zone is not the same as an inhabited one. Fifty silent bots running past you is a
 * crowd scene; one of them grumbling about the Lich King as they pass is a world. This is the
 * cheapest possible version of that, and the most visible thing the project does.
 *
 * **Fire and forget, deliberately.** No history, no thread, no per-bot state: pick someone, make
 * a line, say it, forget it. Three properties make that safe rather than lossy, and all three
 * were checked rather than assumed:
 *
 *  - It goes out on /say, and `PartyVoiceSelector` does not treat /say as a party channel, so a
 *    muttering bot can never pull the party's voice away mid-conversation.
 *  - `rememberHeard()` only runs from the responder after a reply, so a banter line never enters
 *    scene memory by itself and cannot confuse a companion later.
 *  - The chat capture still logs it, so `recent_chat` shows the world talking. That is the only
 *    trace worth keeping and it comes free.
 *
 * The only state here is a clock.
 */
final class BanterDirector
{
    private float $lastSpokeAt = 0.0;

    public function __construct(
        private readonly AgentProvider $agents,
        private readonly WorldGatewayInterface $world,
        private readonly PlayerCharacter $player,
        private readonly PersonalityStore $personalities,
        private readonly RaidBosses $bosses,
        private readonly BotSpeechDispatcher $speech,
        private readonly ReplySanitiser $sanitiser,
        /** Nobody out of earshot: /say carries about this far, so a line nobody hears is a line wasted. */
        #[Autowire(env: 'float:ANM_BANTER_RADIUS')]
        private readonly float $radius = 25.0,
        /**
         * How often the world is allowed to mutter.
         *
         * Three minutes was the first guess and it was far too often in play: the lines are
         * good individually and relentless together, and a companion saying something every
         * few minutes stops reading as a world and starts reading as a feature. Half an hour
         * makes it a pleasant surprise, which is the whole point of it.
         */
        #[Autowire(env: 'int:ANM_BANTER_SECONDS')]
        private readonly int $everySeconds = 1800,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * One pass, if the interval has elapsed. Returns the line said, for the listener to show.
     *
     * The clock is set before the call rather than after: a slow or failed request must not leave
     * the gate open for the next tick a second later.
     */
    public function tick(): ?string
    {
        $now = microtime(true);

        if ($now - $this->lastSpokeAt < $this->everySeconds) {
            return null;
        }

        $this->lastSpokeAt = $now;

        return $this->speak();
    }

    /**
     * Mutter now, ignoring the interval — for trying lines out, and for filming, where the world
     * has to say something on cue.
     */
    public function tickNow(): ?string
    {
        $this->lastSpokeAt = microtime(true);

        return $this->speak();
    }

    private function speak(): ?string
    {
        $player = $this->player->current();

        if (null === $player || !$player->isOnline) {
            return null;
        }

        $speaker = $this->pickSomeoneNear($player);
        $boss = $this->bosses->random();

        if (null === $speaker || null === $boss) {
            return null;
        }

        $line = $this->composeFor($speaker, $boss);

        if (null === $line) {
            return null;
        }

        $this->speech->say($speaker->guid, $line, ChatChannel::Say);

        return \sprintf('%s: %s', $speaker->name, $line);
    }

    private function composeFor(Actor $speaker, string $boss): ?string
    {
        $personality = $this->personalities->of($speaker);

        $prompt = \sprintf(
            'You are %s, a %s %s %s levelling up to one day fight %s. '
            .'Say one %s sentence about that goal.',
            $speaker->name,
            $personality->adjectives(),
            $speaker->race->label(),
            strtolower($speaker->class->label()),
            $boss,
            self::toneFor($personality->humour),
        );

        try {
            $raw = AgentText::of($this->agents->banterAgent()->call($prompt)->getContent());
        } catch (\Throwable $e) {
            // Ambient flavour is never worth interrupting the listener for.
            $this->logger->warning('Banter failed: {why}', ['why' => $e->getMessage()]);

            return null;
        }

        return $this->sanitiser->sanitise($raw);
    }

    /**
     * The tone comes from the character rather than a coin flip, so a player who never speaks to
     * anyone still meets the personality system.
     */
    private static function toneFor(Humour $humour): string
    {
        return match ($humour) {
            Humour::Sardonic => 'sarcastic',
            Humour::Grave => 'fearless',
            Humour::Playful => 'funny',
            Humour::Dry => 'wry and understated',
        };
    }

    private function pickSomeoneNear(Actor $player): ?Actor
    {
        $nearby = array_values(array_filter(
            $this->world->actorsNear($player->position, $this->radius),
            static fn (Actor $a): bool => $a->isBot && $a->isOnline,
        ));

        return [] === $nearby ? null : $nearby[array_rand($nearby)];
    }
}
