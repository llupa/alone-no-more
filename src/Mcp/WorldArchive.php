<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Knowledge\Tool\ArchiveTools;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * The server's own records, over MCP.
 *
 * Thin by design: the lookups are the same ones the in-game archivist uses, and duplicating them
 * here would mean two definitions of "where does this drop" that could drift apart. What differs
 * is only who is asking.
 *
 * Worth exposing even though an assistant has read every wiki: these answer for *this* server,
 * with its own content, its own drop rates and whatever a server owner has changed.
 */
final readonly class WorldArchive
{
    public function __construct(private ArchiveTools $archive)
    {
    }

    #[McpTool(
        name: 'look_up_quest',
        description: 'Look up a quest by name in the server\'s own data: its level, what it asks for, and what it needs. Reports every step when a chain reuses one title.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function lookUpQuest(string $name): string
    {
        return $this->archive->findQuest($name);
    }

    #[McpTool(
        name: 'look_up_item',
        description: 'Look up an item by name: its quality, item level and the level required to use it.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function lookUpItem(string $name): string
    {
        return $this->archive->findItem($name);
    }

    #[McpTool(
        name: 'where_to_get',
        description: 'Find where an item comes from: which creatures drop it and how often, or which containers hold it, and roughly where in the world.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function whereToGet(string $itemName): string
    {
        return $this->archive->whoDrops($itemName);
    }

    #[McpTool(
        name: 'where_is',
        description: 'Find where a named person or creature stands — the quest giver, the vendor, the thing to kill. Gives the zone; direction and distance only when asked from inside the game.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function whereIs(string $name): string
    {
        return $this->archive->whereIs($name);
    }

    #[McpTool(
        name: 'find_creature',
        description: 'Look up creatures by name: their level, how many are in the world and roughly where. Use for "what are these" or "how tough are they".',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function findCreature(string $name): string
    {
        return $this->archive->findCreature($name);
    }

    #[McpTool(
        name: 'what_needs_item',
        description: 'Find which quests want an item. Use to answer what something in a bag is for.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function whatNeedsItem(string $itemName): string
    {
        return $this->archive->whatNeeds($itemName);
    }
}
