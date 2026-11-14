<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Roleplay\Personality\Creed;
use App\Roleplay\Personality\Culture;
use App\Roleplay\Personality\PersonalityStore;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How this companion carries themselves, and where they stand among their own people.
 *
 * Sits just after Identity, which says who they are: this says what they are like. Two night elf
 * priests of the same level were previously the same character wearing different names, which is
 * the quietest way for a world of fifty companions to feel like one.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class PersonalityProvider implements ContextProviderInterface
{
    public function __construct(private PersonalityStore $personalities)
    {
    }

    public function contribute(RoleplayContext $context): string
    {
        $bot = $context->bot;

        // Instructions, not descriptions. Told "you take to folk quickly", a dwarf opened a
        // conversation with "good to meet you, I take to folk quickly" — reading its notes
        // aloud. Told "use their name, notice when they are struggling", it simply does that.
        $lines = $this->personalities->of($bot)->instructions();

        $lines[] = Creed::ofClass($bot->class);

        $faction = Creed::ofFaction($bot->faction());

        if ('' !== $faction) {
            $lines[] = $faction;
        }

        $standing = Culture::noteFor($bot->race, $bot->gender);

        if (null !== $standing) {
            $lines[] = $standing;
        }

        // Said once, plainly, because every axis above can be read as licence to be unpleasant
        // and none of them are. A sardonic companion is good company; one that needles the
        // person it is travelling with is not, and no amount of characterisation makes it so.
        $lines[] = 'Whatever your manner, be glad of this company and help when asked.';
        // The list above is how to speak, not a checklist to work through. Applied all at once
        // it produces a companion who greets you, remarks on the weather, mentions the moon and
        // judges your conduct, in one breath, every time.
        $lines[] = 'These are your manner, not a list to get through: most lines show one of them, many show none.';

        // One per line: a wall of clauses in a paragraph gets skimmed and half-applied.
        return "How you carry yourself:\n- ".implode("\n- ", array_filter($lines));
    }

    public function priority(): int
    {
        return 5;
    }
}
