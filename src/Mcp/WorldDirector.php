<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Herding\BotStagerInterface;
use App\Roleplay\PlayerbotVocabulary;
use App\Roleplay\Tool\PlayerbotCommandRunner;
use App\World\Exception\WorldGatewayException;
use App\World\WorldGatewayInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Changing the world, rather than reading it.
 *
 * This is the half that makes the MCP server worth having: an assistant can populate a zone, send
 * a crowd away, or give a companion an order, by being asked to in plain words. The server is
 * doing the work — these tools only decide which bots and which orders.
 *
 * Kept separate from {@see WorldInspector} because the annotations differ in a way clients act
 * on: everything there is `readOnlyHint`, nothing here is.
 */
final readonly class WorldDirector
{
    public function __construct(
        private WorldGatewayInterface $world,
        private BotStagerInterface $stager,
        private PlayerbotCommandRunner $runner,
    ) {
    }

    #[McpTool(
        name: 'summon_crowd',
        description: <<<'TXT'
            Populate the area around a character with bots of a similar level, logging them in
            where they belong rather than teleporting them. Use when a zone feels empty, or to
            prepare a place before someone arrives. Returns how many were brought in and sent
            away.
            TXT,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function summonCrowd(string $aroundCharacter, ?int $howMany = null): string
    {
        $this->world->refresh();
        $actor = $this->world->findActor($aroundCharacter);

        if (null === $actor) {
            return \sprintf('No character called "%s" exists on this server.', $aroundCharacter);
        }

        if (!$actor->isOnline) {
            return \sprintf('%s is offline, so there is nowhere to gather anyone.', $actor->name);
        }

        return $this->stager->stage($actor, $howMany)->summary();
    }

    #[McpTool(
        name: 'send_bots_away',
        description: 'Log out bots that have drifted further than the given distance from a character, thinning a crowd without emptying it. Party members are never sent away.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function sendBotsAway(string $aroundCharacter, float $beyondYards = 1200.0): string
    {
        $this->world->refresh();
        $actor = $this->world->findActor($aroundCharacter);

        if (null === $actor) {
            return \sprintf('No character called "%s" exists on this server.', $aroundCharacter);
        }

        $sent = $this->stager->dismissDistant($actor, $beyondYards);

        return [] === $sent
            ? \sprintf('No bots were further than %d yards from %s.', (int) $beyondYards, $actor->name)
            : \sprintf('Sent away %d: %s.', \count($sent), implode(', ', $sent));
    }

    #[McpTool(
        name: 'bot_command',
        description: <<<'TXT'
            Give one bot an order, using the playerbots vocabulary — follow, stay, attack, flee,
            grind, revive, repair, buff, pull, taxi, home, mount and so on. Use for a single
            companion; to move a crowd, use summon_crowd instead.
            TXT,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function botCommand(string $botName, string $command): string
    {
        $command = trim($command);

        // The vocabulary is generated from the mod's own command handlers, so this rejects a
        // hallucinated verb before it reaches the server rather than after. Note `isKnownOrder`
        // and not `isCommand`: the latter asks whether a line of *chat* was meant as an order,
        // which is a different question with a different answer.
        if (!PlayerbotVocabulary::isKnownOrder($command)) {
            return \sprintf(
                '"%s" is not a playerbots command. Known commands include: %s.',
                $command,
                implode(', ', \array_slice(PlayerbotVocabulary::commands(), 0, 30)),
            );
        }

        // Name the target before ordering it about. This is not politeness: `playerbots exec`
        // runs on a GM console, and the name reaching it should be one we have just read out of
        // the characters table rather than a string an assistant composed.
        $bot = $this->world->findActor($botName);

        if (null === $bot || !$bot->isBot) {
            return \sprintf('There is no bot called "%s" to give orders to.', $botName);
        }

        try {
            return $this->runner->run($bot->name, $command);
        } catch (WorldGatewayException $e) {
            return \sprintf('The order did not reach %s: %s', $bot->name, $e->getMessage());
        }
    }
}
