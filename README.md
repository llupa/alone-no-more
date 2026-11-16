# Alone No More

A dead MMO server, brought back to life by a PHP application standing next to it.

*Alone No More* runs alongside a local [AzerothCore](https://www.azerothcore.org/) server with
[mod-playerbots](https://github.com/mod-playerbots/azerothcore-wotlk). It does three things the
server cannot do for itself:

- **It herds a crowd.** Rather than logging in two thousand bots and hoping some are near you,
  it keeps roughly fifty in the zone you are actually standing in — chosen to match your level
  and your faction, logged in where they belong so nobody pops into existence in front of you.
- **It gives them something to say.** Companions hold a conversation grounded in the server's
  own database: the quest you linked, what it still needs, where the thing you are missing
  actually drops, and what is standing near you right now.
- **And something to do.** "Take me to Marshal Dughan" and one walks you there, fighting what
  interrupts it and picking the walk up again afterwards. "Sell your junk" at a merchant, "give
  me that sword", "put those on" — ordinary sentences, no slash commands. Where the mod's own
  vocabulary could not reach, we wrote the C++ for it.

All of it is exposed over **MCP**, so an assistant can look at the living world and change it.

```
> what's happening in Teldrassil?

  Teldrassil: 94 online (0 players, 94 bots)
  Darnassus: 1 online (0 players, 1 bot)
```

Built for the Symfony AI & MCP Hackathon, on Symfony 8.1 and PHP 8.5.

---

## Try it in two minutes, with no game at all

You do not need AzerothCore, MySQL, a C++ toolchain or a copy of a 2008 game client. A **captured
snapshot of a real session** is committed, and the whole application runs against it:

```bash
git clone <this repo> && cd alone-no-more
composer install
APP_ENV=replay bin/console anm:world Doppel
```

Everything in `fixtures/` was captured from a live server with `bin/console anm:snapshot`. None of
it is written by hand — inventing game data is exactly the confident fiction this project exists
to avoid.

### Point an assistant at it

```bash
APP_ENV=replay bin/console mcp:server     # speaks MCP over stdio
```

In Claude Desktop's `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "alone-no-more": {
      "command": "php",
      "args": ["/absolute/path/to/alone-no-more/bin/console", "mcp:server"],
      "env": { "APP_ENV": "replay" }
    }
  }
}
```

Then ask it *"where is Doppel and what are they working on?"* or *"what is alive near them?"*.
Drop `APP_ENV` once you have a real server and the same tools drive it.

---

## The MCP tools

Twenty-two, split by whether they change anything — a client may run a read-only tool without
asking permission, so the split is a permission boundary rather than tidiness.

| Tool | What it answers | Changes the world |
|---|---|---|
| `world_player_state` | Where a character is, their party, their quest log with progress | no |
| `world_nearby_actors` | Who is around them, and which creatures are *currently alive* | no |
| `world_zone_population` | Where the world is busy and where it is empty | no |
| `look_up_quest` | A quest on **this** server, every step when a chain reuses a title | no |
| `look_up_item` | Quality, item level, required level | no |
| `where_to_get` | Which creatures drop it, or which chest holds it, and where | no |
| `where_is` | Where a named person or creature stands | no |
| `find_creature` | What these things are, how tough, and roughly where | no |
| `what_needs_item` | Which quests want the thing in your bag | no |
| `recent_chat` | What the world has been saying | no |
| `prepare_companion` | Set a specific character aside to play with later | **yes** |
| `planned_companions` | Who is waiting for a character | no |
| `cancel_companion` | Stop keeping one aside | **yes** |
| `summon_crowd` | Populate a zone with level-appropriate bots | **yes** |
| `send_bots_away` | Thin a crowd without emptying it | **yes** |
| `bot_command` | Give one companion an order | **yes** |
| `what_to_do_next` | What a character could get on with: ready to hand in, still needed, what else the zone offers | no |
| `world_settings` | How the director is currently set up | no |
| `configure_world` | Crowd size, level range, how talkative companions are | **yes** |
| `set_herding` | Turn the crowd on or off | **yes** |
| `follow_character` | Whose zone to keep alive | **yes** |
| `stop_following_character` | Stop following one | **yes** |

**There is no cockpit, and there does not need to be.** Everything you would reach for a
dashboard to change — how busy the world is, whose zone it follows, whether it is herding at
all — is a sentence to an assistant, applied on the listener's next pass with no restart. That
is the part MCP makes possible that a bespoke UI would only have imitated.

`bot_command` reaches a GM console, so it is validated twice: the verb against the mod's own
command vocabulary, and the name against the shape a character name can have. The HTTP transport
is deliberately off — it has no authentication of its own, and three of these tools change a
running world.

---

## Running it against a real server

Everything above runs against a recording. To play it for real you need AzerothCore with
mod-playerbots, our C++ module built into it, and a 3.3.5a client.

Be aware before you start that mod-playerbots does **not** build against upstream AzerothCore —
it needs the `Playerbot` branch of a fork. The guide below names the repositories and the
revisions this was built against, because getting that wrong fails at build time, an hour in.

**→ [docs/real-server.md](docs/real-server.md)** — written to be followed step by step, with
the reason given for each setting that is not obvious. About fifteen minutes if the server is
already up.

```bash
bin/console anm:smoke    # tests every link and names whichever one is wrong
./server.sh start
./listen.sh start
```

---

## How it works

**→ [docs/architecture.html](docs/architecture.html)** — the same picture, interactive and
self-contained: light and dark, click any box to trace what it touches, export to PNG or SVG.

```
   WoW client
       |
  AzerothCore + mod-playerbots + mod-anm-bridge
       |  chat events (anm_chat_event)        orders (anm_outbox)
       v                                           ^
  ┌─────────────────────────────────────────────────────┐
  │  Alone No More (PHP)                                │
  │                                                     │
  │   Herding ──── who should be here, and where        │
  │   Roleplay ─── who answers, and what they know      │
  │   Knowledge ── the server's own quest and loot data │
  │   MCP ──────── all of it, to any assistant          │
  └─────────────────────────────────────────────────────┘
```

**`mod-anm-bridge`** is a small C++ module we wrote, for the things that cannot be done from
outside the worldserver at all: capturing chat, making a bot say arbitrary text, logging bots in
and out, attacking something the player has not selected, and walking a companion somewhere
further than the mod's own 150-yard leash allows.
**→ [docs/the-server-module.md](docs/the-server-module.md)** — what each part does, and which
six failed attempts taught us why it has to live in C++.

**Two models, by cost.** A cheap archivist (Haiku) reads database rows — quest templates, loot
tables, item records — and boils them down to a sentence. The companion (Sonnet) never sees a raw
row; it asks the archivist through a tool. That is a lot of input tokens for very little output,
which is exactly the shape a cheap model is for.

**Grounded, deliberately.** An ungrounded model invents landmarks: asked for work near Astranaar,
a bot once offered a job at "Raptor's Spine", which does not exist. A confidently wrong landmark
is worse than a vague answer, because anyone who knows the zone sees the seam immediately. So
every fact in a prompt comes from the server's own tables.

---

## Development

```bash
bin/phpunit                                   # 288 tests, 13 of which need client data
vendor/bin/phpstan analyse                    # level 8, src and tests
vendor/bin/deptrac analyse                    # layers may depend downward only
bin/console lint:container                    # DI must stay green
vendor/bin/php-cs-fixer fix                   # @Symfony ruleset
bin/console anm:snapshot Doppel               # recapture the replay fixture
```

The thirteen that skip read `AreaTable.dbc` and `WorldMapArea.dbc`, which ship with the game
client and cannot be committed. Point `ANM_DBC_DIR` at your server's `data/dbc` in
`.env.test.local` and they run; without it they skip rather than fail, because a missing zone
name degrades to the continent and that is a real, supported state.

Every outbound integration sits behind an interface — `WorldGatewayInterface`,
`GameKnowledgeInterface`, `QuestLogInterface`, `BotStagerInterface` — which is what lets the
replay implementations be first-class rather than test doubles bolted on afterwards.

The layers run one way, and `deptrac.yaml` enforces it: World and Config at the bottom, then
Knowledge, Herding, Director, Roleplay, and the two ways in — MCP and the console — on top.
Where a lower layer genuinely needs something from above, it declares an interface and the
higher layer implements it: `Herding\ProtectedBots` is how a companion mid-conversation
survives a dismissal sweep without the crowd layer ever learning what a conversation is.

## Licence

MIT. World of Warcraft and AzerothCore are the property of their respective owners; no game
client data is included in this repository.
