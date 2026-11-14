<?php

declare(strict_types=1);

namespace App\Roleplay\Tool;

use App\World\Model\Actor;

/**
 * Which bot is currently speaking, to whom, and in which conversation.
 *
 * Tools are invoked by the model, which knows nothing of our domain — it just says "follow".
 * The responder sets the acting bot here before handing the conversation to the agent, so a tool
 * can act as *that* bot rather than needing the model to name it (and get it wrong). The memory
 * provider reads the same context for the same reason: the agent hands it an Input, which cannot
 * say which of fifty bots is about to speak.
 *
 * Mutable, deliberately: this is a single conversation being handled start to finish in one
 * process. If the listener ever handles conversations concurrently this must become scoped
 * state rather than a shared service.
 */
final class BotCommandContext
{
    private ?Actor $bot = null;
    private ?Actor $speaker = null;

    /** Null for a whisper, which belongs to no shared scene. */
    private ?string $sceneKey = null;

    public function enter(Actor $bot, Actor $speaker, ?string $sceneKey = null): void
    {
        $this->bot = $bot;
        $this->speaker = $speaker;
        $this->sceneKey = $sceneKey;
    }

    public function leave(): void
    {
        $this->bot = null;
        $this->speaker = null;
        $this->sceneKey = null;
    }

    public function sceneKey(): ?string
    {
        return $this->sceneKey;
    }

    public function bot(): Actor
    {
        return $this->bot ?? throw new \LogicException('No bot is acting. A tool was called outside a conversation.');
    }

    public function speaker(): Actor
    {
        return $this->speaker ?? throw new \LogicException('No speaker is set. A tool was called outside a conversation.');
    }

    public function isActive(): bool
    {
        return null !== $this->bot;
    }
}
