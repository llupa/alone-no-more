<?php

declare(strict_types=1);

namespace App\Director;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the director has been told to do, as opposed to what it was configured to do.
 *
 * Everything here has a sensible default in `.env`, which is where a value belongs if it is a
 * property of the installation: a database DSN, a model name, how long to wait for a bot to
 * finish logging in. Those are set once and forgotten.
 *
 * These are different. "Make the zone busier", "follow this character and not that one", "stop
 * herding, I want to be alone for a bit" are things you decide *while playing*, and restarting a
 * background process to change your mind is absurd. They live in our own SQLite so they survive
 * a restart, and they are written over MCP — which is why this project does not need a UI of its
 * own: an assistant already is one.
 */
final class Settings
{
    /**
     * Bounds, not preferences. A target of 5,000 would hang the server, and a level tolerance of
     * 0 would usually find nobody — an assistant filling in arguments from a description needs
     * to be told no.
     */
    private const LIMITS = [
        'crowd_size' => [0, 400],
        'level_tolerance' => [1, 60],
        'reply_cooldown_seconds' => [0, 600],
    ];

    private ?Connection $connection = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/anm.sqlite')]
        private readonly string $path,
        #[Autowire(env: 'int:ANM_HERD_TARGET')]
        private readonly int $defaultCrowdSize = 50,
        #[Autowire(env: 'int:ANM_PEER_LEVEL_TOLERANCE')]
        private readonly int $defaultLevelTolerance = 5,
        #[Autowire(env: 'int:ANM_BOT_COOLDOWN_SECONDS')]
        private readonly int $defaultReplyCooldown = 20,
    ) {
    }

    public function crowdSize(): int
    {
        return (int) ($this->get('crowd_size') ?? $this->defaultCrowdSize);
    }

    public function levelTolerance(): int
    {
        return (int) ($this->get('level_tolerance') ?? $this->defaultLevelTolerance);
    }

    public function replyCooldownSeconds(): int
    {
        return (int) ($this->get('reply_cooldown_seconds') ?? $this->defaultReplyCooldown);
    }

    public function isHerding(): bool
    {
        return '0' !== $this->get('herding');
    }

    /**
     * Whose zone the world should be kept alive around.
     *
     * Empty means "whoever is online", which is the right default for one person playing alone.
     * Naming characters matters the moment a second one is online: an alt parked in a city would
     * otherwise claim half the crowd, and the zone you are actually in ends up half empty.
     *
     * @return string[]
     */
    public function followed(): array
    {
        $names = $this->get('followed');

        if (null === $names || '' === $names) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $names))));
    }

    public function follow(string $character): void
    {
        $followed = $this->followed();

        foreach ($followed as $existing) {
            if (0 === strcasecmp($existing, $character)) {
                return;
            }
        }

        $followed[] = $character;
        $this->set('followed', implode(',', $followed));
    }

    public function stopFollowing(string $character): bool
    {
        $followed = $this->followed();
        $kept = array_values(array_filter(
            $followed,
            static fn (string $name): bool => 0 !== strcasecmp($name, $character),
        ));

        $this->set('followed', implode(',', $kept));

        return \count($kept) !== \count($followed);
    }

    public function setHerding(bool $on): void
    {
        $this->set('herding', $on ? '1' : '0');
    }

    /**
     * @throws \InvalidArgumentException when the value is outside what the world can take
     */
    public function setNumber(string $key, int $value): void
    {
        if (!isset(self::LIMITS[$key])) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting. Try: %s.', $key, implode(', ', array_keys(self::LIMITS))));
        }

        [$min, $max] = self::LIMITS[$key];

        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(\sprintf('%s must be between %d and %d; %d was asked for.', $key, $min, $max, $value));
        }

        $this->set($key, (string) $value);
    }

    /**
     * Everything currently in force, defaults included, for showing someone where they stand.
     *
     * A shape rather than a map of unions: callers format these for a person, and `int|bool|
     * string[]` means every one of them has to narrow a value it already knows the type of.
     *
     * @return array{crowd_size: int, level_tolerance: int, reply_cooldown_seconds: int, herding: bool, followed: string[]}
     */
    public function all(): array
    {
        return [
            'crowd_size' => $this->crowdSize(),
            'level_tolerance' => $this->levelTolerance(),
            'reply_cooldown_seconds' => $this->replyCooldownSeconds(),
            'herding' => $this->isHerding(),
            'followed' => $this->followed(),
        ];
    }

    private function get(string $key): ?string
    {
        $value = $this->connection()->fetchOne('SELECT value FROM setting WHERE key_ = :key', ['key' => $key]);

        return false === $value || null === $value ? null : (string) $value;
    }

    private function set(string $key, string $value): void
    {
        // SQLite's upsert, so a setting written twice does not become two rows.
        $this->connection()->executeStatement(
            'INSERT INTO setting (key_, value) VALUES (:key, :value)
             ON CONFLICT(key_) DO UPDATE SET value = excluded.value',
            ['key' => $key, 'value' => $value],
        );
    }

    private function connection(): Connection
    {
        if (null !== $this->connection) {
            return $this->connection;
        }

        if (!is_dir($dir = \dirname($this->path))) {
            mkdir($dir, 0o775, true);
        }

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
        // `key` is reserved in some SQLite builds, hence key_.
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS setting (key_ TEXT PRIMARY KEY, value TEXT NOT NULL)',
        );

        return $this->connection;
    }
}
