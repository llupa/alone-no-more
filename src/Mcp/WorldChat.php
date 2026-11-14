<?php

declare(strict_types=1);

namespace App\Mcp;

use App\World\Chat\ChatEventRepositoryInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * What the world has been saying.
 *
 * Chat is captured by our own server module into `anm_chat_event`, so this is everything said in
 * earshot — players and bots alike — not just lines addressed to us. For an assistant it is the
 * closest thing the world has to a pulse: who is talking, about what, and whether the place feels
 * alive at all.
 */
final readonly class WorldChat
{
    public function __construct(private ChatEventRepositoryInterface $events)
    {
    }

    #[McpTool(
        name: 'recent_chat',
        description: 'The most recent lines spoken in the world, with who said them and on which channel. Use to see what is happening right now.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function recentChat(int $limit = 20): string
    {
        $latest = $this->events->latestId();
        // `since()` is exclusive, so step back by the window we want rather than from zero —
        // the table holds every line the server has ever carried.
        $events = $this->events->since(max(0, $latest - $limit), $limit);

        if ([] === $events) {
            return 'Nothing has been said recently.';
        }

        return implode("\n", array_map(
            static fn ($event): string => \sprintf(
                '%s [%s] %s: %s',
                $event->createdAt->format('H:i:s'),
                $event->channel->name,
                $event->senderName,
                $event->message,
            ),
            $events,
        ));
    }
}
