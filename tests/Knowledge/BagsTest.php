<?php

declare(strict_types=1);

namespace App\Tests\Knowledge;

use App\Knowledge\Bags;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Turning a name a player said into an item id the mod will accept.
 *
 * The lookup spans both databases on purpose: `character_inventory` knows which instances a
 * character holds and only `item_template` knows what they are. The halves are mocked here; what
 * is worth pinning is that nothing is claimed about a character carrying nothing, and that a
 * match is reported with the item's own name rather than the words that were typed.
 */
#[CoversClass(Bags::class)]
final class BagsTest extends TestCase
{
    public function testItFindsSomethingTheCharacterIsCarrying(): void
    {
        $characters = $this->createMock(Connection::class);
        $characters->method('fetchFirstColumn')->willReturn([7281, 2589]);

        $world = $this->createMock(Connection::class);
        $world->method('fetchAssociative')->willReturn([
            'entry' => 7281,
            'name' => "Journeyman's Pants",
            'quality' => 2,
            'item_level' => 25,
        ]);

        self::assertSame(
            ['entry' => 7281, 'name' => "Journeyman's Pants", 'quality' => 2, 'item_level' => 25],
            (new Bags($characters, $world))->wearableNamed(1, 'journeymans pants'),
        );
    }

    public function testEmptyBagsAreNotAQuestionForTheWorldDatabase(): void
    {
        $characters = $this->createMock(Connection::class);
        $characters->method('fetchFirstColumn')->willReturn([]);

        $world = $this->createMock(Connection::class);
        $world->expects(self::never())->method('fetchAssociative');

        self::assertNull((new Bags($characters, $world))->wearableNamed(1, 'Thunderfury'));
    }

    public function testCarryingSomethingElseIsNotAMatch(): void
    {
        $characters = $this->createMock(Connection::class);
        $characters->method('fetchFirstColumn')->willReturn([2589]);

        $world = $this->createMock(Connection::class);
        $world->method('fetchAssociative')->willReturn(false);

        self::assertNull((new Bags($characters, $world))->wearableNamed(1, 'Thunderfury'));
    }

    /**
     * A blank name would otherwise become `LIKE '%%'` and match the first thing in the bags.
     */
    public function testABlankNameMatchesNothing(): void
    {
        $characters = $this->createMock(Connection::class);
        $characters->expects(self::never())->method('fetchFirstColumn');

        self::assertNull(
            (new Bags($characters, $this->createMock(Connection::class)))->wearableNamed(1, '   '),
        );
    }
}
