<?php

declare(strict_types=1);

namespace App\Roleplay\Context;

use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\GameLink;
use App\Knowledge\ProperNouns;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\String\TruncateMode;

use function Symfony\Component\String\u;

/**
 * Resolves anything the player shift-clicked into chat.
 *
 * When someone links a quest and asks "do you have this one?", the bot should know what it is.
 * The link carries a primary key into the world database, so the answer is exact — the real
 * title, level and objective of that quest on this server — rather than the model's recollection
 * of a wiki it half-remembers.
 */
#[AutoconfigureTag('anm.roleplay_context')]
final readonly class LinkedContentProvider implements ContextProviderInterface
{
    private const MAX_LINKS = 3;

    public function __construct(private GameKnowledgeInterface $knowledge)
    {
    }

    public function contribute(RoleplayContext $context): ?string
    {
        $facts = [];

        // A linked quest is also found by name, because the name is the link's own label — so
        // without this the same quest is described twice in one prompt.
        $seen = [];

        // Links first, deliberately. A link carries a primary key and resolves the full chain —
        // the quest, what it needs, who carries it — where a typed name only gets the quest. If
        // the name ran first it would claim the entry and the richer answer would be discarded
        // as a duplicate.
        $links = GameLink::parseAll($context->event->message);

        foreach (\array_slice($links, 0, self::MAX_LINKS) as $link) {
            $fact = match ($link->type) {
                'quest' => $this->describeQuest($link, $context, $seen),
                'item' => $this->describeItem($link, $context, $seen),
                default => null,
            };

            if (null !== $fact) {
                $facts[] = $fact;
            }
        }

        foreach (\array_slice(ProperNouns::from($context->event->message), 0, self::MAX_LINKS) as $name) {
            // Typed, not shift-clicked. A player names a quest aloud far more often than they
            // link it, and answering "never heard of it" about something sitting in our own
            // database is the worst possible failure — it sounds confident and it is wrong.
            $fact = $this->describeNamedQuest($name, $context, $seen) ?? $this->describeNamedItem($name, $context, $seen);

            if (null !== $fact) {
                $facts[] = $fact;
            }
        }

        if ([] === $facts) {
            return null;
        }

        return "They have mentioned these, and this is the truth of them. Speak as someone who\n"
            ."knows them; do not invent anything beyond what is written here, and if they ask\n"
            ."something these facts do not answer, say plainly that you do not know:\n"
            .implode("\n", $facts);
    }

    public function priority(): int
    {
        return 30;
    }

    private function describeNamedQuest(string $name, RoleplayContext $context, array &$seen): ?string
    {
        $quest = $this->knowledge->findQuestByName($name);

        if (null === $quest || !self::firstSighting('quest', $quest['id'], $seen)) {
            return null;
        }

        $context->mentioned->quest($quest['id'], $quest['title'], $quest['level']);

        $line = \sprintf('- The quest "%s" (level %d)', $quest['title'], $quest['level']);

        return '' !== $quest['objective'] ? $line.': '.self::trim($quest['objective']) : $line;
    }

    private function describeNamedItem(string $name, RoleplayContext $context, array &$seen): ?string
    {
        $item = $this->knowledge->findItemByName($name);

        if (null === $item || !self::firstSighting('item', $item['id'], $seen)) {
            return null;
        }

        $context->mentioned->item($item['id'], $item['name'], $item['quality'], $context->bot->level);

        $line = \sprintf('- The item "%s" (item level %d)', $item['name'], $item['item_level']);

        return '' !== $item['description'] ? $line.': '.self::trim($item['description']) : $line;
    }

    private function describeQuest(GameLink $link, RoleplayContext $context, array &$seen): ?string
    {
        $quest = $this->knowledge->quest($link->id);

        if (null === $quest || !self::firstSighting('quest', $quest['id'], $seen)) {
            return null;
        }

        $context->mentioned->quest($quest['id'], $quest['title'], $quest['level']);

        $line = \sprintf('- The quest "%s" (level %d)', $quest['title'], $quest['level']);

        if ('' !== $quest['objective']) {
            $line .= ': '.self::trim($quest['objective']);
        }

        return $line.$this->howToFinish($quest['id'], $context);
    }

    /**
     * What a linked quest needs, and who carries it.
     *
     * A link is a primary key, so the whole chain — quest, its required items, what drops them —
     * resolves exactly, instantly and for free. The alternative was to have the bot ask the
     * archivist, but a model cannot be *made* to call a tool (forced tool choice returns a 400
     * on current models), and asking it to would be slower and less certain than simply knowing.
     *
     * The archivist still earns its place on the open questions — "a decent sword for my level",
     * "that antidote quest near Dolanaar" — which have no key to look up.
     */
    private function howToFinish(int $questId, RoleplayContext $context): string
    {
        $needed = $this->knowledge->questRequiredItems($questId);

        if ([] === $needed) {
            return '';
        }

        $lines = [];

        // Capped tightly: two items, two sources each. A quest wanting four things from six
        // creatures apiece would otherwise crowd out everything else in the prompt, and a
        // companion reciting a full drop table does not sound like a person.
        foreach (\array_slice($needed, 0, 2) as $item) {
            $context->mentioned->item($item['id'], $item['item'], 1, $context->bot->level);

            $carriers = \array_slice($this->knowledge->whatDrops($item['id'], 3), 0, 2);

            if ([] === $carriers) {
                $lines[] = \sprintf('  It needs %d x %s.', $item['count'], $item['item']);

                continue;
            }

            $who = array_map(
                static fn (array $c): string => \sprintf(
                    '%s (level %d%s)',
                    $c['creature'],
                    $c['level_min'],
                    $c['level_max'] > $c['level_min'] ? '-'.$c['level_max'] : '',
                ),
                $carriers,
            );

            $lines[] = \sprintf(
                '  It needs %d x %s, carried by %s.',
                $item['count'],
                $item['item'],
                implode(' and ', $who),
            );
        }

        return [] === $lines ? '' : "\n".implode("\n", $lines);
    }

    private function describeItem(GameLink $link, RoleplayContext $context, array &$seen): ?string
    {
        $item = $this->knowledge->item($link->id);

        if (null === $item || !self::firstSighting('item', $item['id'], $seen)) {
            return null;
        }

        $context->mentioned->item($item['id'], $item['name'], $item['quality'], $context->bot->level);

        $line = \sprintf('- The item "%s" (item level %d', $item['name'], $item['item_level']);
        $line .= $item['required_level'] > 0 ? \sprintf(', requires level %d)', $item['required_level']) : ')';

        if ('' !== $item['description']) {
            $line .= ': '.self::trim($item['description']);
        }

        return $line;
    }

    /**
     * True the first time a given thing is seen, false afterwards.
     *
     * @param array<string, true> $seen
     */
    private static function firstSighting(string $kind, int $id, array &$seen): bool
    {
        $key = $kind.':'.$id;

        if (isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;

        return true;
    }

    /**
     * Quest text runs to paragraphs; a bot needs the gist, not the scroll.
     */
    private static function trim(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);

        // Code points, not bytes: quest text in a non-English client is full of accented
        // characters and a byte-wise cut produces mojibake.
        return u($text)->truncate(220, '…', TruncateMode::WordBefore)->toString();
    }
}
