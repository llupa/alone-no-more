<?php

declare(strict_types=1);

namespace App\Roleplay\Tool;

use App\World\Exception\WorldGatewayException;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends a playerbot chat command to a specific bot.
 *
 * `.playerbots exec <bot> <command>` is a GM console command, so it reaches us over SOAP and
 * arrives at the bot exactly as if its master had whispered the order. That is the whole reason
 * natural-language control is possible without writing any more C++: the mod already has a rich
 * command vocabulary, we just need to decide which word to say.
 *
 * It is also the one place in the project where text chosen by a model reaches a *GM* console,
 * which is privileged enough to create accounts and ban players. The model does not choose it
 * freely — but the text it chooses from is not ours: `recent_chat` hands an assistant whatever
 * players have typed, and an instruction hidden in a chat line is a well-known way to steer a
 * tool call. So both halves of the command are checked here, at the single point that builds it,
 * rather than at each of the callers.
 */
final readonly class PlayerbotCommandRunner
{
    public function __construct(
        private WorldGatewayInterface $world,
        /**
         * The mod's `Playerbots.CommandPrefix`, which must match the server's config.
         *
         * With a prefix set, mod-playerbots ignores any chat line that does not start with it —
         * which is the whole point. Unprefixed, it treated "Do you have quests?" as the action
         * `you have quests?` and answered with a red error, and it opened a trade window at the
         * mere mention of a word like "cloth" or "fishing". Both are now simply not its
         * business, with no patch to the mod.
         *
         * `playerbots exec` goes through the same gate, so our own orders carry it too.
         */
        #[Autowire(env: 'ANM_BOT_COMMAND_PREFIX')]
        private string $prefix = '!',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Character names in 3.3.5a are two to twelve letters: no spaces, no digits, no punctuation.
     * Anything else cannot name a real bot, so it is only ever an attempt to end the argument
     * early and start a second command.
     */
    private const NAME = '/^[A-Za-z]{2,12}$/';

    /**
     * A command is a verb and at most a few plain arguments. Control characters have no business
     * in one, and a newline is the obvious candidate for smuggling a second console line through
     * the SOAP envelope, which escapes XML but not whitespace.
     */
    private const COMMAND = '/^[A-Za-z0-9 +\-_,.\'!?]{1,80}$/';

    /**
     * Send a bot to a point on its own map.
     *
     * `go` takes `x;y;z`, and the semicolons are the reason this is its own method rather than a
     * string handed to {@see run()}: that method bans `;` on purpose, because smuggling a second
     * console command is the attack it exists to prevent. Here the coordinates are formatted
     * from floats we computed, so nothing a model wrote ever reaches the console.
     *
     * The coordinate form of `go` has no distance limit, unlike the zone-coordinate form, so
     * this works across a whole continent.
     *
     * @throws WorldGatewayException when the name is malformed or the server refuses
     */
    public function goTo(string $botName, float $x, float $y, float $z): string
    {
        if (1 !== preg_match(self::NAME, $botName)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a character name.', $botName));
        }

        $command = \sprintf('go %.2f;%.2f;%.2f', $x, $y, $z);

        return $this->send($botName, $command);
    }

    /**
     * Move a bot's "stay" anchor to a point, so that it walks there and then holds.
     *
     * This is the mechanism that defeated four attempts at leading the way, turned around.
     * `StayStrategy` registers "return to stay position", whose trigger is simply
     * `distance > FollowDistance` with no upper bound, re-checked every tick — so a bot with a
     * stay anchor walks to it persistently and waits on arrival. That is exactly what leading
     * the way needs, and nothing issued from outside the engine can compete with it.
     *
     * The anchor must be moved with `position stay x,y,z` rather than the `stay` chat command,
     * which overwrites it with wherever the bot is currently standing.
     *
     * Own method for the same reason as {@see goTo()}: {@see COMMAND} has no business allowing
     * raw coordinate punctuation from a model, so the string is built here from floats. The
     * mod parses these with `atoi`, so they are whole yards — well inside `FollowDistance`.
     *
     * @throws WorldGatewayException when the name is malformed or the server refuses
     */
    public function anchorAt(string $botName, float $x, float $y, float $z): string
    {
        if (1 !== preg_match(self::NAME, $botName)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a character name.', $botName));
        }

        return $this->send($botName, \sprintf('position stay %d,%d,%d', (int) $x, (int) $y, (int) $z));
    }

    /**
     * Send a bot travelling to a named place, using the mod's own travel system.
     *
     * `go <x>;<y>;<z>` issues one movement order, and a strategy beats an order: a companion
     * told to lead walked a few paces and came back, twice over, under two different fixes.
     * `go travel <name>` is different in kind — it sets a *forced* travel target that the bot
     * pursues across ticks, which is how the mod sends its own bots across a continent.
     *
     * Matched on a title substring against quest, zone, NPC and creature destinations, so it
     * only works for places the travel system knows. The caller falls back to coordinates.
     *
     * @throws WorldGatewayException when the name is malformed or the server refuses
     */
    public function travelTo(string $botName, string $destination): string
    {
        if (1 !== preg_match(self::NAME, $botName)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a character name.', $botName));
        }

        if (1 !== preg_match('/^[A-Za-z0-9 \'-]{2,40}$/', $destination)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a place name.', $destination));
        }

        return $this->send($botName, 'go travel '.$destination);
    }

    /**
     * Have a bot put on one specific item out of its own bags.
     *
     * This is its own method for the same reason as {@see goTo()}: the command cannot be
     * expressed in the characters {@see COMMAND} allows. `EquipAction` parses its argument with
     * `ChatHelper::parseItems`, which scans for the substring `Hitem:` and has no name matching
     * at all — so a plain name is parsed to an empty set and the bot silently does nothing,
     * which is exactly how this looked when it was broken. It needs a real item link.
     *
     * A link is full of the punctuation a console argument should never contain, so none of it
     * comes from the model: the caller supplies an entry id it looked up in the bot's own
     * inventory, and the link is formatted from that integer here. The trailing `[name]` is the
     * part a human reads; the client never sees it, so it is deliberately not the name the model
     * typed.
     *
     * @param int $itemEntry an `item_template` id, which the caller must already have found in
     *                       this bot's bags
     *
     * @throws WorldGatewayException when the name is malformed or the server refuses
     */
    public function equipItem(string $botName, int $itemEntry): string
    {
        if (1 !== preg_match(self::NAME, $botName)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a character name.', $botName));
        }

        if ($itemEntry < 1) {
            throw new WorldGatewayException(\sprintf('%d is not an item.', $itemEntry));
        }

        return $this->send($botName, \sprintf('e |Hitem:%d:0:0:0:0:0:0:0:0|h[item]|h|r', $itemEntry));
    }

    /**
     * @return string the server's acknowledgement, e.g. `Nytalleath: 'follow' sent`
     *
     * @throws WorldGatewayException when the name or command is malformed, the bot is offline,
     *                               or the server rejects it
     */
    public function run(string $botName, string $command): string
    {
        if (1 !== preg_match(self::NAME, $botName)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a character name.', $botName));
        }

        if (1 !== preg_match(self::COMMAND, $command)) {
            throw new WorldGatewayException(\sprintf('"%s" is not a well-formed command.', $command));
        }

        return $this->send($botName, $command);
    }

    /**
     * One place that writes the prefix, so the two callers cannot disagree about it.
     */
    private function send(string $botName, string $command): string
    {
        $this->logger->info('Bot command: {bot} <- {command}', ['bot' => $botName, 'command' => $command]);

        return $this->world->execute(\sprintf('playerbots exec %s %s%s', $botName, $this->prefix, $command));
    }
}
