<?php

declare(strict_types=1);

namespace App\Roleplay;

use App\Ai\AgentText;
use App\Knowledge\Dbc\AreaNames;
use App\Roleplay\Context\PromptBuilder;
use App\Roleplay\Context\RoleplayContext;
use App\Roleplay\Memory\ConversationBags;
use App\Roleplay\Memory\ConversationStoreInterface;
use App\Roleplay\Memory\SceneKey;
use App\Roleplay\Party\PartyVoice;
use App\Roleplay\Selector\BotSelectorInterface;
use App\Roleplay\Tool\BotCommandContext;
use App\World\Chat\BotSpeechDispatcher;
use App\World\GroupRepositoryInterface;
use App\World\Model\Actor;
use App\World\Model\ChatEvent;
use App\World\WorldGatewayInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Message;

/**
 * Turns one line of player chat into one bot's reply, in character.
 *
 * The flow is deliberately linear and interruptible: find who spoke, decide who answers, build
 * the prompt, ask the model, queue the line, remember it. Any step may decide that nobody
 * answers, and that is a normal outcome — a world where every utterance gets a response is as
 * unconvincing as one where none does.
 */
final readonly class RoleplayResponder
{
    /** WoW drops any chat line longer than this outright. */
    private const MAX_CHAT_LENGTH = 255;

    public function __construct(
        private WorldGatewayInterface $world,
        private GroupRepositoryInterface $groups,
        private BotSelectorInterface $selector,
        private PromptBuilder $prompts,
        private AgentProvider $agents,
        private BotSpeechDispatcher $speech,
        private ConversationStoreInterface $memory,
        private ConversationBags $bags,
        private PartyVoice $voice,
        private AreaNames $areaNames,
        private BotCommandContext $commandContext,
        private ReplySanitiser $sanitiser,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return Reply|null null when nobody answered, with the reason logged
     */
    public function respondTo(ChatEvent $event): ?Reply
    {
        if (!$event->channel->invitesReply() || $event->senderIsBot) {
            return null;
        }

        // An order to a bot is not conversation. mod-playerbots already routes these to the bot
        // and acts on them; answering as well means talking over the thing the player actually
        // asked for — a whispered "grind" got a reply about chanting instead of a bot grinding.
        // Combat orders are ours: see PlayerbotVocabulary::OURS for why deferring them was
        // worse than useless.
        if (PlayerbotVocabulary::isCommand($event->message) && !PlayerbotVocabulary::isOursToHandle($event->message)) {
            $this->logger->debug('Leaving "{message}" to the bot command handler', [
                'message' => $event->message,
            ]);

            return null;
        }

        $speaker = $this->world->findActor($event->senderName);

        if (null === $speaker) {
            $this->logger->debug('No actor for speaker {name}', ['name' => $event->senderName]);

            return null;
        }

        $bot = $this->selector->select($event, $speaker, $this->candidatesFor($speaker, $event));

        if (null === $bot) {
            $this->logger->debug('Nobody in earshot to answer {name}', ['name' => $speaker->name]);

            return null;
        }

        $context = new RoleplayContext(
            bot: $bot,
            speaker: $speaker,
            event: $event,
            zoneName: $this->zoneNameFor($bot),
        );

        // Party and /say lines belong to a conversation several people can hear. The scene key
        // identifies it, and the memory provider reads it to tell this bot what its companions
        // said while it was silent.
        $sceneKey = SceneKey::for($event, $speaker);

        $prompt = $this->prompts->build($context);

        // The system prompt carries who the bot is, where it stands and how it should speak —
        // all stable. The conversation itself arrives as real turns, the bot's own past replies
        // among them as assistant messages.
        // withSystemMessage() unshifts and replaces any existing one, so a hot bag re-prompted
        // on every turn cannot accumulate stale system messages.
        // A party is one conversation, so its turns are held once and the chosen voice speaks
        // them. Whoever has just taken the floor starts clean: the stored turns are the previous
        // speaker's own words, and a successor reading them as its own is the mistake that once
        // had a priest talking about the shield she was keeping up. The history is not lost —
        // scene memory hands it over as something overheard, which is what it was.
        if (null !== $sceneKey) {
            $handedOverFrom = $this->voice->takeOver($sceneKey, $bot);

            if (null !== $handedOverFrom) {
                $this->bags->resetParty($sceneKey);
                $this->logger->info('{bot} now speaks for the party, taking over from {from}', [
                    'bot' => $bot->name,
                    'from' => $handedOverFrom,
                ]);
            }
        }

        $messages = (null !== $sceneKey
                ? $this->bags->forParty($sceneKey)
                : $this->bags->forPair($bot->guid, $speaker->guid, $bot->name))
            ->withSystemMessage(Message::forSystem($prompt))
            ->with(Message::ofUser($event->message));

        // Tools act as whichever bot is speaking. The model only ever says "follow"; which bot
        // follows is decided here, not by asking the model to name it correctly.
        $this->commandContext->enter($bot, $speaker, $sceneKey);

        try {
            // A string when the model answered outright, an array of result objects once it
            // reasoned first. Both arrive from the same call.
            $raw = AgentText::of($this->agents->agent()->call($messages)->getContent());
        } finally {
            $this->commandContext->leave();
        }

        $reply = $this->sanitiser->sanitise($raw);

        if (null === $reply) {
            // Refusing to speak is the correct outcome here: the alternative is a bot reciting
            // JSON at a player.
            $this->logger->warning('Discarded unusable reply from {bot}: {raw}', [
                'bot' => $bot->name,
                'raw' => mb_substr($raw, 0, 200),
            ]);

            return null;
        }

        $outboxId = $this->speech->say(
            $bot->guid,
            $reply,
            $event->channel,
            'whisper' === $event->channel->replyAction() ? $speaker->guid : 0,
        );

        // Hot memory for the next reply; SQLite so it survives a restart and feeds affinity.
        if (null !== $sceneKey) {
            $this->bags->recordForParty($sceneKey, $event->message, $reply);
        } else {
            $this->bags->record($bot->guid, $speaker->guid, $event->message, $reply);
        }

        $this->memory->rememberExchange($event, $bot->guid, $reply, $bot->name);

        // The scene is recorded after the reply, never before: this turn's question already
        // reaches the bot as a user message, and the bot's own answer as an assistant one.
        // Writing either beforehand would hand the speaker its own words back as memory.
        if (null !== $sceneKey) {
            $this->memory->rememberHeard($sceneKey, $speaker->guid, $speaker->name, $event->message);
            $this->memory->rememberHeard($sceneKey, $bot->guid, $bot->name, $reply);
        }

        return new Reply($bot, $reply, $outboxId, $prompt);
    }

    /**
     * Who could plausibly answer.
     *
     * Party and raid chat is answered by the group, wherever its members happen to be standing —
     * looking them up by zone would silently drop a member who has run ahead. Proximity channels
     * look at who is physically close. The selector then applies its own rules on top.
     *
     * @return Actor[]
     */
    private function candidatesFor(Actor $speaker, ChatEvent $event): array
    {
        if ($event->isDirected()) {
            $target = $this->world->findActorByGuid($event->receiverGuid);

            return null !== $target ? [$target] : [];
        }

        if (!$event->channel->isProximityBased()) {
            return array_values(array_filter(array_map(
                $this->world->findActorByGuid(...),
                $this->groups->memberGuidsWith($speaker->guid),
            )));
        }

        return $this->world->actorsNear($speaker->position, 300.0);
    }

    /**
     * The zone's real name. `characters.zone` is a number and the names live in client data, so
     * this used to cost a round trip to the playerbots command server on every single reply.
     * AreaTable.dbc answers it locally, and answers a question the command server could not:
     * the *subzone*, so a bot can say "Shadowglen" rather than only "Teldrassil".
     */
    private function zoneNameFor(Actor $bot): ?string
    {
        return $this->areaNames->name($bot->zoneId);
    }
}
