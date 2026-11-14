<?php

declare(strict_types=1);

namespace App\Roleplay\Memory;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One live conversation per bot-and-player pair, held as a MessageBag.
 *
 * Two reasons this is a bag of real turns rather than history narrated into the system prompt.
 *
 * The first is correctness. When the transcript was prose inside the system prompt, the model
 * read it as reference material someone had handed it, and answered "I don't recall speaking
 * with you before" while literally holding the exchange. We suppressed that by ordering it never
 * to say so. As assistant turns the problem does not arise: the bot is looking at its own words,
 * in the position a model is trained to read its own words.
 *
 * The second is that the system prompt becomes stable per bot while only the turns grow, which
 * is the shape prompt caching wants.
 *
 * The bag is hot memory and dies with the process; SQLite remains the record. On first use for a
 * pair we hydrate from the store, so restarting the listener does not give you amnesiac bots.
 */
final class ConversationBags
{
    /** @var array<string, MessageBag> */
    private array $bags = [];

    public function __construct(
        private readonly ConversationStoreInterface $store,
        /**
         * Turns kept in the bag — roughly half of them the player's, half the bot's. Ten
         * exchanges is enough for a conversation to hold its thread without spending the whole
         * latency budget re-reading itself.
         */
        #[Autowire(env: 'int:ANM_HOT_MEMORY_MESSAGES')]
        private readonly int $maxMessages = 20,
    ) {
    }

    /**
     * The conversation so far, ready for the system prompt to be prepended.
     */
    public function forPair(int $botGuid, int $playerGuid, string $botName): MessageBag
    {
        return $this->bags[self::key($botGuid, $playerGuid)] ??= $this->hydrate($botGuid, $playerGuid, $botName);
    }

    /**
     * The party's conversation, held once rather than once per member.
     *
     * A party is one conversation. Keeping a thread per bot meant each held only the half it
     * spoke, so whoever answered next had not heard the question — the single biggest cause of
     * companions talking past each other.
     *
     * Not hydrated from the store: the pairwise transcript belongs to a bot and a player, and
     * this belongs to a room. A restart starts the room fresh, and scene memory carries what was
     * said anyway.
     */
    public function forParty(string $partyKey): MessageBag
    {
        return $this->bags[$partyKey] ??= new MessageBag();
    }

    public function recordForParty(string $partyKey, string $said, string $answered): void
    {
        $bag = $this->bags[$partyKey] ?? new MessageBag();

        $this->bags[$partyKey] = self::trim(
            $bag->with(Message::ofUser($said))->with(Message::ofAssistant($answered)),
            $this->maxMessages,
        );
    }

    /**
     * Drop a party's turns, keeping nothing.
     *
     * Called when the voice changes hands. The turns are stored in the assistant role — they are
     * the *previous* speaker's own words, and handing them to a successor would have it reading
     * someone else's lines as its own. The history is not lost: it reaches the new voice through
     * scene memory, as something it overheard, which is exactly what it was.
     */
    public function resetParty(string $partyKey): void
    {
        unset($this->bags[$partyKey]);
    }

    /**
     * Append what was just said and answered, trimming to the window.
     */
    public function record(int $botGuid, int $playerGuid, string $said, string $answered): void
    {
        $key = self::key($botGuid, $playerGuid);
        $bag = $this->bags[$key] ?? new MessageBag();

        $this->bags[$key] = self::trim(
            $bag->with(Message::ofUser($said))->with(Message::ofAssistant($answered)),
            $this->maxMessages,
        );
    }

    public function hasSpokenWith(int $botGuid, int $playerGuid): bool
    {
        $bag = $this->bags[self::key($botGuid, $playerGuid)] ?? null;

        return null !== $bag
            ? [] !== $bag->getMessages()
            : $this->store->hasSpokenBefore($botGuid, $playerGuid);
    }

    /**
     * Drop a conversation from hot memory. The record in SQLite is untouched.
     */
    public function forget(int $botGuid, int $playerGuid): void
    {
        unset($this->bags[self::key($botGuid, $playerGuid)]);
    }

    private function hydrate(int $botGuid, int $playerGuid, string $botName): MessageBag
    {
        $bag = new MessageBag();

        foreach ($this->store->recent($botGuid, $playerGuid, $this->maxMessages) as $turn) {
            // Whose turn it was decides the role. A bot's own past lines must come back as
            // assistant messages or the whole point is lost.
            $bag = $bag->with(
                $turn['speaker'] === $botName
                    ? Message::ofAssistant($turn['message'])
                    : Message::ofUser($turn['message']),
            );
        }

        return self::trim($bag, $this->maxMessages);
    }

    /**
     * Keep the newest messages. MessageBag is immutable, so this builds a fresh one rather than
     * shifting in place.
     */
    private static function trim(MessageBag $bag, int $max): MessageBag
    {
        $messages = $bag->getMessages();

        if (\count($messages) <= $max) {
            return $bag;
        }

        return new MessageBag(...\array_slice($messages, -$max));
    }

    private static function key(int $botGuid, int $playerGuid): string
    {
        return $botGuid.':'.$playerGuid;
    }
}
