<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The rules of the performance. Runs last so these are the final instructions the model reads.
 *
 * Brevity is enforced here rather than by truncating afterwards: a sentence cut mid-word reads
 * far worse than a short one, and the game's chat line is only 255 characters anyway.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class StyleProvider implements ContextProviderInterface
{
    public function contribute(RoleplayContext $context): string
    {
        return implode(' ', [
            \sprintf('%s has just spoken to you.', $context->speaker->name),
            // "One or two short sentences" was read as a target rather than a ceiling, and
            // replies arrived at almost exactly the 255-character chat limit every time. A word
            // budget is concrete in a way that "short" is not.
            'Answer in character. Most replies are ONE sentence of fifteen words or fewer.',
            'Use two sentences only when they asked something that genuinely needs it.',
            'Never pad. If the whole answer is "Aye, on my way", say that and stop.',
            'Never mention being an AI, a bot, a model or a game. Never break character.',
            'Do not wrap your reply in quotation marks.',
            'Do not describe actions or use asterisks; speak only.',
            'If you do not know something, say so in character rather than inventing it.',
            // Two companions were asked where Deputy Willem stood one minute apart. One looked
            // it up and gave directions; the other declined, citing the rule above. "Do not
            // guess" was being read as "do not find out", so the order of the two is now
            // explicit: look first, decline only after.
            'Before saying you do not know where someone or something is, ask the archives.',
            'Only say you do not know once they have come back with nothing.',
            // A bot is playing the game while you talk to it: it takes quests, finishes them and
            // hands them in between one answer and the next. Told to be truthful, the model
            // noticed its own earlier words no longer matched and narrated the discrepancy —
            // "I was wrong a moment ago", "I need to correct myself", three times in ten
            // minutes. Each correction was honest and each one broke the spell. A person in
            // that position simply speaks about now.
            'What you are carrying and doing changes as you go about your day.',
            'Speak about how things stand now, and never remark that it differs from what you',
            'said before: no "I was wrong earlier", no "I need to correct myself".',
        ]);
    }

    public function priority(): int
    {
        return 100;
    }
}
