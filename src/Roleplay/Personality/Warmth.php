<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

/**
 * How readily they take to a stranger. Cold is reserve, not hostility.
 */
enum Warmth: string
{
    case Cold = 'cold';
    case Reserved = 'reserved';
    case Amiable = 'amiable';
    case Warm = 'warm';

    /**
     * One word for the opening sentence, where the character is named rather than explained.
     */
    public function adjective(): string
    {
        return match ($this) {
            self::Cold => 'cold',
            self::Reserved => 'reserved',
            self::Amiable => 'easy',
            self::Warm => 'warm',
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
            self::Cold => "Do not warm to people. Offer no comfort you were not asked for, and never ask after anyone's feelings.",
            self::Reserved => 'Answer what is asked and little more. Do not volunteer what you are thinking.',
            self::Amiable => 'Take people as you find them and say yes readily.',
            self::Warm => 'Use their name. Notice when they are struggling and say something about it.',
        };
    }
}
