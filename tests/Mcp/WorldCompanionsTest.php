<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Director\CompanionRequests;
use App\Herding\BotRoster;
use App\Mcp\WorldCompanions;
use App\Replay\ReplayGateway;
use App\Replay\Snapshot;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Setting a companion aside, asked of the recording a judge runs.
 *
 * The bots to choose from live in the server's database, and a clone has none. This was the one
 * tool that answered a judge with a bare internal error; it has to say what every other order
 * says in replay — that nothing can be changed in a recording.
 */
#[CoversClass(WorldCompanions::class)]
final class WorldCompanionsTest extends TestCase
{
    private string $requests;

    protected function setUp(): void
    {
        $this->requests = (string) tempnam(sys_get_temp_dir(), 'anm-requests-');
    }

    protected function tearDown(): void
    {
        @unlink($this->requests);
    }

    public function testARecordingSaysItCannotSetAnyoneAsideAndNeverTouchesTheDatabase(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method(self::anything());

        $answer = $this->companions($database)->prepare('Klemprime', 'priest');

        self::assertStringContainsString('This is a recorded world', $answer);
        self::assertStringContainsString('Klemprime', $answer);
    }

    /**
     * A recording still checks the request itself, the same way a live server would.
     */
    public function testANonsenseCallingIsStillExplained(): void
    {
        $answer = $this->companions($this->createStub(Connection::class))->prepare('Klemprime', 'astronaut');

        self::assertStringContainsString('"astronaut" is not a calling', $answer);
    }

    private function companions(Connection $database): WorldCompanions
    {
        return new WorldCompanions(
            new ReplayGateway(new Snapshot(\dirname(__DIR__, 2).'/fixtures/world.json')),
            new BotRoster($database),
            new CompanionRequests($this->requests),
        );
    }
}
