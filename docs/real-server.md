# Running against a real server

The [README](../README.md) runs the whole application against a recorded world with nothing
installed. This is the other path: a live AzerothCore server, a 3.3.5a client, and companions
you can actually walk around with.

It is written to be followed literally, in order. Where a step has a reason that is not obvious,
the reason is given — those are the steps that get skipped and then cost an evening.

---

## 1. What you need first

- **PHP 8.4 or newer**, with `pdo_mysql`, `soap`, `sockets`, `mbstring`, `iconv` and `ctype`.
  Check with `php -m`.
- **Composer**.
- **AzerothCore** (wotlk, 3.3.5a) built and running, with its databases populated.
- **[mod-playerbots](https://github.com/mod-playerbots/azerothcore-wotlk)** installed into that
  server and working — you should be able to log in and summon a bot before adding anything of
  ours.
- A **3.3.5a client** pointed at your server.

This project does not install or patch AzerothCore. It stands beside one.

---

## 2. Add `mod-anm-bridge` and rebuild

Two things cannot be done from outside the worldserver: capturing chat, and making a bot say or
do something a chat command cannot express. `server/mod-anm-bridge` is a small C++ module that
does exactly those and nothing else.

Link it into your AzerothCore modules directory and rebuild:

```bash
ln -s /absolute/path/to/alone-no-more/server/mod-anm-bridge \
      /path/to/azerothcore/modules/mod-anm-bridge

cd /path/to/azerothcore/build
cmake ..                 # picks up the new module
make -j8 worldserver
make install
```

A symlink rather than a copy, so the module is versioned with this repository and a rebuild
picks up changes without copying files about.

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
