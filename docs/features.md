# All features

Everything Alone No More does, how to use it, and the setting behind it. Settings live in `.env`
— override them in `.env.local`. The crowd's can also be changed while you play, by asking an
assistant over MCP (`configure_world`), with no restart.

---

## The crowd

The server's own bots are spread across the whole world. The herder keeps a believable crowd
where you actually are, and moves it with you.

- **Around you, at your level, on your side.** About 50 bots within 600 yards, no more than 5
  levels from yours, of your faction.
- **Fuller in the capitals.** In the ten capitals and sanctuaries — Stormwind, Ironforge,
  Darnassus, The Exodar, Orgrimmar, Thunder Bluff, Undercity, Silvermoon, Shattrath and Dalaran —
  50 more, gathered within 250 yards, so the crowd is in the city rather than in the fields
  outside its walls.
- **Nobody appears in front of you.** Bots log in where they belong.
- **Nobody is thrown away.** Bots that leave go dormant, ready to be brought in wherever you turn
  up next.
- **Calm at the borders.** A zone change has to hold for a minute before the crowd moves, so riding
  along a border or stepping into a cave does not tear the world down and rebuild it.
- **Kept topped up.** Every three minutes the crowd is checked again: bots die, wander off, or are
  sent away elsewhere. Bots more than 1,200 yards from you are sent away.
- **Never mid-sentence.** Anyone who spoke to you in the last ten minutes is never sent away.
- **Awake.** New arrivals get ten seconds to finish logging in, then are set about their business —
  otherwise they stand in a clearing, fifty of them, doing nothing.

| Setting | Default | |
|---|---|---|
| `ANM_HERD_TARGET` | 50 | Bots to keep around you |
| `ANM_HERD_RADIUS` | 600 | How far "around you" reaches, in yards |
| `ANM_PEER_LEVEL_TOLERANCE` | 5 | Levels a bot may be from yours |
| `ANM_HERD_CITY_EXTRA` | 50 | Extra bots in a capital or sanctuary |
| `ANM_HERD_CITY_RADIUS` | 250 | How far "around you" reaches in one |
| `ANM_HERD_SETTLE_SECONDS` | 60 | How long a zone change must hold before the crowd moves |
| `ANM_HERD_REFRESH_SECONDS` | 180 | How often the crowd is topped up |
| `ANM_HERD_DISMISS_BEYOND` | 1200 | Bots further than this are sent away |
| `ANM_HERD_PROTECT_SPOKEN_WITHIN` | 600 | Seconds after speaking to you that a bot is safe from that |
| `ANM_HERD_ROUSE_DELAY_SECONDS` | 10 | Time to finish logging in before being given orders |

**Over MCP:** `summon_crowd`, `send_bots_away`, `world_zone_population`, `configure_world`,
`set_herding`, `follow_character`, `stop_following_character`.
**From the terminal:** `anm:herd`, `anm:world`.

---

## Companions who talk

- **In character, on the channel you used.** Party, say, whisper, guild or officer — an answer to a
  whisper is never said aloud, and an officer's line is never answered to the whole guild.
- **Grounded in the server's own data.** Each reply is built from the world as it stands: who and
  what is around the companion, the quests local people talk about, your quest log and theirs,
  what drops where. The prompt tells them to name nothing else, and nothing else is in it. See one
  with `anm:prompt <companion> <player>`.
- **Two models, by cost.** Claude Sonnet is the companion. Claude Haiku is the archivist it asks
  about quests, items, creatures and loot — reading database rows is a lot of input for a short
  answer, which is what a cheap model is for. Or Ollama, on your own machine, for free: it talks
  well but uses no tools.
- **Links understood.** Shift-click a quest or an item into chat and the companion knows exactly
  which one you mean.
- **A personality, kept.** Rolled once per bot: creed, culture, humour, manner, pride and warmth,
  each written as something to *do* in a sentence ("mention the weather more than anyone needs you
  to") rather than an adjective a model ignores.
- **Memory, in three layers.** The conversation itself, as real turns, so a companion reads its own
  past replies as its own. What it overheard from the rest of the party while it was silent. And a
  record that survives restarts, so the listener coming back does not bring back strangers.
- **One voice per party.** One companion answers for the group instead of all of them at once, and
  none answers twice within three seconds while you are still typing.
- **Honest.** "There is no Redridge Mongrel within forty yards of us." A companion that cannot do
  something says so in character, and never claims an action that did not happen.
- **Fit for a chat window.** Replies are cut to the game's 255-byte limit, ending on a finished
  sentence. Raw tool output never reaches you.
- **Orders left alone.** A line starting with `!` — `!follow`, `!stay` — is an order for
  mod-playerbots itself, and companions do not talk over it.

| Setting | Default | |
|---|---|---|
| `ANM_AI_PROVIDER` | `ollama` | `ollama` or `anthropic` |
| `ANM_ANTHROPIC_MODEL` | `claude-sonnet-5-5` | The companion, on Claude |
| `ANM_LORE_MODEL` | `claude-haiku-5-5` | The archivist — always on Claude |
| `ANM_OLLAMA_MODEL` | `llama3.1:8b` | The companion, on Ollama |
| `ANM_HOT_MEMORY_MESSAGES` | 20 | Conversation turns a companion keeps in mind |
| `ANM_SCENE_MEMORY_LINES` | 6 | Overheard lines it is reminded of |
| `ANM_SOLE_BOT_COOLDOWN_SECONDS` | 3 | Shortest gap between two replies from the same companion |
| `ANM_BOT_COMMAND_PREFIX` | `!` | Must match `Playerbots.CommandPrefix` in `playerbots.conf` |

**From the terminal:** `anm:talk`, `anm:prompt`, `anm:ask`, `anm:listen`.

---

## Companions who act

Ask in plain words. The companion decides which tool fits, and acts as itself. Tools need Claude;
on Ollama, companions talk but do not act.

| Say something like | Tool | What happens |
|---|---|---|
| "Lead the way to Smith Argus" | `lead_the_way` | Walks you to the nearest one, past mod-playerbots' 150-yard leash, fighting what gets in the way and carrying on. Declines inside capitals, where the server's pathfinding cannot cope |
| "Kill ten mongrels for Assessing the Threat" | `keep_fighting` | Keeps fighting until the quest objective is complete, as the server counts it, finishing each fight before starting the next |
| "Stop, come back" | `stop_fighting` | Ends the errand and returns to you |
| "Attack that kobold" | `attack_something` | Attacks what you named, without you targeting it |
| "Sell your junk" / "sell your whites too" | `sell_junk` | Sells grey, or grey and white, items at a merchant who is in reach |
| "Give me the sword" | `hand_over` | Opens a trade window with it in — you still accept |
| "Put the boots on" | `wear_this` | Equips it from their bags |
| "What are you working on?" | `check_my_quest_log` | Reads their own quest log |
| "What do I still need?" | `check_their_quest_log` | Reads yours |
| "Do you have any water?" | `check_my_bags` | Looks through their bags |
| "How far off is your next level?" | `check_experience` | Their experience, or yours |
| "Follow me" / "wait here" | `do_this` | Any mod-playerbots order, checked against the mod's own vocabulary first |
| "Where do I find moonshine?" | `consult_the_archives` | Asks the archivist, who reads the server's tables |

| Setting | Default | |
|---|---|---|
| `ANM_ERRAND_SWING_SECONDS` | 8 | How long a fight gets before the companion is pointed at the next |

---

## The world notices you

- **Overheard.** Now and then someone within 25 yards mutters something, every 30 minutes by
  default. `anm:banter` makes it happen now.
- **Congratulations.** Levels and achievements are noticed — by your companion in party chat, or by
  whoever is standing nearest.
- **Companions on call.** Ask an assistant for "a priest to level with tonight" and one is chosen,
  named, and waiting online when you log in. They are never sent away with the crowd, and brought
  back if they wander off.

| Setting | Default | |
|---|---|---|
| `ANM_BANTER_SECONDS` | 1800 | Seconds between mutterings |
| `ANM_BANTER_RADIUS` | 25 | How far a muttering carries, in yards |

**Over MCP:** `prepare_companion`, `planned_companions`, `cancel_companion`, `recent_chat`.

---

## From an assistant, over MCP

Twenty-two tools: 13 that only look and 9 that change the world, annotated so a client knows which
it may run without asking. No dashboard — crowd size, level range, whose zone to follow and whether
to herd at all are things you say. Stdio only; the unauthenticated HTTP transport is off.
**→ [mcp-tools.md](mcp-tools.md)**

---

## Inside the server

`mod-anm-bridge`, a small C++ module, does what cannot be done from outside the worldserver:
captures chat on every channel (leaving addon traffic out), makes bots say any text on any channel,
logs bots in and out, attacks a named target, walks a companion further than the mod allows, and
records levels and achievements as they happen.
**→ [the-server-module.md](the-server-module.md)**

---

## Trying it and building it

- **Replay.** The whole application, MCP included, against a recorded real session — no server, no
  database, no game client. **→ [try-it.md](try-it.md)**
- **The commands.** `anm:talk`, `anm:prompt`, `anm:smoke` to name a broken link to the server,
  `anm:snapshot` to record a session of your own, and the rest. **→ [development.md](development.md#commands)**
- **Quality.** PHPStan level 8, layers enforced by deptrac, and agents tested with Symfony AI's own
  `MockAgent` against the real recorded world.
