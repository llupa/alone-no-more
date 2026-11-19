# Alone No More

**AI companions for a private World of Warcraft server — built with Symfony AI and MCP.**

> [!IMPORTANT]
> **For educational purposes only.** Alone No More is a non-commercial learning project, built
> for the Symfony AI & MCP Hackathon. It runs against a private, local AzerothCore server, and is
> not affiliated with, endorsed by, or connected to Blizzard Entertainment. World of Warcraft is a
> trademark of Blizzard Entertainment, Inc. No game client files are included in this repository.

▶ **[Watch the 90-second demo](https://youtu.be/VIDEO_ID)**
<!-- TODO: replace VIDEO_ID with the YouTube link before submitting -->

---

A private WoW server gives you the whole world and nobody to share it with. mod-playerbots can
fill it with bots, but they stand in silence and take orders only as slash commands.

Alone No More is a Symfony application that stands beside the server and turns those bots into
company: a crowd wherever you are, and companions who talk, remember and help — in plain English.

## What it does

**A world that is lived in.** About fifty level-appropriate, same-faction bots in the zone you are
standing in, and more in the capitals. They arrive where they belong, never in front of you.

**Companions who talk, from the game's own data.** Quests, loot and creatures are read from the
server's database, never invented:

> *"You mean "Dry Times", which asks for a Cask of Merlot, a Bottle of Moonshine and a Skin of
> Sweet Rum."* — Anduril

**…and say so when they cannot.**

> *"Klemprime, there is no Redridge Mongrel within forty yards of us."* — Khaoh

**Companions who act on plain English.** Lead the way, fight until a quest is done, sell the
junk, hand over and equip gear — no slash commands.

> *"Follow me, Klemprime. Smith Argus is a short walk south-east, some four hundred paces, and I
> trust the weather will hold."*
>
> *"I have just sold the lot, including a Feeble Sword and seven Fractured Canines. Poor
> pickings, but gold is gold."* — Anduril

**Companions who remember** — the conversation, what they overheard, and what you still need.

> *"Only the Bottle of Moonshine now stands between you and "Dry Times" complete."* — Anduril,
> minutes later

**The whole world, from an assistant.** Twenty-two MCP tools: ask Claude to set up your party
before you log in, make the zone busier, or tell you what to do next.

Every quote above is from a real session, and that session is in this repository.

## Built with Symfony AI

- the **AI Bundle** with the **Anthropic** and **Ollama** platforms;
- **Agents** with tool calling and memory: a Claude Sonnet companion that consults a Claude Haiku
  archivist;
- the **MCP Bundle**, which exposes the world to assistants like Claude Desktop as 22 tools.

On Symfony 8.1 and PHP 8.5, beside [AzerothCore](https://www.azerothcore.org/) with
[mod-playerbots](https://github.com/mod-playerbots/azerothcore-wotlk) and a small C++ module of
our own.

## Try it

A recording of that real session is committed, and the whole application runs against it — no
game server, no database, no game client:

```bash
git clone <this repo> && cd alone-no-more && composer install

# anm:world <character>           who is around the player, in the recorded world
APP_ENV=replay bin/console anm:world Klemprime

# anm:prompt <companion> <player>  everything a companion answers from, when the player speaks
APP_ENV=replay bin/console anm:prompt Anduril Klemprime

# anm:talk <player>                talk to your companions as the player — Claude or Ollama
APP_ENV=replay bin/console anm:talk Klemprime
```

Klemprime is the player in the recording, and Anduril is his companion. Every command, with its
arguments and options: [docs/development.md](docs/development.md#commands).

| | You need | Guide |
|---|---|---|
| **Look around** | PHP and Composer | [docs/try-it.md](docs/try-it.md) |
| **Talk to a companion** | Ollama (free, local), or an Anthropic key | [docs/try-it.md](docs/try-it.md#2-talk-to-a-companion) |
| **Build everything yourself** | AzerothCore with mod-playerbots, our C++ module compiled in, and a 3.3.5a client | [docs/real-server.md](docs/real-server.md) |

## How it is built

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/architecture-dark.png">
  <img src="docs/architecture.png" alt="Alone No More — a PHP application beside World of Warcraft">
</picture>

## Documentation

| | |
|---|---|
| [Try it](docs/try-it.md) | The recording, talking to a companion, and Claude Desktop over MCP |
| [How it works](docs/how-it-works.md) | One line of chat end to end, the two models, memory, the crowd |
| [The MCP tools](docs/mcp-tools.md) | All twenty-two, and which of them change the world |
| [Running against a real server](docs/real-server.md) | Building AzerothCore, mod-playerbots and our module, step by step |
| [The server module](docs/the-server-module.md) | The C++ inside the worldserver, and why it has to be there |
| [Development](docs/development.md) | Tests, static analysis, layers, and every command |
| [Architecture](docs/architecture.html) | The interactive diagram — download and open locally |

## All features

**The crowd**
- About 50 bots kept within 600 yards of you, within 5 levels of yours and of your faction —
  every number adjustable, by an assistant over MCP or in `.env`.
- In the ten capitals and sanctuaries — Stormwind, Ironforge, Darnassus, The Exodar, Orgrimmar,
  Thunder Bluff, Undercity, Silvermoon, Shattrath and Dalaran — 50 more, packed closer.
- Bots log in where they belong, never in front of you, and go dormant rather than being thrown
  away, ready for wherever you turn up next.
- A zone change has to last a minute before the crowd moves, so riding along a border or stepping
  into a cave does not tear the world down.
- Anyone who spoke to you in the last ten minutes is never sent away mid-conversation.

**Companions who talk**
- In character, answering on the channel you used: party, say, whisper, guild or officer.
- Grounded in the server's own data: the creatures and people around them, the quests people
  nearby talk about, your quest log and theirs, what drops where. Nothing else is named.
- Two models: Claude Sonnet as the companion, Claude Haiku as the archivist it asks about quests,
  items, creatures and loot. Or Ollama, locally, for free.
- Quest and item links you shift-click into chat are understood.
- Each bot has a personality, rolled once and kept: creed, culture, humour, manner, pride and
  warmth.
- Memory in three layers: the conversation itself, what they overheard from the rest of the party,
  and a record that survives restarts.
- One voice per party, so the whole group does not answer at once.
- Honest when they cannot help. Raw tool output never reaches your chat window, and a long answer
  ends on a finished sentence.

**Companions who act, in plain English**
- **Lead the way** to any NPC, past mod-playerbots' 150-yard leash, fighting what gets in the way
  and carrying on. They decline inside capitals, where the server's pathfinding cannot cope.
- **Keep fighting** until the quest objective is complete, as counted by the server.
- **Attack** something you name, without you having to target it.
- **Sell the junk** at a merchant, grey or white items.
- **Hand over** an item, or **put on** one from their bags.
- **Check** their quest log, your quest log, their bags and their experience.
- Any other mod-playerbots order — follow, stay, flee and the rest — checked against the mod's own
  vocabulary first.

**The world notices you**
- Now and then — every 30 minutes by default — someone nearby mutters something.
- Levels and achievements are congratulated — by your companion in party chat, or by whoever is
  standing nearest.
- A companion asked for in advance is waiting, online, when you log in, is never sent away, and is
  brought back if they wander off.

**From an assistant, over MCP**
- 22 tools: 13 that only look, 9 that change the world, annotated so a client can tell them apart.
- No dashboard: crowd size, level range, whose zone to follow, herding on or off — all by asking.
- Stdio only; the unauthenticated HTTP transport is switched off.

**Inside the server: `mod-anm-bridge`**
- Chat capture on every channel, with addon traffic left out.
- Bots saying any text on any channel, logging in and out, attacking a named target, and walking
  further than the mod allows.
- Level-ups and achievements recorded as they happen.

**Trying it and building it**
- Replay: the whole application, MCP included, against a recorded real session — no server, no
  database, no game client.
- `anm:talk` to talk to companions from the terminal, `anm:prompt` to see exactly what grounds one,
  `anm:smoke` to find a broken link to the server, `anm:snapshot` to record a new session.
- PHPStan level 8, layers enforced by deptrac, and agents tested with Symfony AI's `MockAgent`.
