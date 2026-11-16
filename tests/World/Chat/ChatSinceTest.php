<?php

declare(strict_types=1);

namespace App\Tests\World\Chat;

use App\World\Chat\ChatEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Turning a moment into a watermark.
 *
 * Capturing a snapshot by counting back from the newest line takes whatever the world happened
 * to say, and in a busy zone most of that is strangers muttering. A recording session has a
 * beginning, and `anm_chat_event` is timestamped, so saying when is better than guessing how
 * many lines ago that was.
 */
#[CoversClass(ChatEventRepository::class)]
final class ChatSinceTest extends TestCase
{
    public function testItFindsTheFirstLineSaidAfterAMoment(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(self::anything(), ['at' => '2026-11-16 20:00:00'])
            ->willReturn('781');

        self::assertSame(781, (new ChatEventRepository($connection))->firstIdSince('2026-11-16 20:00'));
    }

    /**
     * Relative moments are the ones a person actually types after a session.
     */
    public function testItUnderstandsARelativeMoment(): void
    {
        $seen = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params) use (&$seen): string {
                $seen = $params['at'];

                return '42';
            },
        );

        (new ChatEventRepository($connection))->firstIdSince('-90 minutes');

        self::assertNotNull($seen);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $seen);
    }

    /**
     * Nothing said since then is a real answer, and a different one from "start at zero" — the
     * caller has to be able to tell the difference, or asking for a session nobody spoke in
     * would capture the entire log instead.
     */
    public function testNothingSaidSinceThenIsNull(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(null);

        self::assertNull((new ChatEventRepository($connection))->firstIdSince('now'));
    }
}
