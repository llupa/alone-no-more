#include "AnmBridge.h"

#include "Cell.h"
#include "CellImpl.h"
#include "Channel.h"
#include "Chat.h"
#include "DatabaseEnv.h"
#include "GridNotifiers.h"
#include "GridNotifiersImpl.h"
#include "Group.h"
#include "Guild.h"
#include "GuildMgr.h"
#include "Log.h"
#include "ObjectAccessor.h"
#include "MotionMaster.h"
#include "PlayerbotAI.h"
#include "PlayerbotMgr.h"
#include "Playerbots.h"
#include "RandomPlayerbotMgr.h"
#include "Player.h"
#include "ScriptMgr.h"
#include "World.h"

#include <algorithm>
#include <cctype>
#include <list>
#include <sstream>

namespace
{
    // Keep chat lines well under the client's limit; an LLM will happily produce a paragraph.
    constexpr std::size_t MAX_CHAT_LEN = 255;

    std::string Escape(std::string value)
    {
        CharacterDatabase.EscapeString(value);
        return value;
    }

    std::string Lowered(std::string value)
    {
        std::transform(value.begin(), value.end(), value.begin(),
                       [](unsigned char c) { return static_cast<char>(std::tolower(c)); });
        return value;
    }

    // The nearest thing the bot may lawfully hit, optionally filtered by what the player called
    // it. "kill at least 4 kobolds" names a kind, not a creature, so the match is a substring of
    // the name rather than an exact one — "kobold" finds "Kobold Vermin" and "Kobold Worker".
    //
    // This exists because mod-playerbots can only attack whatever the *master* has selected. A
    // player who says "attack the kobolds" has selected nothing, so the bot sat there while the
    // model cheerfully answered "right, I'm on it" — announcing an action it had no way to take.
    // "diseased wolf" has to find "Diseased Young Wolf". A plain substring does not, because the
    // real name has a word in the middle — so the player was punished for being more specific
    // than "wolf", which is exactly backwards.
    bool NameMatches(std::string const& name, std::string const& needle)
    {
        std::string const haystack = Lowered(name);
        std::istringstream words(needle);
        std::string word;

        while (words >> word)
        {
            if (haystack.find(word) == std::string::npos)
                return false;
        }

        return true;
    }

    Unit* FindAttackable(Player* bot, float radius, std::string const& wanted, std::string& seenButUnattackable)
    {
        std::list<Unit*> candidates;
        Acore::AnyUnfriendlyUnitInObjectRangeCheck check(bot, bot, radius);
        Acore::UnitListSearcher<Acore::AnyUnfriendlyUnitInObjectRangeCheck> searcher(bot, candidates, check);
        Cell::VisitObjects(bot, searcher, radius);

        std::string const needle = Lowered(wanted);

        Unit* best = nullptr;
        float bestDistance = radius + 1.0f;

        for (Unit* candidate : candidates)
        {
            if (!candidate || !candidate->IsAlive())
                continue;

            // Players are left in deliberately. Excluding them made "that troll over there"
            // impossible to act on even when the troll was Horde and perfectly attackable;
            // IsValidAttackTarget already settles faction, PvP flags and sanctuaries, and it
            // settles them more correctly than we could.
            if (!bot->IsValidAttackTarget(candidate))
            {
                // Remember that we saw it, so "she is not flagged for a fight" can be said
                // instead of "there is nobody by that name", which is a different and more
                // confusing thing to hear.
                if (!needle.empty() && NameMatches(candidate->GetName(), needle))
                    seenButUnattackable = candidate->GetName();

                continue;
            }

            if (!needle.empty() && !NameMatches(candidate->GetName(), needle))
                continue;

            float const distance = bot->GetDistance(candidate);
            if (distance < bestDistance)
            {
                best = candidate;
                bestDistance = distance;
            }
        }

        return best;
    }

    std::string Truncate(std::string const& text)
    {
        if (text.size() <= MAX_CHAT_LEN)
            return text;

        return text.substr(0, MAX_CHAT_LEN - 3) + "...";
    }
}

void AnmBridge::EnsureSchema()
{
    // Created here rather than shipped as module SQL so a fresh clone needs no import step.
    CharacterDatabase.DirectExecute(
        "CREATE TABLE IF NOT EXISTS `anm_chat_event` ("
        "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
        "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        "`sender_guid` INT UNSIGNED NOT NULL,"
        "`sender_name` VARCHAR(12) NOT NULL,"
        "`chat_type` INT UNSIGNED NOT NULL,"
        "`language` INT UNSIGNED NOT NULL,"
        "`receiver_guid` INT UNSIGNED NOT NULL DEFAULT 0,"
        "`channel` VARCHAR(64) NOT NULL DEFAULT '',"
        "`message` TEXT NOT NULL,"
        "`map` INT UNSIGNED NOT NULL DEFAULT 0,"
        "`zone` INT UNSIGNED NOT NULL DEFAULT 0,"
        "`area` INT UNSIGNED NOT NULL DEFAULT 0,"
        "`position_x` FLOAT NOT NULL DEFAULT 0,"
        "`position_y` FLOAT NOT NULL DEFAULT 0,"
        "`position_z` FLOAT NOT NULL DEFAULT 0,"
        "PRIMARY KEY (`id`), KEY `idx_created` (`created_at`), KEY `idx_sender` (`sender_guid`)"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    CharacterDatabase.DirectExecute(
        "CREATE TABLE IF NOT EXISTS `anm_outbox` ("
        "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
        "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        "`bot_guid` INT UNSIGNED NOT NULL,"
        // say | yell | whisper | party | guild | emote
        "`action` VARCHAR(16) NOT NULL,"
        "`target_guid` INT UNSIGNED NOT NULL DEFAULT 0,"
        "`message` TEXT NOT NULL,"
        // pending | done | failed
        "`status` VARCHAR(16) NOT NULL DEFAULT 'pending',"
        "`error` VARCHAR(255) NOT NULL DEFAULT '',"
        "`processed_at` TIMESTAMP NULL DEFAULT NULL,"
        "PRIMARY KEY (`id`), KEY `idx_status` (`status`,`id`)"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    CharacterDatabase.DirectExecute(
        "CREATE TABLE IF NOT EXISTS `anm_milestone` ("
        "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
        "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        "`player_guid` INT UNSIGNED NOT NULL,"
        "`player_name` VARCHAR(12) NOT NULL,"
        "`kind` VARCHAR(16) NOT NULL,"
        "`detail` VARCHAR(128) NOT NULL DEFAULT '',"
        "`number` INT UNSIGNED NOT NULL DEFAULT 0,"
        "PRIMARY KEY (`id`), KEY `idx_created` (`created_at`)"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    LOG_INFO("module.anm", "[AnmBridge] schema ready (anm_chat_event, anm_outbox, anm_milestone)");
}

void AnmBridge::RecordMilestone(Player* player, std::string const& kind, std::string const& detail, uint32 number)
{
    // Bots level constantly and earn achievements in their sleep; this is about the person
    // playing, and a companion congratulating another companion is noise.
    if (!player || GET_PLAYERBOT_AI(player))
        return;

    std::ostringstream q;
    q << "INSERT INTO anm_milestone (player_guid, player_name, kind, detail, number) VALUES ("
      << player->GetGUID().GetCounter() << ",'" << Escape(player->GetName()) << "','"
      << Escape(kind) << "','" << Escape(Truncate(detail)) << "'," << number << ")";
    CharacterDatabase.Execute(q.str());
}

void AnmBridge::RecordChat(Player* sender, uint32 type, uint32 lang, std::string const& msg,
                           ObjectGuid receiverGuid, std::string const& channel)
{
    if (!sender || msg.empty())
        return;

    // Bot chatter is recorded too: the roleplay agent needs to see what bots already said, or it
    // will talk over them. Whether a sender IS a bot is resolved on the PHP side by joining
    // characters.account against account.username LIKE 'RNDBOT%' — doing it here would mean either
    // a per-message database round trip or a hard compile-time dependency on mod-playerbots.

    std::ostringstream q;
    q << "INSERT INTO anm_chat_event (sender_guid, sender_name, chat_type, language,"
         " receiver_guid, channel, message, map, zone, area, position_x, position_y, position_z) VALUES ("
      << sender->GetGUID().GetCounter() << ",'" << Escape(sender->GetName()) << "',"
      << type << "," << lang << "," << receiverGuid.GetCounter() << ",'" << Escape(channel) << "','"
      << Escape(msg) << "'," << sender->GetMapId() << "," << sender->GetZoneId() << ","
      << sender->GetAreaId() << "," << sender->GetPositionX() << "," << sender->GetPositionY() << ","
      << sender->GetPositionZ() << ")";

    CharacterDatabase.Execute(q.str());
}

void AnmBridge::DrainOutbox()
{
    QueryResult result = CharacterDatabase.Query(
        "SELECT id, bot_guid, action, target_guid, message FROM anm_outbox "
        "WHERE status = 'pending' ORDER BY id LIMIT 20");

    if (!result)
        return;

    do
    {
        Field* f = result->Fetch();
        uint64 const id = f[0].Get<uint64>();
        uint32 const botGuid = f[1].Get<uint32>();
        std::string const action = f[2].Get<std::string>();
        uint32 const targetGuid = f[3].Get<uint32>();
        std::string const message = Truncate(f[4].Get<std::string>());

        auto fail = [id](std::string const& why)
        {
            std::ostringstream q;
            q << "UPDATE anm_outbox SET status='failed', error='" << Escape(why)
              << "', processed_at=NOW() WHERE id=" << id;
            CharacterDatabase.Execute(q.str());
        };

        ObjectGuid const botObjectGuid = ObjectGuid::Create<HighGuid::Player>(botGuid);

        // Bringing a bot into the world, or sending it away, are the only actions that make sense
        // on a bot that is not currently online — so they are handled before the online guard.
        if (action == "login")
        {
            if (sRandomPlayerbotMgr.GetPlayerBot(botObjectGuid))
            {
                // Already here. Succeed rather than fail: the caller asked for a bot in the
                // world and there is one, which is what it actually wanted.
                std::ostringstream ok;
                ok << "UPDATE anm_outbox SET status='done', processed_at=NOW() WHERE id=" << id;
                CharacterDatabase.Execute(ok.str());
                continue;
            }

            sRandomPlayerbotMgr.AddPlayerBot(botObjectGuid, 0);

            std::ostringstream ok;
            ok << "UPDATE anm_outbox SET status='done', processed_at=NOW() WHERE id=" << id;
            CharacterDatabase.Execute(ok.str());
            continue;
        }

        if (action == "logout")
        {
            if (!sRandomPlayerbotMgr.GetPlayerBot(botObjectGuid))
            {
                std::ostringstream ok;
                ok << "UPDATE anm_outbox SET status='done', processed_at=NOW() WHERE id=" << id;
                CharacterDatabase.Execute(ok.str());
                continue;
            }

            sRandomPlayerbotMgr.LogoutPlayerBot(botObjectGuid);

            std::ostringstream ok;
            ok << "UPDATE anm_outbox SET status='done', processed_at=NOW() WHERE id=" << id;
            CharacterDatabase.Execute(ok.str());
            continue;
        }

        Player* bot = ObjectAccessor::FindPlayerByLowGUID(botGuid);
        if (!bot || !bot->IsInWorld())
        {
            fail("bot not online");
            continue;
        }

        Player* target = targetGuid ? ObjectAccessor::FindPlayerByLowGUID(targetGuid) : nullptr;

        if (action == "attack")
        {
            PlayerbotAI* botAI = GET_PLAYERBOT_AI(bot);
            if (!botAI)
            {
                fail("not a playerbot");
                continue;
            }

            // 40 yards is a little beyond the player's own targeting range, so anything they can
            // see and mean is in reach.
            std::string seenButUnattackable;
            Unit* victim = FindAttackable(bot, 40.0f, message, seenButUnattackable);
            if (!victim)
            {
                // Three different answers, because they mean three different things to whoever
                // asked: nobody by that name, someone by that name who cannot be fought, or
                // nothing in reach at all.
                if (!seenButUnattackable.empty())
                    fail(seenButUnattackable + " is here but cannot be attacked");
                else
                    fail(message.empty() ? "nothing attackable within 40 yards"
                                         : "nothing called '" + message + "' attackable within 40 yards");

                continue;
            }

            // Both halves matter. Selecting and attacking starts the fight now; handing the guid
            // to the bot's own AI as a prioritised target is what stops its combat engine
            // dropping back to idle on the next tick and undoing us.
            botAI->GetAiObjectContext()->GetValue<GuidVector>("prioritized targets")->Set({victim->GetGUID()});
            bot->SetSelection(victim->GetGUID());
            bot->Attack(victim, true);
            bot->GetMotionMaster()->MoveChase(victim);
        }
        else if (action == "say")
        {
            bot->Say(message, LANG_UNIVERSAL);
        }
        else if (action == "yell")
        {
            bot->Yell(message, LANG_UNIVERSAL);
        }
        else if (action == "emote")
        {
            bot->TextEmote(message);
        }
        else if (action == "whisper")
        {
            if (!target)
            {
                fail("whisper target not online");
                continue;
            }
            bot->Whisper(message, LANG_UNIVERSAL, target);
        }
        else if (action == "party")
        {
            Group* group = bot->GetGroup();
            if (!group)
            {
                fail("bot not in a group");
                continue;
            }

            // The caller passes whoever it meant to answer. `group_member` is persisted state and
            // outlives the live group — after a player relogs, the rows can still say they are
            // partied while the in-memory group has been torn down. Broadcasting to that group
            // succeeds silently and nobody hears it, which is the worst kind of failure: the
            // outbox reads "done" while the player sits there being ignored.
            if (targetGuid)
            {
                ObjectGuid const listener = ObjectGuid::Create<HighGuid::Player>(targetGuid);

                if (!group->IsMember(listener))
                {
                    fail("intended listener is not in the bot's group (stale party)");
                    continue;
                }
            }

            WorldPacket data;
            ChatHandler::BuildChatPacket(data, CHAT_MSG_PARTY, LANG_UNIVERSAL, bot, nullptr, message);
            group->BroadcastPacket(&data, false);
        }
        else if (action == "guild")
        {
            Guild* guild = bot->GetGuildId() ? sGuildMgr->GetGuildById(bot->GetGuildId()) : nullptr;
            if (!guild)
            {
                fail("bot not in a guild");
                continue;
            }
            guild->BroadcastToGuild(bot->GetSession(), false, message, LANG_UNIVERSAL);
        }
        else
        {
            fail("unknown action: " + action);
            continue;
        }

        std::ostringstream ok;
        ok << "UPDATE anm_outbox SET status='done', processed_at=NOW() WHERE id=" << id;
        CharacterDatabase.Execute(ok.str());
    } while (result->NextRow());
}
