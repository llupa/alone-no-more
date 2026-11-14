<?php

declare(strict_types=1);

namespace App\Director;

use App\Config\Settings;
use App\World\Model\Actor;
use App\World\WorldGatewayInterface;

/**
 * Which character the person at the keyboard is playing.
 *
 * The whole application turns on this one answer — whose zone to populate, who a companion is
 * talking to — and for a long time it was a name typed into a config file. That broke the moment
 * the name was stale: the director spent an evening faithfully following a character who was not
 * logged in, and reported it by going completely silent.
 *
 * It does not need configuring. Every bot lives on an account named `RNDBOT…`, so the player is
 * simply the character online whose account is not one of those. One person playing alone has
 * exactly one, and nobody has to tell us their name.
 *
 * A pin is still honoured, because someone with two characters online does need to say which one
 * matters. But a pin that nobody is playing is a stale pin, and falling back beats silence.
 */
final readonly class PlayerCharacter
{
    public function __construct(
        private WorldGatewayInterface $world,
        private Settings $settings,
    ) {
    }

    /**
     * The character being played, or null when nobody is online.
     *
     * Prefers a pinned name; falls back to whoever is actually there.
     */
    public function current(): ?Actor
    {
        return $this->pinned()[0] ?? $this->onlinePlayers()[0] ?? null;
    }

    /**
     * Everyone whose world should be kept alive — normally one person.
     *
     * @return Actor[]
     */
    public function toFollow(): array
    {
        $pinned = $this->pinned();

        return [] !== $pinned ? $pinned : $this->onlinePlayers();
    }

    /**
     * Why we are following someone other than the pinned character, if we are.
     *
     * Returned rather than logged so the caller decides how loudly to say it: this is the
     * difference between "nothing is happening" and "nothing is happening *because*".
     */
    public function pinIsStale(): ?string
    {
        $names = $this->settings->followed();

        if ([] === $names || [] !== $this->pinned()) {
            return null;
        }

        return \sprintf('%s is not online', implode(' or ', $names));
    }

    /**
     * @return Actor[]
     */
    private function pinned(): array
    {
        $names = $this->settings->followed();

        if ([] === $names) {
            return [];
        }

        return array_values(array_filter(
            $this->onlinePlayers(),
            static function (Actor $player) use ($names): bool {
                foreach ($names as $name) {
                    if (0 === strcasecmp($player->name, $name)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @return Actor[]
     */
    private function onlinePlayers(): array
    {
        // onlinePlayers() already excludes the RNDBOT accounts, which is the whole definition.
        return array_values($this->world->onlinePlayers());
    }
}
