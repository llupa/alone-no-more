<?php

declare(strict_types=1);

namespace App\Roleplay\Party;

use App\World\Model\Actor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who speaks for the party.
 *
 * Rotating the answer around the group was meant to stop one companion dominating. It did, and
 * the conversation fell apart: each bot held only its own half, so a follow-up went to someone
 * who had not heard the question. Variety is not worth incoherence — in a real party one person
 * does most of the talking anyway.
 *
 * So one bot answers: the one who has been with you longest. The group table records who is in a
 * party but not when they joined, so seniority is ours to remember — first sighting wins, and it
 * survives a restart. Dismiss the voice and the next-longest-serving member takes over, which is
 * what "second in command" means without anyone having to appoint one.
 */
final class PartyVoice
{
    private ?Connection $connection = null;

    public function __construct(
        #[Autowire('%anm.state_file%')]
        private readonly string $path,
    ) {
    }

    /**
     * The member who should answer, having first noted anyone newly arrived.
     *
     * @param Actor[] $members bots currently in the party
     */
    public function speakerFor(string $partyKey, array $members): ?Actor
    {
        if ([] === $members) {
            return null;
        }

        $this->noteArrivals($partyKey, $members);

        // Whoever currently holds the floor keeps it while they are here. Seniority only decides
        // who picks it up — once the player has handed it to someone by name, falling back to
        // "longest serving" on the very next line would take it straight off them again, which
        // is the rotation problem wearing a different hat.
        $holder = $this->currentHolder($partyKey);

        if (null !== $holder) {
            foreach ($members as $member) {
                if ($member->guid === $holder) {
                    return $member;
                }
            }
        }

        $seniority = $this->seniority($partyKey);

        usort($members, static fn (Actor $a, Actor $b): int => ($seniority[$a->guid] ?? '9999') <=> ($seniority[$b->guid] ?? '9999'));

        return $members[0];
    }

    /**
     * Give the floor to a particular companion — what saying someone's name means.
     */
    public function handTo(string $partyKey, Actor $speaker): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO party_voice (party_key, bot_guid, bot_name) VALUES (:key, :guid, :name)
             ON CONFLICT(party_key) DO UPDATE SET bot_guid = excluded.bot_guid, bot_name = excluded.bot_name',
            ['key' => $partyKey, 'guid' => $speaker->guid, 'name' => $speaker->name],
        );
    }

    private function currentHolder(string $partyKey): ?int
    {
        $guid = $this->connection()->fetchOne(
            'SELECT bot_guid FROM party_voice WHERE party_key = :key',
            ['key' => $partyKey],
        );

        return false === $guid || null === $guid ? null : (int) $guid;
    }

    /**
     * Whether the voice has changed hands since last asked, and who held it before.
     *
     * The caller needs this because the party's conversation is stored as the voice's own words.
     * Handing that transcript to a successor would have it reading someone else's lines in its
     * own voice — the mistake that once had a priest talking about the shield she was keeping
     * up. On handover the turns are dropped; the history reaches the new voice through scene
     * memory instead, as something it overheard, which is what it actually was.
     *
     * @return string|null the previous voice's name, or null when nothing changed
     */
    public function takeOver(string $partyKey, Actor $speaker): ?string
    {
        $previous = $this->connection()->fetchAssociative(
            'SELECT bot_guid, bot_name FROM party_voice WHERE party_key = :key',
            ['key' => $partyKey],
        );

        if (false !== $previous && (int) $previous['bot_guid'] === $speaker->guid) {
            return null;
        }

        $this->connection()->executeStatement(
            'INSERT INTO party_voice (party_key, bot_guid, bot_name) VALUES (:key, :guid, :name)
             ON CONFLICT(party_key) DO UPDATE SET bot_guid = excluded.bot_guid, bot_name = excluded.bot_name',
            ['key' => $partyKey, 'guid' => $speaker->guid, 'name' => $speaker->name],
        );

        // A party that has never had a voice is not a handover, it is the first word.
        return false === $previous ? null : (string) $previous['bot_name'];
    }

    /**
     * Forget a party entirely — used when it disbands.
     */
    public function forget(string $partyKey): void
    {
        $this->connection()->executeStatement('DELETE FROM party_member WHERE party_key = :key', ['key' => $partyKey]);
        $this->connection()->executeStatement('DELETE FROM party_voice WHERE party_key = :key', ['key' => $partyKey]);
    }

    /**
     * @param Actor[] $members
     */
    private function noteArrivals(string $partyKey, array $members): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        foreach ($members as $member) {
            // Ignored when the row already exists, which is the point: first sighting is the
            // only one that counts, and re-noting it on every line would reset seniority.
            $this->connection()->executeStatement(
                'INSERT OR IGNORE INTO party_member (party_key, bot_guid, first_seen) VALUES (:key, :guid, :at)',
                ['key' => $partyKey, 'guid' => $member->guid, 'at' => $now],
            );
        }
    }

    /**
     * @return array<int, string> bot guid => first seen
     */
    private function seniority(string $partyKey): array
    {
        return array_map(strval(...), $this->connection()->fetchAllKeyValue(
            'SELECT bot_guid, first_seen FROM party_member WHERE party_key = :key',
            ['key' => $partyKey],
        ));
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
            'CREATE TABLE IF NOT EXISTS party_member (
                party_key TEXT NOT NULL,
                bot_guid INTEGER NOT NULL,
                first_seen TEXT NOT NULL,
                PRIMARY KEY (party_key, bot_guid)
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS party_voice (
                party_key TEXT PRIMARY KEY,
                bot_guid INTEGER NOT NULL,
                bot_name TEXT NOT NULL
            )',
        );

        return $this->connection;
    }
}
