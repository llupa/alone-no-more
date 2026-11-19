<?php

declare(strict_types=1);

namespace App\World\Chat;

use App\World\Exception\WorldGatewayException;
use App\World\Model\ChatChannel;
use App\World\OutboxInterface;

/**
 * Makes a bot speak, by queueing a row in `anm_outbox` for mod-anm-bridge to dispatch on the
 * world thread (it drains the table every 500 ms).
 *
 * This is a queue rather than a direct call because chat has to be emitted from inside the
 * worldserver: there is no core or playerbot command that makes a bot say arbitrary text, and
 * a PHP process cannot touch a Player object. Queueing also means an LLM reply that arrives
 * while the bot is logging out simply fails one row instead of breaking the conversation.
 */
final readonly class BotSpeechDispatcher
{
    public function __construct(
        private OutboxInterface $outbox,
    ) {
    }

    /**
     * @return int the outbox row id, so the caller can follow up on delivery
     */
    public function say(int $botGuid, string $message, ChatChannel $channel, int $targetGuid = 0): int
    {
        $message = trim($message);

        if ('' === $message) {
            throw new WorldGatewayException('Refusing to queue an empty line of bot speech.');
        }

        if (ChatChannel::Whisper === $channel && 0 === $targetGuid) {
            throw new WorldGatewayException('A whisper needs a target.');
        }

        // Party replies carry the listener too. The bridge checks they are really in the bot's
        // live group before broadcasting, because `group_member` can outlive the group itself.

        return $this->queue($botGuid, $channel->replyAction(), $message, $targetGuid);
    }

    /**
     * A visible emote ("/me glances up from the campfire"), used to telegraph that a bot is
     * about to answer while the model is still generating.
     */
    public function emote(int $botGuid, string $message): int
    {
        return $this->queue($botGuid, 'emote', trim($message), 0);
    }

    /**
     * Set a bot on the nearest thing it may lawfully attack, optionally filtered by name.
     *
     * mod-playerbots can only attack whatever the *master* has selected, so "attack the kobolds"
     * from someone who has clicked nothing left the bot standing still — while the model
     * answered "right, I'm on it". Our own module picks the target instead, which is the one
     * thing the server could do and the mod would not.
     *
     * @param string $what part of a creature's name, or empty for whatever is nearest
     */
    public function attack(int $botGuid, string $what = ''): int
    {
        return $this->queue($botGuid, 'attack', trim($what), 0);
    }

    /**
     * Bring a bot into the world. Unlike every other action here this one targets a bot that is
     * *not* online, which is the whole point.
     */
    public function login(int $botGuid): int
    {
        return $this->queue($botGuid, 'login', '', 0);
    }

    /**
     * Send a bot away again, freeing the slot for somewhere the player actually is.
     */
    public function logout(int $botGuid): int
    {
        return $this->queue($botGuid, 'logout', '', 0);
    }

    /**
     * Delivery state of a queued line: pending, done or failed. Null when the row is gone.
     */
    /**
     * Wait for the server to carry an order out, and say what happened.
     *
     * Chat is queued and forgotten because a line either arrives or does not and nobody is
     * waiting on it. An order is different: a companion that says "going for the wolf" and then
     * stands still is the failure this whole feature exists to remove, and it had crept back in
     * one layer down — the module wrote "cannot be attacked" into the outbox and nobody read it.
     *
     * The wait is short and bounded. The module polls on a tick, so an answer normally arrives
     * well inside it; timing out is reported as uncertainty rather than success.
     *
     * @return array{done: bool, error: string} error is empty when it worked
     */
    public function awaitOutcome(int $outboxId, float $seconds = 2.0): array
    {
        return $this->outbox->awaitOutcome($outboxId, $seconds);
    }

    public function statusOf(int $outboxId): ?string
    {
        return $this->outbox->statusOf($outboxId);
    }

    private function queue(int $botGuid, string $action, string $message, int $targetGuid): int
    {
        return $this->outbox->queue($botGuid, $action, $message, $targetGuid);
    }
}
