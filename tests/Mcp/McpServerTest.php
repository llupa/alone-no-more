<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Mcp\Capability\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The MCP server as a client meets it.
 *
 * This is the contract an external assistant depends on: the tool names it calls, and the
 * annotations it uses to decide what it is allowed to do without asking. Renaming a method is
 * free; renaming a tool breaks every saved prompt pointing at it, and nothing else in the suite
 * would notice.
 *
 * No world server is needed — registration is static, and the calls themselves are covered where
 * the services are.
 */
final class McpServerTest extends KernelTestCase
{
    private function registry(): Registry
    {
        self::bootKernel();

        // Tools reach the registry through loaders the builder runs, so asking for the registry
        // alone hands back an empty one. Building the server is what populates it.
        self::getContainer()->get('mcp.server.default');

        /** @var Registry $registry */
        $registry = self::getContainer()->get('mcp.server.default.registry');

        return $registry;
    }

    /**
     * @return string[]
     */
    private function toolNames(): array
    {
        $names = array_map(
            static fn (object $tool): string => $tool->name,
            iterator_to_array($this->registry()->getTools()),
        );

        sort($names);

        return $names;
    }

    #[DataProvider('expectedTools')]
    public function testTheWorldIsExposedAsNamedTools(string $tool): void
    {
        self::assertContains($tool, $this->toolNames());
    }

    /**
     * @return iterable<string, array<int, mixed>>
     */
    public static function expectedTools(): iterable
    {
        yield 'where a character is' => ['world_player_state'];
        yield 'who is around them' => ['world_nearby_actors'];
        yield 'where the world is busy' => ['world_zone_population'];
        yield 'what to get on with' => ['what_to_do_next'];
        yield 'quests' => ['look_up_quest'];
        yield 'items' => ['look_up_item'];
        yield 'where an item comes from' => ['where_to_get'];
        yield 'what an item is for' => ['what_needs_item'];
        yield 'where someone stands' => ['where_is'];
        yield 'finding a creature' => ['find_creature'];
        yield 'what is being said' => ['recent_chat'];
        yield 'populating a zone' => ['summon_crowd'];
        yield 'thinning a crowd' => ['send_bots_away'];
        yield 'ordering one bot' => ['bot_command'];
        yield 'how the director is set up' => ['world_settings'];
        yield 'changing how it behaves' => ['configure_world'];
        yield 'turning the crowd off' => ['set_herding'];
        yield 'whose world to keep alive' => ['follow_character'];
        yield 'and no longer' => ['stop_following_character'];
        yield 'setting a companion aside' => ['prepare_companion'];
        yield 'who is waiting' => ['planned_companions'];
        yield 'changing your mind' => ['cancel_companion'];
    }

    /**
     * A client may run a read-only tool without asking permission, so a tool that changes the
     * world must never claim to be one.
     */
    public function testOnlyTheReadingToolsAreMarkedReadOnly(): void
    {
        $changesTheWorld = [
            'summon_crowd', 'send_bots_away', 'bot_command',
            'configure_world', 'set_herding', 'follow_character', 'stop_following_character',
            'prepare_companion', 'cancel_companion',
        ];

        $readOnly = [];

        foreach ($this->registry()->getTools() as $tool) {
            if (true === ($tool->annotations->readOnlyHint ?? false)) {
                $readOnly[] = $tool->name;
            }
        }

        foreach ($changesTheWorld as $tool) {
            self::assertNotContains($tool, $readOnly, \sprintf('"%s" changes the world and must not be readOnlyHint.', $tool));
        }

        self::assertContains('world_player_state', $readOnly);
    }

    /**
     * The registry is scoped to App\Mcp so a bundle we install later cannot quietly publish its
     * own tools to anyone pointed at this server.
     */
    public function testNothingOutsideOurNamespaceIsPublished(): void
    {
        $expected = array_map(static fn (array $case): string => $case[0], iterator_to_array(self::expectedTools()));
        sort($expected);

        self::assertSame($expected, $this->toolNames());
    }
}
