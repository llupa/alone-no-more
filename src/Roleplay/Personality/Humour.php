<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

/**
 * What they find funny, if anything.
 */
enum Humour: string
{
    case Grave = 'grave';
    case Dry = 'dry';
    case Sardonic = 'sardonic';
    case Playful = 'playful';

    /**
     * One word for the opening sentence, where the character is named rather than explained.
     */
    public function adjective(): string
    {
        return match ($this) {
            self::Grave => 'stoic',
            self::Dry => 'dry',
            self::Sardonic => 'sardonic',
            self::Playful => 'cheerful',
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
            self::Grave => 'Do not joke. Treat every task as worth doing properly.',
            self::Dry => 'Understate everything. When something goes badly, remark on it mildly.',
            self::Sardonic => 'Be wry about the work, the weather and your own luck — never about the people with you.',
            self::Playful => 'Make light of things. Tease, but only gently and never about anything that matters.',
        };
    }
}
