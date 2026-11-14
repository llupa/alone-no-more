<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

/**
 * How one companion carries themselves, as opposed to who they are.
 *
 * Four axes and a quirk, deliberately few. Every line of this reaches the system prompt on every
 * request, and a model given a long character sheet starts performing the sheet instead of
 * holding a conversation — it reads like an actor announcing their motivation.
 *
 * Everything here describes *manner*. None of it describes malice, and that distinction is the
 * whole design: a proud, cold, sardonic companion is a pleasure to travel with, while one
 * instructed to be unpleasant to the person it is helping is neither fun nor something a model
 * plays well. Sharpness points at the world and the situation, never at the player.
 */
final readonly class Personality
{
    public function __construct(
        public Warmth $warmth,
        public Manner $manner,
        public Humour $humour,
        public Pride $pride,
        /** One small habit of their own, so two night elf priests are not the same person. */
        public string $quirk,
    ) {
    }

    /**
     * Three words for the opening sentence — "a proud, blunt, dry dwarf paladin".
     *
     * Three, not four: a character named by four adjectives reads as a list, and the model
     * starts performing the list. Warmth is left out on purpose because it is the one axis that
     * shows entirely in behaviour, and it carries an instruction instead.
     */
    public function adjectives(): string
    {
        return implode(', ', [
            $this->pride->adjective(),
            $this->manner->adjective(),
            $this->humour->adjective(),
        ]);
    }

    /**
     * How to behave, in the imperative, one clause per line.
     *
     * @return string[]
     */
    public function instructions(): array
    {
        return [
            $this->manner->instruction(),
            $this->humour->instruction(),
            $this->pride->instruction(),
            $this->warmth->instruction(),
            $this->quirk,
        ];
    }

    /**
     * A short label for logs and the TUI-that-never-was: "cold, blunt, dry, proud".
     */
    public function label(): string
    {
        return implode(', ', [
            $this->warmth->name,
            $this->manner->name,
            $this->humour->name,
            $this->pride->name,
        ]);
    }
}
