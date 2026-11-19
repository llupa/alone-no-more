# Try it without a game server

You do not need AzerothCore, MySQL, a C++ toolchain or a copy of a 2008 game client. A
**recording of a real play session** is committed, and the whole application runs against it —
the same code, the same agents, the same MCP tools, with only the source of the data swapped.

There are three levels, each a step further in:

| | You need | You get |
|---|---|---|
| **1. Look around** | PHP 8.5 and Composer | The recorded world, what grounds a companion, and all 22 MCP tools in Claude Desktop |
| **2. Talk to a companion** | the above, plus Ollama (free, local) or an Anthropic key | Anduril answering you in character, from the recording's quest logs |
| **3. The real thing** | a private AzerothCore server and a 3.3.5a client | Everything, in the game — see [real-server.md](real-server.md) |

```bash
git clone <this repo> && cd alone-no-more
composer install
```

Every command below starts with `APP_ENV=replay`. Drop it once you have a real server and the
same commands drive that instead.

---

## 1. Look around — no key, no server

### The recorded world

```bash
APP_ENV=replay bin/console anm:world Klemprime
```

```
Klemprime — a male Human warrior of the Alliance, level 14
==========================================================

 ! [NOTE] A recording of a real session in Redridge Mountains, captured 17 November 2026.
 !        No game server is running.

Within 150yd: 8
---------------
  name       who                faction    lvl   peer?   distance
  Anduril    Night Elf Priest   Alliance   13    yes     1 yd
  Ibykick    Gnome Warlock      Alliance   15    yes     106 yd
  Bolgew     Tauren Warrior     Horde      19    yes     108 yd
  …
```

Klemprime is the player; Anduril, one yard away, is the AI companion in his party; the rest are
the crowd the app keeps around him so the world feels lived in.

### What grounds a companion

```bash
APP_ENV=replay bin/console anm:prompt Anduril Klemprime
```

The exact system prompt Anduril would answer from, assembled by the same code the game uses: a
rolled personality ("proud, formal, sardonic… mention the weather more than anyone needs you
to"), the creatures and people actually standing around him in the recording, the quests local
people talk about — and the rule that he names nothing that is not in those lists. Every fact in
it comes from the server's own tables. This is the whole answer to "how do you stop a model
inventing places".

### The world, from an assistant

```bash
APP_ENV=replay bin/console mcp:server     # speaks MCP over stdio
```

In Claude Desktop's `claude_desktop_config.json`, then restart Claude Desktop:

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

Then ask, in plain words. Every answer below is what the recording actually returns:

| Ask | Tool it reaches | What comes back |
|---|---|---|
| *"Where is Klemprime and what is he working on?"* | `world_player_state` | Level 14 warrior in Redridge, in a party with Anduril, and his quest log with progress — "Dry Times" still needs a Bottle of Moonshine |
| *"What should Klemprime do next?"* | `what_to_do_next` | Four quests ready to hand in, and what the others still need |
| *"What has the party been saying?"* | `recent_chat` | The recorded conversation, both halves of it |
| *"What is Dry Times?"* | `look_up_quest` | Barkeep Daniels of Lakeshire, and the four drinks he wants |
| *"Where do Underbelly Whelp Scales come from?"* | `where_to_get` | Black Dragon Whelps and Snarlflare, about 80% of the time |
| *"Who is around Klemprime?"* | `world_nearby_actors` | The eight characters within 150 yards, players and bots |

All 22 tools are listed in [mcp-tools.md](mcp-tools.md).

---

## 2. Talk to a companion

```bash
APP_ENV=replay bin/console anm:talk Klemprime
```

You type as Klemprime in party chat, and Anduril answers. The line goes through exactly what a
line typed in the game does — the same responder, prompt, agent, tools and memory — so this is the
companion himself, not a demo of him:

```
 Klemprime:
 > Anduril, do we still need anything for Dry Times?

  Anduril (Night Elf Priest, lvl 13): Yes, Klemprime. Your log shows only the Bottle of
  Moonshine still missing. I still lack the Merlot, the Moonshine and the Sweet Rum, so the work
  is not finished.

 Klemprime:
 > Can you lead the way to Barkeep Daniels?

  Anduril (Night Elf Priest, lvl 13): I cannot lead you there, Klemprime, for nothing in this
  place can be moved or changed. I can still talk, or look up where the barkeep may be found.
```

Both answers are true of the recording: Klemprime's log does lack only the Moonshine, and
Anduril's own copy of the quest still lacks three. The second is an order a recording cannot
carry out, refused in character rather than announced as done.

`--say "…"` sends one line and stops; `--to Anduril` whispers instead of speaking in party chat.
What he remembers of you is kept in `var/replay.sqlite`; delete it to be strangers again.

### Which model answers

| | Set up | What you get |
|---|---|---|
| **Claude** (recommended) | `ANM_AI_PROVIDER=anthropic` and `ANTHROPIC_API_KEY=…` in `.env.local` | The real thing: tools, the archivist, Claude Sonnet as the companion and Claude Haiku reading the records |
| **Ollama** (free, local) | the default: `ollama pull llama3.1:8b` and have Ollama running | Conversation without tools. An 8B model on a laptop is slower and looser — it will sometimes break the voice it was given — but it costs nothing and needs no account |

You can ask the archivist directly too — it always runs on Claude:

```bash
APP_ENV=replay bin/console anm:ask "Where do I find a Bottle of Moonshine?"
```

---

## What the recording is

`fixtures/world.json`, captured from a live server with `bin/console anm:snapshot` at the end of a
session in Redridge: 38 characters and the party, 22 quest logs, 84 lines of the conversation
between the player and his companions, and the 100 quests, 91 items and 159 loot entries from the
server's own database that the session touched. None of it is invented — making up game data is
exactly the confident fiction this project exists to avoid.

One seam worth naming, because this fixture predates its fix: the companions' party replies in it
were taken from the listener's own log rather than captured with everything else.
`mod-anm-bridge` reads chat through `OnPlayerCanUseChat`, and a party message it broadcasts itself
never passes that hook — so the table held every question the player asked and not one answer.
Every line is a real thing a companion said; it simply reached the fixture by a different road.
The module records its own party and guild speech now, so the next capture needs no such help.

## What a recording will not do

- **Change anything.** There is no server, so every order is refused and says so — a companion
  tells you in character, and `bot_command` answers *"This is a recorded world, so … was not
  run."* The recording was captured just after the session ended, so Klemprime is offline, and
  `summon_crowd` says there is nowhere to gather anyone. A demo that faked "brought in 19" would
  misrepresent the project it demonstrates.
- **Know the whole game.** Lookups know what the session touched — Redridge's quests, items,
  creatures and loot — not all of Azeroth. Ask about something elsewhere and the answer is that it
  is not in the records, rather than an invented one.

The settings tools (`configure_world`, `set_herding`, `follow_character`) do work: they write to
`var/replay.sqlite`, kept apart from the state a live server uses. With no listener running,
nothing acts on what they set.
