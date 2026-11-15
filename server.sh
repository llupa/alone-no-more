#!/usr/bin/env sh
set -eu

# Start, stop and check the AzerothCore worldserver this project talks to.
#
#   ./server.sh start | stop | restart | status
#
# It exists because starting it by hand went wrong in a way worth writing down.
# `worldserver` runs an interactive console on stdout, so backgrounding it with
# its output redirected to a file and nothing on stdin makes it print its "AC>"
# prompt in a tight loop. Left overnight that log reached 48 GB and took the
# disk to 87% full, which is where the server stopped.
#
# So: the console is off in worldserver.conf (we drive it over SOAP), stdin
# comes from /dev/null, and stdout goes to /dev/null too — the server writes its
# real log to Server.log through its own logger.

SERVER_DIR="${ANM_SERVER_DIR:-$HOME/wow/server}"
BIN="$SERVER_DIR/bin/worldserver"
CONF="$SERVER_DIR/etc/worldserver.conf"

running() {
  pgrep -f "worldserver -c" >/dev/null 2>&1
}

case "${1:-status}" in
  start)
    if running; then
      echo "worldserver already running (pid $(pgrep -f 'worldserver -c' | head -1))"
      exit 0
    fi

    [ -x "$BIN" ] || { echo "no worldserver at $BIN"; exit 1; }

    cd "$SERVER_DIR/bin"
    nohup ./worldserver -c "$CONF" >/dev/null 2>&1 </dev/null &
    echo "worldserver starting (pid $!) — world is up in about 15 seconds"
    ;;

  stop)
    running || { echo "worldserver was not running"; exit 0; }
    pkill -f "worldserver -c"
    echo "worldserver stopping — it saves everything on the way out"
    ;;

  restart)
    "$0" stop
    while running; do sleep 1; done
    "$0" start
    ;;

  status)
    if running; then
      echo "worldserver running (pid $(pgrep -f 'worldserver -c' | head -1))"
    else
      echo "worldserver not running"
    fi
    ;;

  *)
    echo "usage: $0 start|stop|restart|status" >&2
    exit 64
    ;;
esac
