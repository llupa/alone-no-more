<?php

declare(strict_types=1);

namespace App\Roleplay\Personality;

use App\World\Model\Actor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The personality a companion was given the first time you met them, kept.
 *
 * The roll is already seeded by guid, so this is not needed to make a character stable within a
 * version — it is needed to make them stable *across* versions. Add a quirk to the list or
 * reweight a race, and every bot in the world would quietly become someone else; a companion you
 * have travelled with for a week turning into a different person is the worst kind of bug,
 * because nothing breaks and you just stop trusting it.
 *
 * So the roll happens once, on first meeting, and is written down.
 */
final class PersonalityStore
{
    /** @var array<int, Personality> */
    private array $known = [];

    private ?Connection $connection = null;

    public function __construct(
        private readonly PersonalityRoller $roller,
        #[Autowire('%kernel.project_dir%/var/anm.sqlite')]
        private readonly string $path,
    ) {
    }

    public function of(Actor $bot): Personality
    {
        if (isset($this->known[$bot->guid])) {
            return $this->known[$bot->guid];
        }

        $stored = $this->connection()->fetchAssociative(
            'SELECT warmth, manner, humour, pride, quirk FROM personality WHERE bot_guid = :guid',
            ['guid' => $bot->guid],
        );

        if (false !== $stored) {
            return $this->known[$bot->guid] = new Personality(
                Warmth::from((string) $stored['warmth']),
                Manner::from((string) $stored['manner']),
                Humour::from((string) $stored['humour']),
                Pride::from((string) $stored['pride']),
                (string) $stored['quirk'],
            );
        }

        $rolled = $this->roller->roll($bot);

        $this->connection()->executeStatement(
            'INSERT OR IGNORE INTO personality (bot_guid, warmth, manner, humour, pride, quirk, rolled_at)
             VALUES (:guid, :warmth, :manner, :humour, :pride, :quirk, :at)',
            [
                'guid' => $bot->guid,
                'warmth' => $rolled->warmth->value,
                'manner' => $rolled->manner->value,
                'humour' => $rolled->humour->value,
                'pride' => $rolled->pride->value,
                'quirk' => $rolled->quirk,
                'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return $this->known[$bot->guid] = $rolled;
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
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS personality (
                bot_guid INTEGER PRIMARY KEY,
                warmth TEXT NOT NULL,
                manner TEXT NOT NULL,
                humour TEXT NOT NULL,
                pride TEXT NOT NULL,
                quirk TEXT NOT NULL,
                rolled_at TEXT NOT NULL
            )',
        );

        return $this->connection;
    }
}
