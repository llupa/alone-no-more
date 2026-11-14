<?php

declare(strict_types=1);

namespace App\Roleplay\Tool;

use App\Knowledge\Bearing;
use App\Knowledge\GameKnowledgeInterface;
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
        where he is". Pass the name they used. You will stop a few paces short of them. For
        telling someone where something is without walking there, consult the archives instead.
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
    ];

    public function __construct(
        private BotCommandContext $context,
        private PlayerbotCommandRunner $runner,
        private QuestLogInterface $questLog,
        private GameKnowledgeInterface $knowledge,
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

        try {
            $this->runner->run($this->context->bot()->name, $command);

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

        try {
            // Z is left as the bot's own: the server pathfinds, and guessing a height from a
            // spawn average is how you send someone into the ground.
            $this->runner->goTo($bot->name, $x, $y, $bot->position->z);
        } catch (WorldGatewayException $e) {
            return 'You could not set off: '.$e->getMessage();
        }

        // Watched until it arrives, then told to wait. `go` is one movement order, so a
        // companion that is also following would drift back the moment the walk ended.
        $this->journeys->start(new Journey(
            botGuid: $bot->guid,
            destination: new Position($x, $y, $bot->position->z, $bot->position->mapId),
            towards: $target['name'],
            startedAt: microtime(true),
        ));

        $bearing = Bearing::from($bot->position, new Position((float) $target['x'], (float) $target['y'], 0.0, $target['map']));

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
