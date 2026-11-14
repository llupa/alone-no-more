<?php

declare(strict_types=1);

namespace App\Herding;

use App\Config\Settings;
use App\Knowledge\Dbc\AreaNames;
use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\Model\Faction;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps a believable crowd around the player.
 *
 * The mod's own answer to an empty world is more bots: spin up fifteen hundred and enough of
 * them happen to be near you. Measured, that puts 33 in your zone — 2.2% of the population — and
 * leaves the other 97% somewhere you are not.
 *
 * This takes the opposite approach. A large pool of characters stays dormant, and we choose
 * which hundred are awake based on where you actually are. A dormant bot can be moved freely
 * because nobody can see an offline character arrive; it simply exists where we put it when it
 * logs in. No teleport to witness, no travel AI to fight.
 */
#[AsAlias(BotStagerInterface::class)]
final readonly class BotStager implements BotStagerInterface
{
    public function __construct(
        private BotRoster $roster,
        private PlacementPoints $places,
        private BotSpeechDispatcher $dispatcher,
        private GroupRepositoryInterface $groups,
        private AreaNames $areas,
        private WorldGatewayInterface $world,
        /**
         * How big the crowd should be and how close in level, read fresh each time: both are
         * decided while playing — "make it busier", "bring people I can group with" — so they
         * live in Settings rather than in .env, and change without a restart.
         */
        private Settings $settings,
        #[Autowire(env: 'float:ANM_HERD_RADIUS')]
        private float $radius = 600.0,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Bring the crowd around this player up to strength.
     */
    public function stage(Actor $player, ?int $target = null): StagingResult
    {
        $target ??= $this->settings->crowdSize();
        $present = $this->roster->onlineBotsWithin($player->position, $this->radius);
        $wanted = $target - $present;

        if ($wanted <= 0) {
            return new StagingResult($present, $target, [], []);
        }

        $places = $this->places->around($player->position, $this->radius, $wanted * 2);

        if ([] === $places) {
            // Open water, an unfinished area, or somewhere with no creature spawns at all.
            return new StagingResult($present, $target, [], [], 'nowhere to stand nearby');
        }

        // Who belongs here. Teldrassil is the Night Elf homeland; a crowd there that is half
        // Horde reads as wrong instantly, long before anyone notices the dialogue. Contested
        // ground splits evenly, which is the whole character of a place like Ashenvale.
        $control = $this->areas->controlOf($player->zoneId);
        $majorityFaction = $control->heldBy();

        $candidates = $this->pickWithFactionMix($player, $wanted, $control->majorityShare(), $majorityFaction);

        if ([] === $candidates) {
            return new StagingResult($present, $target, [], [], \sprintf(
                'no dormant bots within %d levels of %d',
                $this->settings->levelTolerance(),
                $player->level,
            ));
        }

        $broughtIn = [];

        foreach ($candidates as $candidate) {
            if (\count($broughtIn) >= $wanted) {
                break;
            }

            $spot = $places[array_rand($places)];

            // A bot that woke up by itself between the query and now must be left alone:
            // overwriting a live character's row would teleport someone in full view.
            if (!$this->roster->placeDormant($candidate['guid'], $spot, $player->zoneId)) {
                continue;
            }

            $this->dispatcher->login($candidate['guid']);
            $broughtIn[] = ['name' => $candidate['name'], 'level' => $candidate['level']];
        }

        $this->logger->info('Staged {count} bots around {player}', [
            'count' => \count($broughtIn),
            'player' => $player->name,
        ]);

        return new StagingResult(
            $present,
            $target,
            $broughtIn,
            [],
            \count($broughtIn) < $wanted ? 'ran out of suitable dormant bots' : null,
        );
    }

    /**
     * Everyone nearby, so stragglers can be re-ordered.
     *
     * @return string[]
     */
    public function namesNear(Actor $player): array
    {
        // Never the player's own party. "new rpg" sends a bot off on errands of its own, which
        // is exactly right for the crowd and exactly wrong for a companion that was asked to
        // follow — ordering your party to go roaming is how they stopped following you.
        return $this->roster->onlineBotNamesWithin(
            $player->position,
            $this->radius,
            $this->groups->memberGuidsWith($player->guid),
        );
    }

    /**
     * Give the bots we just woke something to do.
     *
     * A bot logs in idle. Normally the mod's own tick hands it a destination, but that tick is
     * what we switched off to stop it fighting us over who is online — and the cost, which only
     * showed up in game, was fifty people standing perfectly still in a clearing. The AI was
     * running fine; nobody had told it where to go.
     *
     * `grind` sets a bot roaming and fighting around where it stands, which is exactly what we
     * want: it was placed deliberately, so it should stay in that area rather than be sent off
     * to a spot of the mod's choosing.
     *
     * Deferred until after the logins are queued, because the bot has to be in the world before
     * it can be given an order, and the bridge drains the outbox in sequence.
     *
     * @param string[] $names
     */
    public function rouse(array $names): void
    {
        foreach ($names as $name) {
            try {
                // Two different things, both needed.
                //
                // "new rpg" is a persistent strategy: it is what keeps a bot choosing errands
                // for itself indefinitely. Without it a bot has no standing reason to do
                // anything and simply stands where it was placed.
                //
                // "grind" is a one-shot action that sends it off now rather than whenever the
                // strategy next gets round to it. On its own it wears off and the bot stops,
                // which is why ordering a crowd to grind left them all standing a minute later.
                $this->world->execute(\sprintf('playerbots exec %s nc +new rpg', $name));
                $this->world->execute(\sprintf('playerbots exec %s grind', $name));
            } catch (\Throwable $e) {
                // A bot that has not finished logging in yet simply stays idle until the next
                // pass rouses it. Not worth failing a staging run over.
                $this->logger->debug('Could not rouse {bot}: {why}', ['bot' => $name, 'why' => $e->getMessage()]);
            }
        }
    }

    /**
     * Draw candidates so the crowd belongs to the zone it stands in.
     *
     * Never entirely one-sided: a hostile scout or two in enemy territory is a normal sight in
     * this game, and a zone with literally nobody from the other side feels sterile rather than
     * safe.
     *
     * @return array<int, array{guid: int, name: string, level: int, race: int}>
     */
    private function pickWithFactionMix(Actor $player, int $wanted, float $majorityShare, ?Faction $majority): array
    {
        if (null === $majority) {
            // Contested: no side to favour, take whoever fits the level band.
            return $this->roster->dormantPeersOf($player, $this->settings->levelTolerance(), $wanted * 2);
        }

        $majorityWanted = (int) round($wanted * $majorityShare);

        $picked = $this->roster->dormantPeersOf($player, $this->settings->levelTolerance(), $majorityWanted * 2, $majority);
        $picked = \array_slice($picked, 0, $majorityWanted);

        $minority = $this->roster->dormantPeersOf(
            $player,
            $this->settings->levelTolerance(),
            max(1, ($wanted - \count($picked)) * 2),
            $majority->opposing(),
        );

        return array_merge($picked, \array_slice($minority, 0, max(0, $wanted - \count($picked))));
    }

    /**
     * Send away bots the player has left behind, freeing them to be staged somewhere else.
     *
     * Party members are never sent away, nor anyone the player has been talking to — logging out
     * a bot mid-conversation destroys the exchange and reads as the world glitching.
     */
    public function dismissDistant(Actor $player, float $beyondYards, array $alsoProtect = []): array
    {
        $protected = array_merge(
            [$player->guid],
            $this->groups->memberGuidsWith($player->guid),
            $alsoProtect,
        );

        $leaving = $this->roster->onlineBotsBeyond($player->position, $beyondYards, $protected, 40);

        foreach ($leaving as $bot) {
            $this->dispatcher->logout($bot['guid']);
        }

        if ([] !== $leaving) {
            $this->logger->info('Sent {count} distant bots away', ['count' => \count($leaving)]);
        }

        return array_column($leaving, 'name');
    }
}
