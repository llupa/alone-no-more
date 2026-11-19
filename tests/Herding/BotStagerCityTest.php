<?php

declare(strict_types=1);

namespace App\Tests\Herding;

use App\Config\Settings;
use App\Herding\BotRoster;
use App\Herding\BotStager;
use App\Herding\PlacementPoints;
use App\Knowledge\Dbc\AreaNames;
use App\Tests\Roleplay\ActorMother;
use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
use App\World\Model\Position;
use App\World\Outbox;
use App\World\WorldGatewayInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Gathering the crowd tighter inside a capital.
 *
 * Six hundred yards is a zone's worth of countryside and a city's worth of outskirts: from the
 * Trade District that sphere reaches past the walls into Elwynn, so the crowd was staged around
 * Stormwind instead of in it. The player counted it with /who — seven people in the city,
 * forty-odd in the forest.
 */
#[CoversClass(BotStager::class)]
final class BotStagerCityTest extends TestCase
{
    private const STORMWIND = 1519;
    private const ELWYNN = 12;

    public function testACapitalIsGatheredTighterAndFuller(): void
    {
        $asked = $this->stageIn(self::STORMWIND);

        self::assertSame(250.0 * 250.0, $asked['max2'], 'spots should be sought within the city radius');
        self::assertSame(100, $asked['target'], 'a capital gets the crowd plus the city extra');
    }

    public function testOpenCountryIsUnchanged(): void
    {
        $asked = $this->stageIn(self::ELWYNN);

        self::assertSame(600.0 * 600.0, $asked['max2']);
        self::assertSame(50, $asked['target']);
    }

    /**
     * @return array{max2: float, target: int}
     */
    private function stageIn(int $zoneId): array
    {
        /** @var array{max2?: float} $seen */
        $seen = [];

        // Real collaborators over stub connections: the production classes are final, and
        // widening them to suit a test would be the wrong trade.
        $world = $this->createMock(Connection::class);
        $world->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params) use (&$seen): array {
                if (isset($params['max2'])) {
                    $seen['max2'] = (float) $params['max2'];
                }

                return [];   // no spots, so staging stops before touching anything else
            },
        );

        $characters = $this->createStub(Connection::class);
        $characters->method('fetchOne')->willReturn(false);
        $characters->method('fetchAllAssociative')->willReturn([]);

        $stager = new BotStager(
            new BotRoster($characters),
            new PlacementPoints($world),
            new BotSpeechDispatcher(new Outbox($characters)),
            $this->createStub(GroupRepositoryInterface::class),
            new AreaNames('/nonexistent'),
            $this->createStub(WorldGatewayInterface::class),
            new Settings(tempnam(sys_get_temp_dir(), 'anm-stager-').'.sqlite'),
        );

        $result = $stager->stage(
            ActorMother::player(100, at: new Position(-8913.0, 554.0, 100.0, 0), zoneId: $zoneId),
        );

        return ['max2' => $seen['max2'] ?? 0.0, 'target' => $result->target];
    }
}
