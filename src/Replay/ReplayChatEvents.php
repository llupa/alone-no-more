<?php

declare(strict_types=1);

namespace App\Replay;

use App\World\Chat\ChatEventRepositoryInterface;
use App\World\Model\ChatChannel;
use App\World\Model\ChatEvent;
use App\World\Model\Position;

/**
 * A recorded conversation, handed out the way the live repository hands out a running one.
 *
 * `since()` keeps its watermark semantics, so the listener replays the session a line at a time
 * exactly as it would read a live one — which is what makes a recorded demo look like the real
 * thing rather than a wall of text.
 */
final class ReplayChatEvents implements ChatEventRepositoryInterface
{
    /** @var ChatEvent[]|null */
    private ?array $events = null;

    public function __construct(private readonly Snapshot $snapshot)
    {
    }

    /**
     * @return ChatEvent[]
     */
    public function since(int $watermark, int $limit = 50): array
    {
        $newer = array_values(array_filter($this->events(), static fn (ChatEvent $e): bool => $e->id > $watermark));

        return \array_slice($newer, 0, $limit);
    }

    /**
     * A recording has no clock worth consulting: every line in it was said before it was
     * written down. The whole capture is "since" any moment you could name.
     */
    public function firstIdSince(string $at): int
    {
        return 0;
    }

    public function latestId(): int
    {
        $events = $this->events();

        return [] === $events ? 0 : end($events)->id;
    }

    /**
     * @return ChatEvent[]
     */
    public function conversationBetween(int $botGuid, int $playerGuid, int $limit = 20): array
    {
        $between = array_values(array_filter(
            $this->events(),
            static fn (ChatEvent $e): bool => \in_array($e->senderGuid, [$botGuid, $playerGuid], true),
        ));

        return \array_slice($between, -$limit);
    }

    /**
     * @return ChatEvent[]
     */
    private function events(): array
    {
        if (null !== $this->events) {
            return $this->events;
        }

        $events = [];

        /** @var array<string, mixed> $row */
        foreach ($this->snapshot->section('chat') as $row) {
            $channel = ChatChannel::tryFrom((int) $row['channel']);

            if (null === $channel) {
                continue;
            }

            $events[] = new ChatEvent(
                id: (int) $row['id'],
                createdAt: new \DateTimeImmutable((string) $row['at']),
                senderGuid: (int) $row['senderGuid'],
                senderName: (string) $row['senderName'],
                channel: $channel,
                message: (string) $row['message'],
                receiverGuid: (int) ($row['receiverGuid'] ?? 0),
                channelName: (string) ($row['channelName'] ?? ''),
                position: new Position(
                    (float) ($row['x'] ?? 0),
                    (float) ($row['y'] ?? 0),
                    (float) ($row['z'] ?? 0),
                    (int) ($row['map'] ?? 0),
                ),
                senderIsBot: (bool) ($row['senderIsBot'] ?? false),
            );
        }

        usort($events, static fn (ChatEvent $a, ChatEvent $b): int => $a->id <=> $b->id);

        return $this->events = $events;
    }
}
