<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Errand\ErrandRunner;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What this companion is in the middle of.
 *
 * Without it a bot halfway through clearing a camp has no idea it is doing anything: asked "how
 * many to go", it would either guess or go and read its quest log as though the question came out
 * of nowhere. Someone working at a task knows they are working at it.
 *
 * Progress comes from the server's own counters rather than from the model keeping a tally, which
 * it cannot do — the earlier version promised "I'll keep count as we go" and then could not.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class ErrandProvider implements ContextProviderInterface
{
    public function __construct(
        private ErrandBook $errands,
        private ErrandRunner $runner,
    ) {
    }

    public function contribute(RoleplayContext $context): ?string
    {
        $errand = $this->errands->of($context->bot->guid);

        if (null === $errand) {
            return null;
        }

        $lines = [\sprintf('Right now you are %s, and you keep going without being asked again.', $errand->describe())];

        if (null !== $errand->questTitle) {
            $lines[] = \sprintf('It is for "%s".', $errand->questTitle);
        }

        $progress = $this->runner->progressOf($errand);

        if (null !== $progress) {
            $lines[] = \sprintf('So far: %s.', $progress);
        }

        $lines[] = 'If they tell you to stop, stop.';

        return implode(' ', $lines);
    }

    public function priority(): int
    {
        return 15;
    }
}
