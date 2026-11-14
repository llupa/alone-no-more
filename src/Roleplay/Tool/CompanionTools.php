<?php

declare(strict_types=1);

namespace App\Roleplay\Tool;

use App\Knowledge\BagsInterface;
use App\Knowledge\Bearing;
use App\Knowledge\GameKnowledgeInterface;
use App\Knowledge\LevelProgressInterface;
use App\Knowledge\QuestLogInterface;
use App\Roleplay\Errand\Errand;
use App\Roleplay\Errand\ErrandBook;
use App\Roleplay\Travel\Journey;
use App\Roleplay\Travel\Journeys;
use App\World\Chat\BotSpeechDispatcher;
use App\World\Exception\WorldGatewayException;
use App\World\Model\Position;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What a companion can do, and what it can find out about itself.
 *
 * Deliberately few. Every tool's description is sent on every request, and a model given a long
 * menu invents dishes that are not on it — ours once called a tool named `lookup_object`, which
 * has never existed, and recited the call into party chat. A short menu is a menu a model keeps
 * straight.
 *
 * They cover only what cannot be answered another way: the player's own words already reach the
 * bot as orders when they are orders, and what a bot knows about the world around it is already
 * in its prompt. What is left is acting on intent that is phrased as conversation, and reading
 * the bot's own state off the server.
 */
#[AsTool(
    name: 'do_this',
    description: <<<'TXT'
        Carry out something the player asked of you, when they asked in passing rather than as an
        order. Pass one of: follow, stay, attack, flee, runaway, grind, revive, release, repair,
        buff, rebuff, ready, pull, disperse, leave, invite, taxi, home, drink, mount, dismount.
        Use it when they say things like "come along", "wait here a moment", "get it off me",
        "we should run". Do not use it when they are only talking about doing something.
        TXT,
    method: 'doThis',
)]
#[AsTool(
    name: 'attack_something',
    description: <<<'TXT'
        Go and fight. Use whenever the player wants something killed — "attack", "kill the
        kobolds", "I need four of those wolves", "get it off me", "help me fight" — including
        when they have not clicked a target. Pass what they called it ("kobold", "wolf") if they
        named it, or leave it empty for whatever is nearest. Prefer this over do_this for
        anything to do with fighting.
        TXT,
    method: 'attackSomething',
)]
#[AsTool(
    name: 'lead_the_way',
    description: <<<'TXT'
        Walk to a named person or creature so the player can follow you. Use when they ask to be
        taken somewhere — "lead me to Deputy Willem", "take me to the quest giver", "show me
        where he is". Pass the name they used. You will stop a few paces short of them.

        You can only walk them somewhere nearby. If the answer says it is too far, do not claim
        to be setting off: tell them the direction and roughly how far, and offer to follow
        them there instead. For telling someone where something is without walking there,
        consult the archives instead.
        TXT,
    method: 'leadTheWay',
)]
#[AsTool(
    name: 'keep_fighting',
    description: <<<'TXT'
        Keep going at something until the job is done, instead of stopping after one enemy. Use
        when the player wants a task finished — "attack until you have seven", "let's collect
        pelts", "clear this camp", "keep killing them". Pass what they called it ("wolf",
        "kobold"), and the exact title of the quest it is for when you know it, so you stop when
        that quest is satisfied rather than fighting all evening.
        TXT,
    method: 'keepFighting',
)]
#[AsTool(
    name: 'stop_fighting',
    description: 'Stop fighting on your own and come back to the player. Use for "stop", "that is enough", "come back", "hold up".',
    method: 'stopFighting',
)]
#[AsTool(
    name: 'wear_this',
    description: <<<'TXT'
        Put on something that is already in your bags. Use when the player names a piece of gear
        and asks you to wear or equip it. You can only wear what you are carrying — if they are
        still holding it, say so and ask them to hand it over. For "put on anything better you
        are carrying", use do_this with "equip upgrade" instead.
        TXT,
    method: 'wearThis',
)]
#[AsTool(
    name: 'check_experience',
    description: <<<'TXT'
        How far you or the player are through your current level. Use for "how much more xp do
        you need", "how close are you to levelling", "am I nearly there". Pass whose: "mine" for
        your own, "theirs" for the player's.
        TXT,
    method: 'checkExperience',
)]
#[AsTool(
    name: 'check_my_quest_log',
    description: 'Read your own quest log. Use whenever the player asks what you are working on, what quests you have, whether you have finished one, or whether you share one with them — you cannot know this otherwise.',
    method: 'checkMyQuestLog',
)]
#[AsTool(
    name: 'check_their_quest_log',
    description: <<<'TXT'
        Look at what the player is working on right now, and how far along they are. Use whenever
        they talk about their own quests — "I have two already", "I need to finish this", "where
        do I hand this in", "what should I do next" — or whenever you would otherwise have to
        guess which quest they mean. This is the only way to know what they are carrying progress
        on; the archives describe quests, not people.
        TXT,
    method: 'checkTheirQuestLog',
)]
#[AsTool(
    name: 'check_my_bags',
    description: 'Look through what you are carrying. Use when the player asks what you have, whether you have food, water, or some particular thing.',
    method: 'checkMyBags',
)]
final readonly class CompanionTools
{
    /**
     * What a companion may actually be told to do — the same list the tool description offers,
     * and nothing else.
     *
     * `PlayerbotVocabulary::isCommand()` is the wrong gate here, and the difference is worth
     * spelling out because it looked right for weeks. That method answers "is this line an
     * order rather than conversation", so it spans the mod's whole 135-command vocabulary; used
     * as an execution gate it also admitted `destroy`, `sendmail`, `drop`, `guild remove` and a
     * hundred others the description never mentioned. The model picks these verbs from text it
     * does not control — party chat it overheard, and whatever any player typed — so the gate
     * has to be the promise, not the vocabulary.
     *
     * Matched exactly, with no arguments: everything here is a bare verb, and anything carrying
     * an argument is reaching for something this list does not cover.
     */
    private const COMPANION_ACTIONS = [
        'follow', 'stay', 'attack', 'flee', 'runaway', 'grind', 'revive', 'release', 'repair',
        'buff', 'rebuff', 'ready', 'pull', 'disperse', 'leave', 'invite', 'taxi', 'home',
        'drink', 'mount', 'dismount',
        // Gear, added after a companion had to refuse a reasonable request. It takes no
        // arguments and only re-equips what the bot already carries (EquipUpgradeAction is
        // SelectInventoryItemsToEquip + EquipItems), which is why it can sit in the bare verb
        // list while equipping a *named* item needs its own tool. Deliberately *not* here:
        // `autogear`, which runs PlayerbotFactory::InitEquipment and conjures fresh items into
        // the bot's slots. That is a gearing cheat, not a request a companion should honour.
        'equip upgrade',
    ];

    /**
     * How far a companion can actually be sent, which is not a choice — it is
     * `Playerbots.ReactDistance` minus a margin. See {@see leadTheWay()}.
     */
    private const LEAD_YARDS = 140.0;

    /**
     * The orders that take movement over, and so end any walk in progress.
     *
     * A journey is hot memory, like an errand: it is taken in, drained a leg at a time, and
     * must be let go the moment something else owns the companion's feet. Without this the
     * container outlived the thing it described — a player saying "follow me" mid-walk got a
     * bot that followed for one second and then re-anchored itself, because {@see Journeys}
     * still believed it was travelling. The journey has to be cancelled by whatever supersedes
     * it, not left to time out.
     */
    private const ORDERS_THAT_END_A_WALK = [
        'follow', 'stay', 'flee', 'runaway', 'grind', 'home', 'taxi', 'leave', 'disperse',
        'pull', 'attack', 'revive', 'release',
    ];

    public function __construct(
        private BotCommandContext $context,
        private PlayerbotCommandRunner $runner,
        private QuestLogInterface $questLog,
        private BagsInterface $bags,
        private GameKnowledgeInterface $knowledge,
        private LevelProgressInterface $levels,
        private BotSpeechDispatcher $speech,
        private ErrandBook $errands,
        private Journeys $journeys,
    ) {
    }

    /**
     * @param string $command one word from the playerbot vocabulary
     */
    public function doThis(string $command): string
    {
        $command = strtolower(trim($command));

        // A model will occasionally invent a plausible-sounding verb, and the honest answer is
        // that the character does not know how — not a command the server rejects, and
        // certainly not a silent failure the bot then claims as a success.
        if (!\in_array($command, self::COMPANION_ACTIONS, true)) {
            return \sprintf('You do not know how to "%s". Say so plainly rather than pretending.', $command);
        }

        $bot = $this->context->bot();

        if (\in_array($command, self::ORDERS_THAT_END_A_WALK, true)) {
            $this->journeys->cancel($bot->guid);
        }

        try {
            $this->runner->run($bot->name, $command);

            return \sprintf('Done — you are now doing this: %s.', $command);
        } catch (WorldGatewayException $e) {
            return 'You could not: '.$e->getMessage();
        }
    }

    /**
     * @param string $what part of what the player called it, or empty for whatever is nearest
     */
    public function attackSomething(string $what = ''): string
    {
        $bot = $this->context->bot();

        // Chasing something is not walking somewhere. Let the walk go, or it re-anchors the
        // companion mid-fight.
        $this->journeys->cancel($bot->guid);

        // Wait for the server to actually do it. Reporting the intention instead of the outcome
        // is how a companion ended up announcing "right, going for Khizmu now" and then standing
        // there — the module had written "cannot be attacked" into the outbox and nothing read
        // it. Two seconds is longer than the module's tick and shorter than a conversation.
        $outcome = $this->speech->awaitOutcome($this->speech->attack($bot->guid, $what));

        if ($outcome['done']) {
            return '' === $what
                ? 'You have gone for the nearest enemy and are fighting it now.'
                : \sprintf('You have gone for the nearest "%s" and are fighting it now.', $what);
        }

        if ('' !== $outcome['error']) {
            // Handed back as a fact to speak from, not a line to recite.
            return \sprintf('You could not: %s. Say so plainly rather than claiming you are on it.', $outcome['error']);
        }

        return 'You set off, but cannot tell yet whether you reached anything. Do not claim a fight you are not in.';
    }

    /**
     * Persistent combat, rather than one swing.
     *
     * `attack` is a single order: the bot engages once and stops, which is why "attack until you
     * have seven" got one wolf and silence. `grind` is a *strategy* in the mod rather than a
     * command, so adding it to the non-combat engine is what makes the behaviour continue —
     * `nc +grind` rather than `grind`, a distinction that cost an evening once before.
     */
    /**
     * @param string $who the name the player used
     */
    public function leadTheWay(string $who): string
    {
        $bot = $this->context->bot();
        $found = $this->knowledge->findCreatures($who, 1);

        if ([] === $found) {
            return \sprintf('There is nobody called "%s" in the records, so you cannot lead them anywhere.', $who);
        }

        $target = $found[0];

        if ($target['map'] !== $bot->position->mapId) {
            return \sprintf('%s is on another continent entirely. Say so; you cannot walk there.', $target['name']);
        }

        [$x, $y] = self::shortOf($bot->position->x, $bot->position->y, (float) $target['x'], (float) $target['y']);

        $bearing = Bearing::from($bot->position, new Position((float) $target['x'], (float) $target['y'], 0.0, $target['map']));
        $away = hypot($x - $bot->position->x, $y - $bot->position->y);

        // Past this, a companion cannot be walked anywhere, and the honest answer is to say so.
        //
        // `Playerbots.ReactDistance` (150 yards as shipped) is a hard leash on every "go there"
        // mechanism the mod has: `ReturnToStayPositionAction::isPossible()` throws away a stay
        // anchor beyond it, and `GoAction`'s coordinate form refuses outright with "It is too
        // far away". Six rewrites went into trying to get past that, and the logs were
        // unambiguous in the end — under the leash a companion arrives every time, over it the
        // distance never changes by a single yard.
        //
        // Claiming to be walking and then standing still is worse than refusing. Directions a
        // player can act on are worth more than a promise that is not kept.
        if ($away > self::LEAD_YARDS) {
            return \sprintf(
                '%s is too far for you to walk them to — %s. Say so plainly, give them the '
                .'direction and roughly how far, and offer to follow them there instead.',
                $target['name'],
                null !== $bearing ? $bearing->describe() : \sprintf('about %d paces off', (int) round($away)),
            );
        }

        $journey = new Journey(
            botGuid: $bot->guid,
            destination: new Position($x, $y, $bot->position->z, $bot->position->mapId),
            towards: $target['name'],
            startedAt: microtime(true),
        );

        // The first leg, which {@see Journeys} then drags along the route.
        $leg = $journey->nextLeg($bot->position);

        try {
            // Five attempts at this failed by trying to out-issue the bot's own AI with
            // one-shot orders. The logs finally showed why, and the fix is to stop competing
            // with the engine and drive it instead.
            //
            // The real limit was never the mechanism, it was the range. Every one of those
            // rewrites would have worked at a hundred yards and frozen at three hundred:
            // `ReturnToStayPositionAction::isPossible()` discards any anchor further off than
            // `Playerbots.ReactDistance` (150 yards as shipped) and re-anchors the bot where
            // it stands, and `GoAction`'s coordinate form refuses the same distance outright
            // with "It is too far away". The companion's own estimates were the giveaway:
            // "about a hundred paces" always arrived, "about 303 paces east" never did.
            //
            // So the walk is handed to `StayStrategy`, whose "return to stay position" trigger
            // runs every tick and holds the bot when it arrives — and {@see Journeys} drags
            // that anchor along the route a leg at a time, so it is never out of range.
            //
            // Two things are deliberately not used. `go travel <npc>` whispers "Clearing
            // travel target" and returns *success* when the travel system does not recognise
            // the name, which it does not for most NPCs, so nothing ever threw and no fallback
            // ran. Repeated `go x;y;z` orders are rejected by `IsDuplicateMove` when they name
            // the same point, so the nudges that looked like effort were no-ops.
            //
            // `follow` first, because it is the mod's own full reset: `-stay` on both engines
            // and both recorded positions cleared. Then stop following, then move the anchor,
            // then switch the strategy on. `position stay` is used rather than the `stay`
            // command, which would overwrite the anchor with where the bot is standing now.
            $this->runner->run($bot->name, 'follow');
            $this->runner->run($bot->name, 'nc -follow');
            $this->runner->anchorAt($bot->name, $leg->x, $leg->y, $leg->z);
            $this->runner->run($bot->name, 'nc +stay');
        } catch (WorldGatewayException $e) {
            return 'You could not set off: '.$e->getMessage();
        }

        $this->journeys->start($journey);

        return \sprintf(
            'You are walking to %s%s, and will wait there. Tell them to follow you.',
            $target['name'],
            null !== $bearing ? ', '.$bearing->describe() : '',
        );
    }

    /**
     * A point a few paces short of the target, along the line from here to there.
     *
     * Walking *onto* an NPC leaves a companion standing inside them, which looks wrong and can
     * block the player from clicking the very person they asked to be taken to.
     *
     * @return array{0: float, 1: float}
     */
    private static function shortOf(float $fromX, float $fromY, float $toX, float $toY, float $paces = 5.0): array
    {
        $distance = hypot($toX - $fromX, $toY - $fromY);

        if ($distance <= $paces) {
            return [$fromX, $fromY];
        }

        $keep = ($distance - $paces) / $distance;

        return [$fromX + ($toX - $fromX) * $keep, $fromY + ($toY - $fromY) * $keep];
    }

    /**
     * @param string $what      what the player called it, or empty for whatever is nearest
     * @param string $forQuest  the exact quest title this serves, when one was named
     * @param bool   $forPlayer true when it is the player's copy of the quest being finished
     */
    public function keepFighting(string $what = '', string $forQuest = '', bool $forPlayer = false): string
    {
        $bot = $this->context->bot();
        $owner = $forPlayer ? $this->context->speaker() : $bot;

        // An errand owns the companion's feet for as long as it runs, so any walk ends here.
        $this->journeys->cancel($bot->guid);

        // The mod's own `grind` strategy would do something similar and wander off doing it:
        // it finds its own targets, anywhere. An errand keeps the companion on what the player
        // pointed at, where the player is, and knows what finishing looks like.
        [$questId, $questTitle] = $this->questNamed($owner->guid, $forQuest);

        $this->errands->start(new Errand(
            botGuid: $bot->guid,
            what: trim($what),
            questId: $questId,
            questTitle: $questTitle,
            questOwnerGuid: $owner->guid,
        ));

        // One swing now so something visibly happens; the runner takes it from there.
        $outcome = $this->speech->awaitOutcome($this->speech->attack($bot->guid, trim($what)));

        if (!$outcome['done'] && '' !== $outcome['error']) {
            $this->errands->finish($bot->guid);

            return \sprintf('You could not start: %s. Say so plainly.', $outcome['error']);
        }

        if (null === $questId) {
            return 'You are working through them now and will keep going until told to stop.';
        }

        return $forPlayer
            ? \sprintf('You are working through them and will stop when their "%s" is done.', $questTitle)
            : \sprintf('You are working through them and will stop when "%s" is done.', $questTitle);
    }

    public function stopFighting(): string
    {
        $bot = $this->context->bot();
        $this->errands->finish($bot->guid);
        $this->journeys->cancel($bot->guid);

        try {
            $this->runner->run($bot->name, 'follow');

            return 'You have stopped and are following the player again.';
        } catch (WorldGatewayException $e) {
            return 'You could not: '.$e->getMessage();
        }
    }

    /**
     * Match a quest title the model offered against what the owner is actually carrying.
     *
     * A title it invented must not become a stopping condition that can never be met — and the
     * owner matters as much as the title: checking the companion's log for the player's quest
     * is how "help me collect mine" became a promise that expired on the next tick.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function questNamed(int $ownerGuid, string $title): array
    {
        $title = trim($title);

        if ('' === $title) {
            return [null, null];
        }

        foreach ($this->questLog->activeFor($ownerGuid) as $quest) {
            if (0 === strcasecmp($quest['title'], $title)) {
                return [$quest['id'], $quest['title']];
            }
        }

        return [null, null];
    }

    /**
     * @param string $item the name of a piece of gear in the companion's own bags
     */
    public function wearThis(string $item): string
    {
        $item = trim($item);

        // A name is a short line of ordinary words. This no longer guards the console — the
        // command is built from an integer below — but it keeps a search string out of a LIKE.
        if (1 !== preg_match("/^[A-Za-z0-9 '\\-]{2,40}$/", $item)) {
            return \sprintf('"%s" is not the name of a thing you could be wearing.', $item);
        }

        $bot = $this->context->bot();

        // The name has to become an entry id before anything can be sent: the mod's `equip`
        // understands item links and nothing else. Looking it up in this bot's own bags buys
        // the honest refusal below as well — previously every request was answered "you have
        // put it on, if it was in your bags", whether or not it was, and it never was.
        $found = $this->bags->wearableNamed($bot->guid, $item);

        if (null === $found) {
            return \sprintf(
                'You are not carrying anything called "%s" that you could wear. Say so, and ask '
                .'them to hand it over if they are still holding it.',
                $item,
            );
        }

        try {
            $this->runner->equipItem($bot->name, $found['entry']);

            return \sprintf('You have put on your %s.', $found['name']);
        } catch (WorldGatewayException $e) {
            return 'You could not: '.$e->getMessage();
        }
    }

    /**
     * @param string $whose "mine" for the companion's own, "theirs" for the player's
     */
    public function checkExperience(string $whose = 'mine'): string
    {
        $theirs = str_starts_with(strtolower(trim($whose)), 't');
        $who = $theirs ? $this->context->speaker() : $this->context->bot();
        $progress = $this->levels->forCharacter($who->guid);

        if (null === $progress) {
            return $theirs
                ? \sprintf('You cannot tell how far along %s is.', $who->name)
                : 'You cannot tell how far along you are.';
        }

        // Rounded, and never the raw number alone. "2,084 more" is a database row read aloud;
        // "about three quarters of the way" is what one traveller tells another.
        return \sprintf(
            '%s level %d, about %d%% of the way to the next, with %s experience still to earn.',
            $theirs ? $who->name.' is' : 'You are',
            $progress['level'],
            $progress['percent'],
            number_format($progress['remaining']),
        );
    }

    public function checkMyQuestLog(): string
    {
        $bot = $this->context->bot();

        return $this->describeLog(
            $this->questLog->activeFor($bot->guid),
            'You are not on any quests at the moment.',
            'You are working on',
        );
    }

    public function checkTheirQuestLog(): string
    {
        $player = $this->context->speaker();

        return $this->describeLog(
            $this->questLog->activeFor($player->guid),
            \sprintf('%s is not on any quests at the moment.', $player->name),
            \sprintf('%s is working on', $player->name),
        );
    }

    /**
     * Render a log as a person would describe it — what they are on, and what is left.
     *
     * Progress is given as "two of six" rather than a bare count, because a companion saying
     * "four more" is the whole reason for reading this.
     *
     * @param array<int, array{id: int, title: string, level: int, complete: bool, needs: array<int, array{what: string, have: int, need: int}>}> $quests
     */
    private function describeLog(array $quests, string $whenEmpty, string $lead): string
    {
        if ([] === $quests) {
            return $whenEmpty;
        }

        $lines = [];

        foreach ($quests as $quest) {
            if ($quest['complete']) {
                // Where to take it is the only thing left worth knowing, and guessing the giver
                // is wrong for about half the quests in the game.
                $ends = $this->knowledge->questGivers($quest['id'])['ends'];

                $lines[] = [] === $ends
                    ? \sprintf('"%s" (level %d), which is done and ready to hand in', $quest['title'], $quest['level'])
                    : \sprintf('"%s" (level %d), done and waiting to be handed to %s', $quest['title'], $quest['level'], $ends[0]);

                continue;
            }

            $outstanding = [];

            foreach ($quest['needs'] as $need) {
                if ($need['have'] >= $need['need']) {
                    continue;
                }

                $outstanding[] = \sprintf('%s %d of %d', $need['what'], $need['have'], $need['need']);
            }

            $lines[] = [] === $outstanding
                ? \sprintf('"%s" (level %d)', $quest['title'], $quest['level'])
                : \sprintf('"%s" (level %d), with %s', $quest['title'], $quest['level'], implode(' and ', $outstanding));
        }

        return $lead.': '.implode('; ', $lines).'.';
    }

    public function checkMyBags(): string
    {
        try {
            $answer = trim($this->runner->run($this->context->bot()->name, 'items'));

            return '' !== $answer ? $answer : 'Your bags are empty.';
        } catch (WorldGatewayException $e) {
            return 'You could not look through your bags: '.$e->getMessage();
        }
    }
}
