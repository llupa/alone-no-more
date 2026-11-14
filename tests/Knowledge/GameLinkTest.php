<?php

declare(strict_types=1);

namespace App\Tests\Knowledge;

use App\Knowledge\GameLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Link markup is tested against lines captured verbatim from the game client, not invented ones:
 * the colour prefix, the trailing field list and the |r terminator all vary by link type.
 */
final class GameLinkTest extends TestCase
{
    /** Captured from Doppel's party chat. */
    private const REAL_QUEST_LINE = 'Do you have this quest |cffffff00|Hquest:3521:4|h[Iverron\'s Antidote]|h|r ?';

    public function testItReadsAQuestLinkTheClientActuallySent(): void
    {
        $links = GameLink::parseAll(self::REAL_QUEST_LINE);

        self::assertCount(1, $links);
        self::assertSame('quest', $links[0]->type);
        self::assertSame(3521, $links[0]->id);
        self::assertSame("Iverron's Antidote", $links[0]->label);
    }

    public function testItReadsAnItemLink(): void
    {
        $links = GameLink::parseAll('check out |cff1eff00|Hitem:19019:0:0:0:0:0:0:0|h[Thunderfury]|h|r');

        self::assertCount(1, $links);
        self::assertSame('item', $links[0]->type);
        self::assertSame(19019, $links[0]->id);
    }

    public function testItReadsSeveralLinksInOrder(): void
    {
        $links = GameLink::parseAll(
            '|cffffff00|Hquest:3521:4|h[One]|h|r and |cff1eff00|Hitem:19019:0:0:0:0:0:0:0|h[Two]|h|r',
        );

        self::assertCount(2, $links);
        self::assertSame([3521, 19019], array_map(static fn (GameLink $l): int => $l->id, $links));
    }

    #[DataProvider('plainMessages')]
    public function testPlainChatHasNoLinks(string $message): void
    {
        self::assertSame([], GameLink::parseAll($message));
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function plainMessages(): iterable
    {
        yield 'ordinary' => ['how many quests do you have?'];
        yield 'brackets but no link' => ['meet me at [the inn]'];
        yield 'empty' => [''];
        yield 'pipe but no link' => ['a | b'];
    }

    public function testStripLeavesTheSentenceAPersonRead(): void
    {
        self::assertSame(
            "Do you have this quest Iverron's Antidote ?",
            GameLink::strip(self::REAL_QUEST_LINE),
        );
    }

    public function testStripLeavesPlainTextAlone(): void
    {
        self::assertSame('just talking', GameLink::strip('just talking'));
    }
}
