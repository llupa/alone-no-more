# Running against a real server

[Try it without a server](try-it.md) runs the whole application against a recorded world with
nothing installed. This is the other path: a live AzerothCore server, a 3.3.5a client, and companions
you can actually walk around with.

It is written to be followed literally, in order. Where a step has a reason that is not obvious,
the reason is given — those are the steps that get skipped and then cost an evening.

---

## 0. The server itself, which this project does not install

**Read this step even if you are in a hurry.** It is the one that goes wrong, and it goes wrong
in a way that only shows up an hour later at build time.

mod-playerbots does **not** build against upstream AzerothCore. It needs a fork, on a branch
that is not the default:

```bash
# The server. Note the organisation and the branch — not azerothcore/azerothcore-wotlk.
git clone -b Playerbot https://github.com/mod-playerbots/azerothcore-wotlk.git
cd azerothcore-wotlk

# The bots, as a module inside it.
git clone https://github.com/mod-playerbots/mod-playerbots.git modules/mod-playerbots
```

Known good here: AzerothCore `e7e2e28` (Playerbot branch) with mod-playerbots `405f811`, both
2026-11-05. If something fails to build, check you are on that fork and branch before anything
else.

From there, follow **[AzerothCore's own installation guide](https://www.azerothcore.org/wiki/installation)**
for your platform — building the core, creating the three databases (`acore_auth`,
`acore_world`, `acore_characters`), and **extracting the client data** (`dbc`, `maps`, `vmaps`,
`mmaps`) from a 3.3.5a client into the server's `data/` directory. That extraction takes a
while and is not optional: without `mmaps` the bots cannot path, which looks like our leading
being broken and is not.

We do not reproduce that guide here. It is long, platform-specific, and maintained by people
who keep it correct.

### You are ready for the next step when all of these are true

```bash
# the worldserver starts and reports the Playerbot branch
./worldserver --version 2>/dev/null | head -1     # or just watch it boot

# you can log in with the 3.3.5a client and create a character, and then — typed in game,
# not in the server console, because this particular command is in-game only:
.playerbots bot add <somebotname>
```

If you can log in, and summon one bot that follows you, the server is ready and nothing below
is about AzerothCore any more.

---

## 1. What you need on the application side

- **PHP 8.4 or newer**, with `pdo_mysql`, `soap`, `sockets`, `mbstring`, `iconv` and `ctype`.
  Check with `php -m`.
- **Composer**.
- The server from step 0, running.
- A **3.3.5a client** pointed at it.

This project stands beside AzerothCore. It does not install it, patch it, or require changes to
its source — only the one module in step 2, and four settings in step 3.

---

## 2. Add `mod-anm-bridge` and rebuild

Two things cannot be done from outside the worldserver: capturing chat, and making a bot say or
do something a chat command cannot express. `server/mod-anm-bridge` is a small C++ module that
does exactly those and nothing else.

Link it into your AzerothCore modules directory and rebuild:

On Linux or macOS:

```bash
ln -s /absolute/path/to/alone-no-more/server/mod-anm-bridge \
      /path/to/azerothcore/modules/mod-anm-bridge

cd /path/to/azerothcore/build
cmake ..                 # picks up the new module
make -j8 worldserver
make install
```

On Windows, from an elevated prompt (or copy the directory instead — you then have to re-copy
it whenever this repository changes):

```cmd
mklink /D "C:\path\to\azerothcore\modules\mod-anm-bridge" "C:\path\to\alone-no-more\server\mod-anm-bridge"
```

…then re-run CMake and rebuild the `worldserver` project in Visual Studio.

A link rather than a copy, so the module stays versioned with this repository and a rebuild
picks up changes without anyone remembering to copy files about.

It creates its own tables (`anm_chat_event`, `anm_outbox`, `anm_milestone`) in the characters
database on first start. There is no SQL to run.

See [the server module](the-server-module.md) for what it does and why each part exists.

---

## 3. Configure the worldserver

In `worldserver.conf`:

```ini
SOAP.Enabled = 1
SOAP.IP     = "127.0.0.1"
SOAP.Port   = 7878

# Leave the console off. The worldserver prints an "AC>" prompt on stdout, and if you start it
# backgrounded with output redirected and nothing on stdin it will do so in a tight loop. That
# log reached 48 GB here in one night and stopped the server by filling the disk.
Console.Enable = 0
```

In `modules/playerbots.conf`:

```ini
# The single most important setting for this project.
#
# With a prefix, mod-playerbots ignores any chat line that does not start with it — which is
# what lets players talk normally. Without it, "Do you have quests?" is parsed as the command
# "you have quests?" and answered with a red error, and the mere mention of a word like "cloth"
# or "fishing" opens a trade window.
Playerbots.CommandPrefix = "!"

# Off, for the same reason: it opens trades on substring matches against item and slot names.
Playerbots.EnableAutoTradeOnItemMention = 0

# We decide who is awake, so the mod should not.
Playerbots.RandomBotAutologin = 0
Playerbots.MaxRandomBots = 0
```

Optional, but what this machine runs:

```ini
GameType = 1                       # a PvP realm
Playerbots.RandomBotJoinLfg = 1    # bots queue for dungeons on their own, from level 15
```

---

## 4. Make a GM account for the application

The app drives the server's console over SOAP, which needs an account:

```
account create anm <a password you choose>
account set gmlevel anm 3 -1
```

Level 3 is what the console commands we use require. The password never goes in a committed
file — see the next step.

---

## 5. Configure the application

`.env` is committed and holds defaults only. Your values go in `.env.local`, which is
git-ignored:

```dotenv
# Only if yours differ from the committed defaults. 3306 is often taken already.
ANM_CHARACTERS_DSN=mysql://acore:acore@127.0.0.1:3306/acore_characters
ANM_WORLD_DSN=mysql://acore:acore@127.0.0.1:3306/acore_world

ANM_SOAP_ENDPOINT=http://127.0.0.1:7878/
ANM_SOAP_USERNAME=anm
ANM_SOAP_PASSWORD=<the password you chose>

# Zone and subzone names live in the client's data tables, not in the server database.
# Point this at your server's data directory. Without it, zone names fall back to the
# continent — everything still works, it is just less specific.
ANM_DBC_DIR=/path/to/your/server/data/dbc

# "anthropic" or "ollama". Ollama is the default so the project runs with no account at all,
# but companions are noticeably better on Anthropic.
ANM_AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
```

Then:

```bash
composer install
```

---

## 6. Check it before playing

```bash
bin/console anm:smoke
```

It tests each link end to end — SOAP reachable, both databases readable, chat capture working,
bot speech dispatched — and names whichever one is wrong. Run this before anything else; most
setup problems are one of those four and it will tell you which.

---

## 7. Run it

```bash
./server.sh start        # the worldserver, started so it cannot fill your disk
./listen.sh start        # the listener: herds the crowd, and answers chat
```

Then log in. Within a minute or so there should be people around you.

```bash
bin/console anm:world <your character>    # who is near you, and how the zone is populated
bin/console anm:herd <your character>     # bring a crowd now, without waiting
bin/console anm:party                     # your party, and wake any member who is offline
tail -f var/log/dev.log                   # what the application is doing
```

`./listen.sh stop` and `./server.sh stop` when you are done. The server saves on the way out.

---

## If something is wrong

| What you see | What it usually is |
|---|---|
| "No GM account configured" | `ANM_SOAP_USERNAME` / `ANM_SOAP_PASSWORD` missing from `.env.local` |
| `anm:smoke` fails on SOAP | `SOAP.Enabled = 1` not set, or the worldserver is not running |
| Companions never answer | the listener is not running, or `CommandPrefix` is not `"!"` |
| A bot answers with a red error in game | `CommandPrefix` is not set; the mod is parsing ordinary chat |
| A trade window keeps opening | `EnableAutoTradeOnItemMention` is not `0` |
| Nobody is ever near you | herding is off — `bin/console anm:world` shows the setting |
| Zone names are continents | `ANM_DBC_DIR` is not pointing at the client data tables |

The listener writes to `var/log/dev.log`, and every bot command it sends is in there. When a
companion does something surprising, that file usually says why.
