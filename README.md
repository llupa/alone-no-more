# Alone No More

A dead MMO server, brought back to life by a PHP application standing next to it.

*Alone No More* runs alongside a local [AzerothCore](https://www.azerothcore.org/) server with
[mod-playerbots](https://github.com/mod-playerbots/azerothcore-wotlk). It does two things the
server cannot do for itself:

- **It herds a crowd.** Rather than logging in two thousand bots and hoping some are near you,
  it keeps roughly fifty in the zone you are actually standing in — chosen to match your level
  and your faction, logged in where they belong so nobody pops into existence in front of you.
- **It gives them something to say.** Companions hold a conversation grounded in the server's
  own database: the quest you linked, what it still needs, where the thing you are missing
  actually drops, and what is standing near you right now.

Both are exposed over **MCP**, so an assistant can look at the living world and change it.

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

Seventeen, split by whether they change anything — a client may run a read-only tool without asking
permission, so the split is a permission boundary rather than tidiness.

| Tool | What it answers | Changes the world |
|---|---|---|
| `world_player_state` | Where a character is, their party, their quest log with progress | no |
| `world_nearby_actors` | Who is around them, and which creatures are *currently alive* | no |
| `world_zone_population` | Where the world is busy and where it is empty | no |
| `look_up_quest` | A quest on **this** server, every step when a chain reuses a title | no |
| `look_up_item` | Quality, item level, required level | no |
| `where_to_get` | Which creatures drop it, or which chest holds it, and where | no |
| `what_needs_item` | Which quests want the thing in your bag | no |
| `recent_chat` | What the world has been saying | no |
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

### What you need

- **PHP 8.4+** with `pdo_mysql`, `soap`, `sockets`, `mbstring`, `iconv`, `ctype`
- **AzerothCore** with **mod-playerbots**, and our module **`mod-anm-bridge`** (in `server/`)
- SOAP enabled in `worldserver.conf` (`SOAP.Enabled = 1`)
- Either an **Anthropic API key** or a local **Ollama** — Ollama is the default so the project
  runs with no account at all

### Configure

First, a GM account for the app to drive the console with. On your running server:

```
account create anm <a password you choose>
account set gmlevel anm 3 -1
```

`.env` holds defaults and is committed, so no password lives in it. Real values go in
`.env.local`, which is git-ignored:

```dotenv
ANM_WORLD_DSN=mysql://acore:acore@127.0.0.1:3306/acore_world
ANM_CHARACTERS_DSN=mysql://acore:acore@127.0.0.1:3306/acore_characters
ANM_SOAP_ENDPOINT=http://127.0.0.1:7878/
ANM_SOAP_USERNAME=anm
ANM_SOAP_PASSWORD=<the password you chose>

# Zone and subzone names live in the client data, not on the server.
ANM_DBC_DIR=/path/to/your/server/data/dbc

ANM_AI_PROVIDER=anthropic          # or "ollama"
ANTHROPIC_API_KEY=sk-ant-...
```

### Check it

`anm:smoke` tells you which of these is wrong before anything else runs:

```bash
bin/console anm:smoke               # server reachable, databases readable, chat captured
bin/console anm:world Doppel        # who is near you
bin/console anm:herd Doppel         # bring a crowd to your zone
./listen.sh start                   # the listener: herds, and answers chat
```

---

## How it works

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

**`mod-anm-bridge`** is a small C++ module we wrote. It captures chat through the core's
`OnPlayerCanUseChat` hooks into a table, and dispatches orders from an outbox — including logging
bots in and out, which is what makes herding possible without teleport artefacts.

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
bin/phpunit                                   # 248 tests
vendor/bin/phpstan analyse                    # level 8, src and tests
vendor/bin/deptrac analyse                    # layers may depend downward only
bin/console lint:container                    # DI must stay green
vendor/bin/php-cs-fixer fix                   # @Symfony ruleset
bin/console anm:snapshot Doppel               # recapture the replay fixture
```

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
