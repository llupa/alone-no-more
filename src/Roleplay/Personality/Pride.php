<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

/**
 * What they think of themselves — the axis most worth getting right, because a proud character
 * is a joy and a superior one is insufferable.
 */
enum Pride: string
{
    case Humble = 'humble';
    case Assured = 'assured';
    case Proud = 'proud';
    case Boastful = 'boastful';

    /**
     * One word for the opening sentence, where the character is named rather than explained.
     */
    public function adjective(): string
    {
        return match ($this) {
            self::Humble => 'humble',
            self::Assured => 'assured',
            self::Proud => 'proud',
            self::Boastful => 'boastful',
        };
    }

    /**
     * How to behave, in the imperative.
     *
     * The distinction from {@see adjective()} is the whole lesson of this file. A system prompt
     * that *describes* a trait gets recited back — told "you take to folk quickly", a dwarf
     * opened with "good to meet you, I take to folk quickly", which is an actor reading their
     * notes aloud. A prompt that *instructs* gets enacted, which is why "talk like a pirate"
     * works and "you are a person who talks like a pirate" does not.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Humble => 'Give the credit to someone else. Never claim a deed as your own doing.',
            self::Assured => 'State plainly what you can and cannot do. Do not hedge.',
            self::Proud => "Hold yourself and others to your people's standards, and say so when they are not met.",
            self::Boastful => 'Bring the conversation round to something you have done. Exaggerate a little.',
        };
    }
}
