<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Director\Settings;
use App\World\WorldGatewayInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * The director's controls, as tools.
 *
 * This is the project's answer to "where is the user interface": there isn't one, and there does
 * not need to be. Anything you would have reached for a dashboard to change — how busy the world
 * is, who it follows, whether it is herding at all — is a sentence to an assistant instead.
 *
 * Changes take effect on the listener's next pass rather than needing a restart, which is the
 * entire point of keeping them out of `.env`.
 */
final readonly class WorldSettings
{
    public function __construct(
        private Settings $settings,
        private WorldGatewayInterface $world,
    ) {
    }

    #[McpTool(
        name: 'world_settings',
        description: 'Show how the director is currently set up: crowd size, which characters it follows, whether it is herding, and how talkative companions are.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function show(): string
    {
        $settings = $this->settings->all();

        return implode("\n", [
            \sprintf('Crowd size: about %d bots kept in the zone.', $settings['crowd_size']),
            \sprintf('Level range: within %d levels of the player.', $settings['level_tolerance']),
            \sprintf('Companions wait %d seconds before answering again.', $settings['reply_cooldown_seconds']),
            \sprintf('Herding is %s.', $settings['herding'] ? 'on' : 'off'),
            [] === $settings['followed']
                ? 'Following: whoever is online.'
                : 'Following: '.implode(', ', $settings['followed']).'.',
        ]);
    }

    #[McpTool(
        name: 'configure_world',
        description: <<<'TXT'
            Change how the director behaves while it runs. Settings: crowd_size (how many bots to
            keep nearby), level_tolerance (how far from the player's level they may be),
            reply_cooldown_seconds (how long a companion waits before speaking again). Takes
            effect on the next pass, with no restart.
            TXT,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function configure(string $setting, int $value): string
    {
        try {
            $this->settings->setNumber($setting, $value);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return \sprintf('%s is now %d.', $setting, $value);
    }

    #[McpTool(
        name: 'set_herding',
        description: 'Turn the crowd on or off. Off leaves the world as it is and stops bringing anyone in — use it when someone wants the place to themselves.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function setHerding(bool $on): string
    {
        $this->settings->setHerding($on);

        return $on
            ? 'Herding is on; the world will fill around whoever is being followed.'
            : 'Herding is off. Nobody new will be brought in, and nobody already here will be sent away.';
    }

    #[McpTool(
        name: 'follow_character',
        description: <<<'TXT'
            Name a character the world should be kept alive around — normally the one being
            played. With none named the director follows whoever is online, which splits the
            crowd when an alt is parked somewhere else.
            TXT,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function follow(string $characterName): string
    {
        $actor = $this->world->findActor($characterName);

        if (null === $actor) {
            return \sprintf('No character called "%s" exists on this server.', $characterName);
        }

        if ($actor->isBot) {
            return \sprintf('%s is a bot. Follow a character someone is playing.', $actor->name);
        }

        $this->settings->follow($actor->name);

        return \sprintf(
            'Now following %s. The world will be kept populated around them%s.',
            $actor->name,
            \count($this->settings->followed()) > 1 ? ' as well as the others' : ' and nobody else',
        );
    }

    #[McpTool(
        name: 'stop_following_character',
        description: 'Stop keeping the world populated around a character. With none left named, the director goes back to following whoever is online.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function stopFollowing(string $characterName): string
    {
        if (!$this->settings->stopFollowing($characterName)) {
            return \sprintf('%s was not being followed.', $characterName);
        }

        return [] === $this->settings->followed()
            ? \sprintf('No longer following %s. Back to following whoever is online.', $characterName)
            : \sprintf('No longer following %s. Still following: %s.', $characterName, implode(', ', $this->settings->followed()));
    }
}
