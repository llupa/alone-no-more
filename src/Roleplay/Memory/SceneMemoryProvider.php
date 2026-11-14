<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use App\Roleplay\Tool\BotCommandContext;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Memory\Memory;
use Symfony\AI\Agent\Memory\MemoryProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What a bot knows that it was never told directly.
 *
 * Two things reach a bot as memory rather than as dialogue, and the distinction is the whole
 * design. Its own exchanges with the player arrive as real turns in the MessageBag, because a
 * model reads its own words as its own only in the assistant role — narrating them into the
 * system prompt is what once had a bot say "I don't recall speaking with you before" while
 * holding the transcript.
 *
 * But what a *companion* said was never this bot's words, and what happened in past meetings is
 * not this conversation. Those are reference material, and the system prompt is where reference
 * material belongs. Putting a companion's line in the assistant role would be worse than
 * useless: the bot would believe it had said it, and a priest would start talking about the
 * shield she was keeping up.
 */
final readonly class SceneMemoryProvider implements MemoryProviderInterface
{
    public function __construct(
        private ConversationStoreInterface $store,
        private BotCommandContext $context,
        /**
         * Companion lines carried into the prompt. Small on purpose: this is what the bot missed
         * while someone else was answering, not a transcript of the evening.
         */
        #[Autowire(env: 'int:ANM_SCENE_MEMORY_LINES')]
        private int $sceneLines = 6,
    ) {
    }

    /**
     * @return list<Memory>
     */
    public function load(Input $input): array
    {
        // The agent is also used outside a conversation — `anm:ask` talks to the archivist with
        // no bot acting at all — and a memory provider firing there would be nonsense.
        if (!$this->context->isActive()) {
            return [];
        }

        $bot = $this->context->bot();
        $speaker = $this->context->speaker();

        $memories = [];

        $overheard = $this->overheard($bot->guid, $speaker->guid);

        if (null !== $overheard) {
            $memories[] = new Memory($overheard);
        }

        $acquaintance = $this->acquaintance($bot->guid, $speaker->guid, $speaker->name);

        if (null !== $acquaintance) {
            $memories[] = new Memory($acquaintance);
        }

        return $memories;
    }

    /**
     * What companions said while this bot was silent.
     */
    private function overheard(int $botGuid, int $speakerGuid): ?string
    {
        $sceneKey = $this->context->sceneKey();

        if (null === $sceneKey) {
            return null;
        }

        $lines = $this->store->sceneLines($sceneKey, [$botGuid, $speakerGuid], $this->sceneLines);

        if ([] === $lines) {
            return null;
        }

        $said = array_map(
            static fn (array $line): string => \sprintf('- %s said: "%s"', $line['speaker'], $line['message']),
            $lines,
        );

        return "You have been travelling with others, and you overheard them say this recently.\n"
            ."These are not your words and you were not spoken to:\n"
            .implode("\n", $said)."\n"
            .'Do not answer them, and do not repeat something a companion has already said.';
    }

    /**
     * How well this bot knows the person in front of it.
     */
    private function acquaintance(int $botGuid, int $playerGuid, string $playerName): ?string
    {
        $known = $this->store->acquaintance($botGuid, $playerGuid);

        if (null === $known || $known['exchanges'] < 1) {
            return null;
        }

        return \sprintf(
            'You have met %s before. You have spoken %d times, most recently on %s.',
            $playerName,
            $known['exchanges'],
            $known['last_seen'],
        );
    }
}
