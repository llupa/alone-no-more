<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Knowledge\GameKnowledgeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where the bot is standing, and what is actually around it.
 *
 * This provider exists because of a specific failure: asked for work near Astranaar, an
 * ungrounded bot offered a job at "Raptor's Spine", which does not exist. Naming real creatures
 * that are really spawned nearby gives the model something true to reach for.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class PlaceProvider implements ContextProviderInterface
{
    public function __construct(private GameKnowledgeInterface $knowledge)
    {
    }

    public function contribute(RoleplayContext $context): string
    {
        $bot = $context->bot;
        $lines = [];

        $lines[] = null !== $context->zoneName
            ? \sprintf('You are in %s.', $context->zoneName)
            : 'You are out in the world.';

        $creatures = $this->knowledge->creaturesNear($bot->position, 200.0, 5);

        if ([] !== $creatures) {
            $names = array_map(
                static fn (array $c): string => $c['name'],
                \array_slice($creatures, 0, 4),
            );
            // Spelled out as *creatures* because "nearby you can see X" let the model read a
            // guard's name as a landmark — it offered to climb "the top of Shadowglen Sentinel",
            // who is a person standing on a path.
            $lines[] = \sprintf(
                'The living creatures and people around you right now are: %s. These are beings, not places. '
                .'If you mention local wildlife, foes or folk, name only these, and do not invent landmarks, towns or regions.',
                self::list($names),
            );
        }

        // Drawn from a wider pool and shuffled rather than taking the same top three every time.
        // With a fixed list the model fixated: in one session a bot mentioned the Gnarlpine
        // Corruption in five replies out of eight, because it was in every prompt.
        $quests = $this->knowledge->questsForZone($bot->zoneId, $bot->level, limit: 12);

        if ([] !== $quests) {
            shuffle($quests);
            $chosen = \array_slice($quests, 0, 2);

            foreach ($chosen as $quest) {
                $context->mentioned->quest($quest['id'], $quest['title'], $quest['level']);
            }

            $titles = array_map(static fn (array $q): string => '"'.$q['title'].'"', $chosen);
            $lines[] = \sprintf(
                'Among the tasks people hereabouts speak of: %s. Mention such things only if they come up naturally.',
                self::list($titles),
            );
        }

        return implode(' ', $lines);
    }

    public function priority(): int
    {
        return 10;
    }

    /**
     * @param string[] $items
     */
    private static function list(array $items): string
    {
        if (\count($items) < 2) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
