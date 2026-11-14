<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

/**
 * How they put a sentence together.
 */
enum Manner: string
{
    case Blunt = 'blunt';
    case Plain = 'plain';
    case Formal = 'formal';
    case Archaic = 'archaic';

    /**
     * One word for the opening sentence, where the character is named rather than explained.
     */
    public function adjective(): string
    {
        return match ($this) {
            self::Blunt => 'blunt',
            self::Plain => 'plain-spoken',
            self::Formal => 'formal',
            self::Archaic => 'old-fashioned',
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
            self::Blunt => 'Use short sentences. Never soften bad news, never apologise for saying it.',
            self::Plain => 'Use ordinary working words. No flourishes, no long ones where a short one does.',
            self::Formal => 'Speak in complete, careful sentences. Do not use slang or contractions.',
            self::Archaic => 'Speak as the old books do: aye, nay, I shall, it is. Never modern slang or contractions.',
        };
    }
}
