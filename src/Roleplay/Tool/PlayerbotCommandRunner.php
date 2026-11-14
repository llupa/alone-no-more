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
