/*
 * mod-anm-bridge — the one piece of "Alone No More" that has to live inside the worldserver.
 *
 * Everything else in this project talks to AzerothCore from PHP over SOAP and MySQL. Two things
 * cannot be done that way, and this module does exactly those two and nothing more:
 *
 *   1. Capturing chat. The core's `chat.system` logger only records channel join/leave/ownership
 *      events, never message bodies, so there is no log to tail. We hook PlayerScript's
 *      OnPlayerCanUseChat family, which fires for say/yell/whisper/party/guild/channel with the
 *      full context, and append to `anm_chat_event`.
 *
 *   2. Making a bot speak arbitrary text. No core or playerbot command does this. We drain an
 *      `anm_outbox` table that the PHP side writes to.
 *
 * Both tables live in the characters database so the module needs no connection pool of its own.
 */

#ifndef MOD_ANM_BRIDGE_H
#define MOD_ANM_BRIDGE_H

#include "Common.h"
#include "ObjectGuid.h"

#include <string>

class Player;

namespace AnmBridge
{
    // Creates anm_chat_event / anm_outbox if absent. Safe to call on every startup.
    void EnsureSchema();

    void RecordChat(Player* sender, uint32 type, uint32 lang, std::string const& msg,
                    ObjectGuid receiverGuid, std::string const& channel);

    // Something that happened *to* a player: a ding, an achievement. Recorded as it happens,
    // which polling could not do — two levels in one tick would look like one, and an
    // achievement's name is never written to the characters database at all.
    void RecordMilestone(Player* player, std::string const& kind, std::string const& detail, uint32 number);

    // Drains anm_outbox. Called from the world update on a fixed interval.
    void DrainOutbox();

    // Walks the bots that have somewhere to be, one short hop at a time. Called from the world
    // update, more often than the outbox is drained, because this is movement.
    //
    // Leading a player to an NPC is the third thing that cannot be done from outside the
    // worldserver, and it took six attempts through mod-playerbots' chat commands to accept
    // that. Every route out there is leashed to `Playerbots.ReactDistance` (150 yards):
    // `ReturnToStayPositionAction::isPossible()` throws away a stay anchor beyond it, and
    // `GoAction`'s coordinate form refuses outright. Inside, there is no leash — only
    // pathfinding, which is handled by walking in hops.
    void DriveLeads();
}

#endif
