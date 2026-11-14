<?php

declare(strict_types=1);

namespace App\Tests\Roleplay\Context;

use App\Roleplay\Context\MentionedEntities;
use PHPUnit\Framework\TestCase;

/**
 * Link markup is compared against lines the real client sent, because getting a single field
 * wrong produces a link that looks right and resolves to the wrong thing.
 */
final class MentionedEntitiesTest extends TestCase
{
    public function testItBuildsTheSameItemMarkupTheClientSends(): void
    {
        $entities = new MentionedEntities();
        $entities->item(5168, 'Timberling Seed', 1, 6);

        self::assertSame(
            'need a |cffffffff|Hitem:5168:0:0:0:0:0:0:0:6|h[Timberling Seed]|h|r',
            $entities->linkify('need a Timberling Seed'),
        );
    }

    public function testItBuildsTheSameQuestMarkupTheClientSends(): void
    {
        $entities = new MentionedEntities();
        $entities->quest(932, 'Twisted Hatred', 7);

        self::assertSame(
            'about |cffffff00|Hquest:932:7|h[Twisted Hatred]|h|r now',
            $entities->linkify('about Twisted Hatred now'),
        );
    }

    public function testQualityDecidesTheColour(): void
    {
        $entities = new MentionedEntities();
        $entities->item(6121, "Recruit's Pants", 0, 6);

        // |cff9d9d9d is the grey the client used for this very item.
        self::assertStringContainsString('|cff9d9d9d|Hitem:6121:', $entities->linkify("Recruit's Pants"));
    }

    public function testSomethingAlreadyLinkedIsLeftAlone(): void
    {
        $entities = new MentionedEntities();
        $entities->quest(932, 'Twisted Hatred', 7);

        $alreadyLinked = 'about |cffffff00|Hquest:932:7|h[Twisted Hatred]|h|r yes';

        self::assertSame($alreadyLinked, $entities->linkify($alreadyLinked));
    }

    /**
     * Only the first mention is linked. Three links to the same quest in one sentence reads as
     * a machine, not a person.
     */
    public function testOnlyTheFirstMentionIsLinked(): void
    {
        $entities = new MentionedEntities();
        $entities->quest(932, 'Twisted Hatred', 7);

        $linked = $entities->linkify('Twisted Hatred, yes, Twisted Hatred');

        self::assertSame(1, substr_count($linked, '|Hquest:932'));
    }

    public function testLongerNamesWinSoShorterOnesDoNotEatThem(): void
    {
        $entities = new MentionedEntities();
        $entities->quest(1, 'Hatred', 5);
        $entities->quest(932, 'Twisted Hatred', 7);

        $linked = $entities->linkify('I want Twisted Hatred');

        self::assertStringContainsString('|h[Twisted Hatred]|h', $linked);
        self::assertStringNotContainsString('Twisted |cffffff00|Hquest:1', $linked);
    }

    public function testItLeavesWordsItWasNotToldAboutAlone(): void
    {
        $entities = new MentionedEntities();
        $entities->quest(932, 'Twisted Hatred', 7);

        self::assertSame('some other business', $entities->linkify('some other business'));
    }

    public function testNothingMentionedMeansNothingChanged(): void
    {
        self::assertSame('plain words', (new MentionedEntities())->linkify('plain words'));
        self::assertTrue((new MentionedEntities())->isEmpty());
    }
}
