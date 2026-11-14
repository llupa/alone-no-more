<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Roleplay\Personality\PersonalityStore;
use App\World\Model\Actor;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Who the bot is. Measured to be the single highest-value line in the prompt: accent, register
 * and lore-flavour all emerged from this alone in testing, before any other context existed.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class IdentityProvider implements ContextProviderInterface
{
    public function __construct(private PersonalityStore $personalities)
    {
    }

    public function contribute(RoleplayContext $context): string
    {
        $bot = $context->bot;

        // "You are Thanvi, a proud, blunt, dry dwarf paladin of the Alliance." The adjectives
        // belong here rather than in a paragraph of their own: a character is named in one
        // breath and then played, while a character described in four sentences gets read out.
        $lines = [\sprintf(
            'You are %s, a %s %s.',
            $bot->name,
            $this->personalities->of($bot)->adjectives(),
            self::whatTheyAre($bot),
        )];

        if (null !== $bot->guildName) {
            $lines[] = \sprintf('You belong to the guild <%s>.', $bot->guildName);
        }

        // Without this a level 6 claimed to have fought in the war against the Scourge and held
        // them off. Level is the only measure of experience the world gives us, so it has to
        // bound what a character may claim to have lived through.
        $lines[] = self::standing($bot->level);

        return implode(' ', $lines);
    }

    public function priority(): int
    {
        return 0;
    }

    /**
     * Race, class and allegiance, as one phrase to hang adjectives off.
     */
    private static function whatTheyAre(Actor $bot): string
    {
        return \sprintf(
            '%s %s %s of the %s',
            strtolower($bot->gender->label()),
            // Races keep their capitals: "Forsaken" and "Night Elf" are proper nouns.
            $bot->race->label(),
            strtolower($bot->class->label()),
            $bot->faction()->value,
        );
    }

    /**
     * What someone of this level could honestly have seen. Claiming otherwise is the fastest way
     * to lose a player who knows the world.
     */
    private static function standing(int $level): string
    {
        return match (true) {
            $level <= 10 => 'You are barely out of training and have never left your homeland. '
                .'You have seen no wars and no distant lands; if asked about them, say honestly that you have not been.',
            $level <= 30 => 'You are a journeyman adventurer. You have travelled a little and fought real battles, '
                .'but the great wars are stories you have heard, not fought in.',
            $level <= 59 => 'You are a seasoned adventurer who has crossed much of the world and survived hard fights.',
            default => 'You are a veteran of the great campaigns, and speak of them as someone who was there.',
        };
    }
}
