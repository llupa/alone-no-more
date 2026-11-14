<?php

declare(strict_types=1);

namespace App\Roleplay\Milestones;

use App\Ai\AgentText;
use App\Roleplay\AgentProvider;
use App\Roleplay\Memory\ConversationStoreInterface;
use App\Roleplay\Party\PartyVoice;
use App\Roleplay\Personality\PersonalityStore;
use App\Roleplay\Personality\Warmth;
use App\Roleplay\ReplySanitiser;
use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\Model\ChatChannel;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Someone says something when you ding.
 *
 * The moment is the point: a level is the one thing in this game everybody still announces, and
 * a world where nobody notices is exactly the loneliness this project is named after.
 *
 * Who speaks matters as much as what they say. The party's voice says it if you are in a party,
 * because that is the companion you have been talking to; otherwise whoever is standing closest,
 * because a stranger's nod is still better than silence. And it is said in party chat when there
 * is a party, so it reads as directed at you rather than announced to the zone.
 */
final readonly class MilestoneHerald
{
    public function __construct(
        private AgentProvider $agents,
        private WorldGatewayInterface $world,
        private GroupRepositoryInterface $groups,
        private PartyVoice $voice,
        private PersonalityStore $personalities,
        private ConversationStoreInterface $memory,
        private BotSpeechDispatcher $speech,
        private ReplySanitiser $sanitiser,
        /** How close a stranger has to be to notice, when there is no party. */
        #[Autowire(env: 'float:ANM_BANTER_RADIUS')]
        private float $earshot = 25.0,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return string|null what was said, for the listener to show
     */
    public function announce(Milestone $milestone): ?string
    {
        $player = $this->world->findActorByGuid($milestone->playerGuid);

        if (null === $player || !$player->isOnline) {
            return null;
        }

        [$speaker, $channel] = $this->whoSpeaks($player);

        if (null === $speaker) {
            return null;
        }

        $line = $this->compose($speaker, $player, $milestone);

        if (null === $line) {
            return null;
        }

        $this->speech->say($speaker->guid, $line, $channel);

        return \sprintf('%s: %s', $speaker->name, $line);
    }

    /**
     * The party's voice if there is one, otherwise whoever is close enough to notice.
     *
     * @return array{0: Actor|null, 1: ChatChannel}
     */
    private function whoSpeaks(Actor $player): array
    {
        $memberGuids = $this->groups->memberGuidsWith($player->guid);

        if ([] !== $memberGuids) {
            $members = array_values(array_filter(array_map(
                $this->world->findActorByGuid(...),
                $memberGuids,
            ), static fn (?Actor $a): bool => null !== $a && $a->isBot && $a->isOnline));

            if ([] !== $members) {
                // Party chat, not /say: a word about your levelling should read as directed at
                // you, not announced to the zone.
                return [$this->voice->speakerFor('party:'.$player->guid, $members), ChatChannel::Party];
            }
        }

        $nearby = array_values(array_filter(
            $this->world->actorsNear($player->position, $this->earshot),
            static fn (Actor $a): bool => $a->isBot && $a->isOnline,
        ));

        return [[] === $nearby ? null : $nearby[array_rand($nearby)], ChatChannel::Say];
    }

    private function compose(Actor $speaker, Actor $player, Milestone $milestone): ?string
    {
        $personality = $this->personalities->of($speaker);
        $known = $this->memory->acquaintance($speaker->guid, $player->guid);
        $exchanges = $known['exchanges'] ?? 0;

        // Warmth is earned, not assumed. A companion you have barely met being fond of you is
        // uncomfortable rather than charming, and a cold one gushing is simply out of character
        // — so the warmer register needs both a warm personality and a shared history.
        $fond = $exchanges >= 10
            && \in_array($personality->warmth, [Warmth::Warm, Warmth::Amiable], true);

        $prompt = \sprintf(
            'You are %s, a %s %s %s. %s, who you are travelling with, has %s. '
            .'Say one short line to them about it, in your own manner. %s '
            .'Do not use their name more than once. Never mention levels you have not reached yourself.',
            $speaker->name,
            $personality->adjectives(),
            $speaker->race->label(),
            strtolower($speaker->class->label()),
            $player->name,
            $milestone->describe(),
            $fond
                ? 'You have travelled together a long while and are fond of them; let that show a little, warmly and without flattery.'
                : 'Be genuine rather than effusive.',
        );

        try {
            $raw = AgentText::of($this->agents->banterAgent()->call($prompt)->getContent());
        } catch (\Throwable $e) {
            // A missed congratulation is not worth interrupting the listener for.
            $this->logger->warning('Could not congratulate {player}: {why}', [
                'player' => $player->name,
                'why' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->sanitiser->sanitise($raw);
    }
}
