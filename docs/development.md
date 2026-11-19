# Development

```bash
php bin/phpunit                               # the suite; a few tests need client data
vendor/bin/phpstan analyse                    # level 8, src and tests
vendor/bin/deptrac analyse                    # layers may depend downward only
bin/console lint:container                    # DI must stay green — and APP_ENV=replay too
vendor/bin/php-cs-fixer fix                   # @Symfony ruleset
bin/console anm:snapshot Klemprime            # recapture the replay fixture from a live server
```

The tests that skip read `AreaTable.dbc` and `WorldMapArea.dbc`, which ship with the game client
and cannot be committed. Point `ANM_DBC_DIR` at your server's `data/dbc` in `.env.test.local` and
they run; without it they skip rather than fail, because a missing zone name degrades to the
continent and that is a real, supported state.

Agents are tested with Symfony AI's own `MockAgent`: `anm:talk` runs against the real recorded
world and the real conversation memory, with only the model replaced, so a test says what the
application does with an answer rather than what a model happens to say.

## Structure

Every outbound integration sits behind an interface — `WorldGatewayInterface`,
`GameKnowledgeInterface`, `QuestLogInterface`, `BotStagerInterface`, `OutboxInterface` — which is
what lets the replay implementations be first-class rather than test doubles bolted on
afterwards.

The layers run one way, and `deptrac.yaml` enforces it: World and Config at the bottom, then
Knowledge, Herding, Director, Roleplay, and the two ways in — MCP and the console — on top. Where a
lower layer genuinely needs something from above, it declares an interface and the higher layer
implements it: `Herding\ProtectedBots` is how a companion mid-conversation survives a dismissal
sweep without the crowd layer ever learning what a conversation is.

## Commands

Every command, as its own `--help` defines it. Run `bin/console <command> --help` for the same
from the terminal. Where a character is optional, it defaults to whoever is playing.

### Trying it, live or against the recording

#### `anm:world`

Show who is near a character, and how the zone is populated.

```
anm:world [--radius RADIUS] [--] [<character>]
```

| | | |
|---|---|---|
| `<character>` | optional | Character to look around from. |
| `--radius=RADIUS` | option | Radius in yards. Default: `150`. |

```bash
APP_ENV=replay bin/console anm:world Klemprime --radius=300
```

#### `anm:prompt`

Show the system prompt a bot would be given, and where each line came from.

```
anm:prompt [--said SAID] [--] <bot> [<speaker>]
```

| | | |
|---|---|---|
| `<bot>` | required | The bot whose prompt to render. |
| `<speaker>` | optional | Who is speaking to them. |
| `--said=SAID` | option | What was said, so linked quests and items resolve. Default: `hello there`. |

```bash
APP_ENV=replay bin/console anm:prompt Anduril Klemprime --said="Where do I find moonshine?"
```

#### `anm:talk`

Talk to your companions from the terminal, as a player in party chat.

```
anm:talk [--say SAY] [--to TO] [--] [<character>]
```

| | | |
|---|---|---|
| `<character>` | optional | Who you are. Defaults to whoever is playing; against the recording, Klemprime. |
| `--say=SAY` | option | Say this one line and stop, instead of holding a conversation. |
| `--to=TO` | option | Whisper to this companion instead of speaking in party chat. |

```bash
APP_ENV=replay bin/console anm:talk Klemprime --say="Do we still need anything for Dry Times?"
```

#### `anm:ask`

Ask the archivist about a quest, an item, a creature, or where to find something.

```
anm:ask <question>
```

| | | |
|---|---|---|
| `<question>` | required | What you want to know. |

```bash
APP_ENV=replay bin/console anm:ask "Where do I find a Bottle of Moonshine?"
```

#### `anm:party`

Show a player's party and wake any members who are offline.

```
anm:party [--wake] [--] [<character>]
```

| | | |
|---|---|---|
| `<character>` | optional | Whose party to look at. |
| `--wake` | option | Log in any party members who are offline. |

```bash
APP_ENV=replay bin/console anm:party Klemprime
```

#### `anm:herd`

Bring a believable crowd into the world around a character.

```
anm:herd [--target TARGET] [--dismiss-beyond DISMISS-BEYOND] [--] [<character>]
```

| | | |
|---|---|---|
| `<character>` | optional | Who to gather a crowd around. |
| `--target=TARGET` | option | How many bots should be nearby. |
| `--dismiss-beyond=DISMISS-BEYOND` | option | Also send away bots further than this many yards. |

```bash
bin/console anm:herd Klemprime --target=50 --dismiss-beyond=400
```

### Running against a live server

#### `anm:listen`

Listen to game chat and let nearby bots reply in character.

```
anm:listen [--interval INTERVAL] [--backlog] [--explain] [--no-herding] [--save-every SAVE-EVERY]
```

| | | |
|---|---|---|
| `--interval=INTERVAL` | option | Milliseconds between polls of the chat log. Default: `1000`. |
| `--backlog` | option | Answer chat already in the log rather than starting from now. |
| `--explain` | option | Show the system prompt each reply was built from. |
| `--no-herding` | option | Do not populate the world; only answer chat. |
| `--save-every=SAVE-EVERY` | option | Seconds between full saves of the world when nobody is talking. Default: `5`. |

```bash
bin/console anm:listen --explain
```

#### `anm:banter`

Have a bot near the player mutter something, immediately.

```
anm:banter [--lines LINES]
```

| | | |
|---|---|---|
| `--lines=LINES` | option | How many lines to draw out. Default: `1`. |

```bash
bin/console anm:banter --lines=3
```

#### `anm:smoke`

Check every link to the game server end to end: SOAP, command server, chat capture, bot speech.

```
anm:smoke [--bot BOT] [--line LINE]
```

| | | |
|---|---|---|
| `--bot=BOT` | option | Bot that should speak. Defaults to the first online bot. |
| `--line=LINE` | option | Line the bot should say. Default: `The road to Astranaar is quiet tonight.` |

```bash
bin/console anm:smoke --bot=Anduril
```

### The MCP server

#### `mcp:server`

Starts an MCP server over STDIO.

```
mcp:server [<name>]
```

| | | |
|---|---|---|
| `<name>` | optional | Name of the server to run (`mcp.servers.<name>`). |

```bash
APP_ENV=replay bin/console mcp:server
```

### Recording a session

#### `anm:snapshot`

Capture a slice of the live world into fixtures/ for replay.

```
anm:snapshot [--radius RADIUS] [--chat-lines CHAT-LINES] [--since SINCE] [--] [<character>]
```

| | | |
|---|---|---|
| `<character>` | optional | Character the snapshot is centred on. |
| `--radius=RADIUS` | option | How far around them to capture, in yards. Default: `300`. |
| `--chat-lines=CHAT-LINES` | option | How many recent chat lines to include. Default: `60`. |
| `--since=SINCE` | option | Only chat from this moment on, e.g. "2026-11-16 20:00" or "-90 minutes". Captures a session rather than whatever was said most recently. |

```bash
bin/console anm:snapshot Klemprime --since="-90 minutes"
```
