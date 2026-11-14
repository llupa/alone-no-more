<?php

declare(strict_types=1);

namespace App\World\CommandServer;

use App\World\Exception\WorldGatewayException;
use App\World\Model\Position;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client for the mod-playerbots command server (playerbots.conf: Playerbots.CommandServerPort).
 *
 * The protocol is one line in, one line out, over a plain TCP socket:
 *
 *     position,42\n   ->   -9464.75 62.17 56 0 0.6 |Elwynn Forest|\n
 *
 * The server keeps the connection open and handles requests in sequence, so we hold one
 * socket for the lifetime of the client rather than reconnecting per bot. That matters:
 * a density sweep queries a hundred bots at a time.
 *
 * Only the bot's low GUID is accepted, not its name. Unknown guids answer "invalid guid",
 * and a line without a comma answers "invalid request: ...".
 */
final class CommandServerClient
{
    /** @var resource|null */
    private $socket;

    public function __construct(
        #[Autowire(env: 'ANM_COMMAND_SERVER_HOST')]
        private readonly string $host = '127.0.0.1',
        #[Autowire(env: 'int:ANM_COMMAND_SERVER_PORT')]
        private readonly int $port = 8888,
        private readonly float $timeout = 2.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function position(int $botGuid): ?Position
    {
        $raw = $this->send('position', $botGuid);

        if (self::isError($raw)) {
            return null;
        }

        return Position::fromProtocolLine($raw);
    }

    /**
     * Positions for many bots in one pass over the open socket.
     *
     * Bots that are offline or unknown are omitted rather than nulled: callers want the
     * crowd that exists, and a bot logging out mid-sweep is normal, not exceptional.
     *
     * @param int[] $botGuids
     *
     * @return array<int, Position> keyed by bot guid
     */
    public function positions(array $botGuids): array
    {
        $positions = [];

        foreach ($botGuids as $guid) {
            try {
                if (null !== $position = $this->position($guid)) {
                    $positions[$guid] = $position;
                }
            } catch (WorldGatewayException $e) {
                // One unparseable bot must not abort the sweep.
                $this->logger->debug('Skipping bot {guid}: {reason}', ['guid' => $guid, 'reason' => $e->getMessage()]);
            }
        }

        return $positions;
    }

    /**
     * @return string the server's reply, or null when it reports an error for this bot
     */
    public function send(string $command, int $botGuid): string
    {
        $socket = $this->socket();

        $request = \sprintf("%s,%d\n", $command, $botGuid);

        if (false === @fwrite($socket, $request)) {
            $this->disconnect();

            throw new WorldGatewayException(\sprintf('Lost the command server while sending "%s".', $command));
        }

        $line = @fgets($socket);

        if (false === $line) {
            $this->disconnect();

            throw new WorldGatewayException(\sprintf('No reply from the command server for "%s".', $command));
        }

        return trim($line);
    }

    public function disconnect(): void
    {
        if (null !== $this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private static function isError(string $reply): bool
    {
        return '' === $reply
            || 'invalid guid' === $reply
            || str_starts_with($reply, 'invalid request:');
    }

    /**
     * @return resource
     */
    private function socket()
    {
        if (null !== $this->socket && !feof($this->socket)) {
            return $this->socket;
        }

        $this->disconnect();

        $socket = @stream_socket_client(
            \sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeout,
        );

        if (false === $socket) {
            throw new WorldGatewayException(\sprintf('Cannot reach the playerbots command server at %s:%d (%s). Is the worldserver running with Playerbots.CommandServerPort set?', $this->host, $this->port, $errstr ?: 'error '.$errno));
        }

        stream_set_timeout($socket, (int) $this->timeout);

        return $this->socket = $socket;
    }
}
