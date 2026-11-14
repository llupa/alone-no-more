<?php

declare(strict_types=1);

namespace App\World;

use App\World\Model\Actor;
use App\World\Model\Position;

/**
 * Everything this application needs to know about, and do to, a running game world.
 *
 * The seam exists for two reasons beyond testing. First, a recorded session can implement it, so
 * the demo runs with no WoW server at all. Second, the live implementation is assembled from
 * three unrelated transports — SOAP, a TCP line protocol and direct SQL — and callers should
 * never have to care which one answers a given question.
 */
interface WorldGatewayInterface
{
    /**
     * Pull the world's live state into the characters table.
     *
     * AzerothCore only persists a character's position every `PlayerSaveInterval` (15 minutes by
     * default), so without this every read is badly stale. Costs ~24 ms with 100 bots online.
     */
    public function refresh(): void;

    public function findActor(string $name): ?Actor;

    public function findActorByGuid(int $guid): ?Actor;

    /**
     * Real people currently in the world — everyone online who is not a bot.
     *
     * @return Actor[]
     */
    public function onlinePlayers(): array;

    /**
     * Everyone currently online, player and bot alike.
     *
     * @return Actor[] keyed by guid
     */
    public function onlineActors(): array;

    /**
     * Everyone within `yards` of a point on the same map, nearest first.
     *
     * @return Actor[]
     */
    public function actorsNear(Position $centre, float $yards): array;

    /**
     * @return Actor[]
     */
    public function actorsInZone(int $zoneId): array;

    /**
     * Run a GM console command and return its output. The escape hatch for the long tail of
     * things the server can do that this interface does not model.
     */
    public function execute(string $command): string;
}
