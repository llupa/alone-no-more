#!/usr/bin/env bash
# Run the roleplay listener detached, so it keeps answering while you play.
#   ./listen.sh          start (from now, ignoring old chat)
#   ./listen.sh start    the same, for anyone who reaches for the obvious verb
#   ./listen.sh stop     stop
#   ./listen.sh restart  stop, then start
#   tail -f var/listen.log
cd "$(dirname "$0")"

stop_listener() {
  pkill -f "console anm:listen" 2>/dev/null || return 1
  # pkill returns before the process is gone; starting again immediately races with it and the
  # new instance refuses to start because it sees the dying one.
  for _ in $(seq 1 25); do
    pgrep -f "console anm:listen" >/dev/null || return 0
    sleep 0.2
  done
  pkill -9 -f "console anm:listen" 2>/dev/null
  return 0
}

if [ "${1:-}" = "stop" ]; then
  stop_listener && echo "listener stopped" || echo "listener was not running"
  exit 0
fi

# "start" is what everyone types. Without this it was passed through to the console command,
# which rejected it as an unexpected argument — after this script had already printed
# "listener started" and a pid, so it looked like it had worked.
if [ "${1:-}" = "start" ]; then
  shift
fi

if [ "${1:-}" = "restart" ]; then
  stop_listener >/dev/null 2>&1
  shift
fi

if pgrep -f "console anm:listen" >/dev/null; then
  echo "listener already running (pid $(pgrep -f 'console anm:listen' | head -1)) — use: ./listen.sh restart"
  exit 0
fi

mkdir -p var

# A long-running process holds the compiled container it booted with. Edit any service and
# Symfony rebuilds that container under a new hash, leaving this process requiring files that no
# longer exist — it then fails on every message while still logging the chat, which reads exactly
# like "the bot stopped answering". Clearing first makes a restart genuinely pick up the code.
php bin/console cache:clear --no-interaction >/dev/null 2>&1

nohup php bin/console anm:listen "$@" >> var/listen.log 2>&1 &
echo "listener started (pid $!) — tail -f var/listen.log"
