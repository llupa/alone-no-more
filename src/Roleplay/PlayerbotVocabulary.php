<?php

declare(strict_types=1);

namespace App\Roleplay;

/**
 * The vocabulary mod-playerbots already answers to.
 *
 * These words are orders, not conversation. The mod routes a whisper or party line straight to
 * the bot it names, so a player typing "grind" or "follow" is driving their companion — and a
 * roleplay layer that replies "what an interesting question" talks over the thing actually
 * happening. It did precisely that in testing: a whispered "grind" produced a conversation about
 * chanting instead of a bot going to grind.
 *
 * Generated from ChatCommandHandlerStrategy rather than typed out, so the list cannot drift from
 * what the mod really accepts.
 */
final class PlayerbotVocabulary
{
    /** @var string[] */
    private const COMMANDS = [
        'accept',
        'add all loot',
        'attack',
        'attackers',
        'aura',
        'autogear',
        'autogear bis',
        'b',
        'bank',
        'buff',
        'bwl',
        'c',
        'calc',
        'cancel aquatic form',
        'cancel bear form',
        'cancel cat form',
        'cancel dire bear form',
        'cancel moonkin form',
        'cancel travel form',
        'cancel tree form',
        'cast',
        'castnc',
        'cdebug',
        'chat',
        'cheat',
        'co',
        'craft',
        'cs',
        'de',
        'debug',
        'destroy',
        'disperse',
        'dps',
        'drink',
        'drop',
        'e',
        'emblems',
        'emote',
        'enter vehicle',
        'equip upgrade',
        'flag',
        'flee',
        'focus heal',
        'follow',
        'formation',
        'gb',
        'ginvite',
        'give leader',
        'glyph equip',
        'glyphs',
        'go',
        'grind',
        'guild demote',
        'guild leave',
        'guild promote',
        'guild remove',
        'help',
        'hire',
        'home',
        'inv',
        'invite',
        'items',
        'leave',
        'leave vehicle',
        'lfg',
        'll',
        'log',
        'los',
        'mail',
        'maintenance',
        'max dps',
        'move from group',
        'naxx',
        'nc',
        'nt',
        'open items',
        'outfit',
        'pet',
        'pet attack',
        'position',
        'pull',
        'pull back',
        'pull rti',
        'pvp stats',
        'q',
        'qi',
        'quests',
        'r',
        'ra',
        'range',
        'ready',
        'rebuff',
        'release',
        'remove glyph',
        'rep',
        'repair',
        'reputation',
        'revive',
        'roll',
        'rpg do quest',
        'rpg status',
        'rti',
        'rtsc',
        'runaway',
        's',
        'save mana',
        'sendmail',
        'share',
        'spell',
        'spells',
        'ss',
        'stance',
        'stats',
        'stay',
        'summon',
        't',
        'talents',
        'talk',
        'tame',
        'tank attack',
        'target',
        'target me',
        'taxi',
        'teleport',
        'tell pvp stats',
        'trainer',
        'u',
        'ue',
        'unlock items',
        'unlock traded item',
        'wait for attack time',
        'warning',
        'who',
        'wipe',
        'wts',
    ];

    /**
     * Whether a word is a real order in the mod's vocabulary.
     *
     * Distinct from {@see isCommand()}, which asks whether a *chat line* was meant as an order —
     * a question about intent, answered by the prefix. This asks whether a verb exists at all,
     * and is for callers that already know they are issuing one: the MCP tool, where an
     * assistant composes the verb and may invent a plausible one.
     */
    public static function isKnownOrder(string $command): bool
    {
        $text = strtolower(trim($command));

        if (\in_array($text, self::COMMANDS, true)) {
            return true;
        }

        // Commands that take an argument: `co +dps`, `cast fireball`.
        $verb = strtok($text, ' ');

        return false !== $verb && \in_array($verb, self::COMMANDS, true);
    }

    /**
     * The whole vocabulary, for telling a caller what it may say.
     *
     * @return string[]
     */
    public static function commands(): array
    {
        return self::COMMANDS;
    }

    /**
     * Whether this line is an order to a bot rather than something said to it.
     *
     * Once `Playerbots.CommandPrefix` is set, the mod ignores every chat line that does not
     * start with it — so the question stops being a judgement call and becomes a prefix check.
     *
     * The guesswork it replaces was a steady source of bugs. `who` is a real command, so "Who is
     * Anub'arak?" was handed to the mod and the player got silence. We added a question-mark
     * rule, then a list of English words, and each fix only narrowed the next misreading. A
     * player who wants to drive a bot directly types "!follow"; everything else is conversation,
     * and there is nothing left to guess.
     */
    public static function isCommand(string $message, string $prefix = '!'): bool
    {
        $text = trim($message);

        return '' !== $prefix && str_starts_with($text, $prefix) && \strlen($text) > \strlen($prefix);
    }
}
