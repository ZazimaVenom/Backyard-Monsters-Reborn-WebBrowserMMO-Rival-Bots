#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
PHP_BIN="${PHP_EXE:-php}"
"$PHP_BIN" -r 'if (version_compare(PHP_VERSION,"8.2.0","<") || !extension_loaded("pdo_mysql")) {fwrite(STDERR,"PHP 8.2+ with PDO MySQL is required.\n");exit(1);}'
"$PHP_BIN" bin/local-check.php port-free 8080
BMH_SERVER_PID=
BMH_WORKER_PID=
cleanup() {
  [[ -z "$BMH_WORKER_PID" ]] || kill "$BMH_WORKER_PID" 2>/dev/null || true
  [[ -z "$BMH_SERVER_PID" ]] || kill "$BMH_SERVER_PID" 2>/dev/null || true
  [[ -z "$BMH_WORKER_PID" ]] || wait "$BMH_WORKER_PID" 2>/dev/null || true
  [[ -z "$BMH_SERVER_PID" ]] || wait "$BMH_SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM
"$PHP_BIN" -S 127.0.0.1:8080 -t public &
BMH_SERVER_PID=$!
"$PHP_BIN" bin/local-check.php ready 8080
printf 'Backyard Monsters Rehatched: start MySQL first. Open http://127.0.0.1:8080/\n'
if [[ -f var/config.php ]]; then
  "$PHP_BIN" bin/worker.php &
  BMH_WORKER_PID=$!
else
  printf 'After setup.php finishes, enter the game. Bots run automatically while a player is online.\n'
fi
wait "$BMH_SERVER_PID"
