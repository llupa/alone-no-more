#include "AnmBridge.h"

#include "Channel.h"
#include "Guild.h"
#include "Group.h"
#include "Player.h"
#include "ScriptMgr.h"

namespace
{
    // How often the outbox is drained. Bot replies are conversational, so a half-second floor is
    // imperceptible in chat and keeps the query off the hot path of the world update.
    constexpr uint32 OUTBOX_INTERVAL_MS = 500;
}

class AnmChatCaptureScript : public PlayerScript
{
public:
    AnmChatCaptureScript() : PlayerScript("AnmChatCaptureScript", {
        PLAYERHOOK_CAN_PLAYER_USE_CHAT,
        PLAYERHOOK_CAN_PLAYER_USE_PRIVATE_CHAT,
        PLAYERHOOK_CAN_PLAYER_USE_GROUP_CHAT,
        PLAYERHOOK_CAN_PLAYER_USE_GUILD_CHAT,
        PLAYERHOOK_CAN_PLAYER_USE_CHANNEL_CHAT,
        PLAYERHOOK_ON_LEVEL_CHANGED,
        PLAYERHOOK_ON_ACHI_COMPLETE,
    }) { }

    // Levelling and achievements are recorded as they happen rather than polled. Polling
    // `characters.level` would need a remembered baseline per player, would miss two levels
    // gained between two ticks, and could never learn an achievement's name — the core hands
    // us the whole AchievementEntry here.
    void OnPlayerLevelChanged(Player* player, uint8 oldLevel) override
    {
        if (!player || player->GetLevel() <= oldLevel)
            return;  // levels can be taken away too; only a ding is worth remarking on

        AnmBridge::RecordMilestone(player, "level", "", player->GetLevel());
    }

    void OnPlayerAchievementComplete(Player* player, AchievementEntry const* achievement) override
    {
        if (!achievement)
            return;

        // name[0] is enUS, the same slot the client data uses everywhere else here.
        char const* title = achievement->name[0];

        AnmBridge::RecordMilestone(player, "achievement", title ? title : "", achievement->ID);
    }

    // Every overload returns true: we observe chat, we never block it.
    bool OnPlayerCanUseChat(Player* player, uint32 type, uint32 lang, std::string& msg) override
    {
        AnmBridge::RecordChat(player, type, lang, msg, ObjectGuid::Empty, "");
        return true;
    }

    bool OnPlayerCanUseChat(Player* player, uint32 type, uint32 lang, std::string& msg, Player* receiver) override
    {
        AnmBridge::RecordChat(player, type, lang, msg, receiver ? receiver->GetGUID() : ObjectGuid::Empty, "");
        return true;
    }

    bool OnPlayerCanUseChat(Player* player, uint32 type, uint32 lang, std::string& msg, Group* /*group*/) override
    {
        AnmBridge::RecordChat(player, type, lang, msg, ObjectGuid::Empty, "");
        return true;
    }

    bool OnPlayerCanUseChat(Player* player, uint32 type, uint32 lang, std::string& msg, Guild* /*guild*/) override
    {
        AnmBridge::RecordChat(player, type, lang, msg, ObjectGuid::Empty, "");
        return true;
    }

    bool OnPlayerCanUseChat(Player* player, uint32 type, uint32 lang, std::string& msg, Channel* channel) override
    {
        AnmBridge::RecordChat(player, type, lang, msg, ObjectGuid::Empty,
                              channel ? channel->GetName() : "");
        return true;
    }
};

class AnmWorldScript : public WorldScript
{
public:
    AnmWorldScript() : WorldScript("AnmWorldScript", { WORLDHOOK_ON_STARTUP, WORLDHOOK_ON_UPDATE }) { }

    void OnStartup() override
    {
        AnmBridge::EnsureSchema();
    }

    void OnUpdate(uint32 diff) override
    {
        _elapsed += diff;
        if (_elapsed < OUTBOX_INTERVAL_MS)
            return;

        _elapsed = 0;
        AnmBridge::DrainOutbox();

        // Same cadence, deliberately: a companion leading the way re-issues a hop only when it
        // has stopped, so twice a second is plenty and keeps LEAD_INTERVAL_MS honest.
        AnmBridge::DriveLeads();
    }

private:
    uint32 _elapsed = 0;
};

void AddAnmBridgeScripts()
{
    new AnmChatCaptureScript();
    new AnmWorldScript();
}

void Addmod_anm_bridgeScripts()
{
    AddAnmBridgeScripts();
}
