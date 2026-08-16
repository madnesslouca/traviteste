#!/usr/bin/env bash
#
# Reproducible local install for the Docker stack.
#
#   ./tools/install.sh            install onto the existing database
#   ./tools/install.sh --fresh    drop the database volume and start clean
#
# Every step is checked, so a failure stops here with a readable message
# instead of leaving a half-written world behind.
set -euo pipefail

# Git Bash on Windows rewrites Unix-looking arguments into Windows paths before
# they reach the container: /travian/... arrived as C:/Program Files/Git/travian.
# Both variables are ignored everywhere else.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

WORLD_ID="${TRAVIAN_WORLD_ID:-dev}"
ERROR_LOG="main_script/copyable/include/error_log.log"
FRESH=0
[ "${1:-}" = "--fresh" ] && FRESH=1

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[0;32mok\033[0m %s\n' "$*"; }
die()  { printf '\n\033[0;31m!! %s\033[0m\n' "$*" >&2; exit 1; }

compose() { docker compose "$@"; }

# ---------------------------------------------------------------- preflight
say "Checking prerequisites"
command -v docker >/dev/null 2>&1 || die "docker not found in PATH"
docker info >/dev/null 2>&1 || die "docker daemon is not running"
compose version >/dev/null 2>&1 || die "'docker compose' not available (needs Compose v2)"
ok "docker and compose available"

# The shipped log had grown past 600 MB, which slows every write and fills the
# disk. Keep the tail for reference and start over.
if [ -f "$ERROR_LOG" ]; then
    SIZE=$(wc -c < "$ERROR_LOG" | tr -d ' ')
    if [ "$SIZE" -gt 10485760 ]; then
        say "Rotating error log ($((SIZE / 1024 / 1024)) MB)"
        tail -n 500 "$ERROR_LOG" > "${ERROR_LOG}.tail" 2>/dev/null || true
        : > "$ERROR_LOG"
        ok "truncated, last 500 lines kept in ${ERROR_LOG}.tail"
    fi
fi

# ---------------------------------------------------------------- build
say "Building images"
compose build >/dev/null
ok "images built"

if [ "$FRESH" = "1" ]; then
    say "Removing existing containers and database volume"
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    ok "clean slate"
fi

# ---------------------------------------------------------------- database
if [ ! -f .env ] && [ -f .env.example ]; then
    printf '    \033[0;33mwarn\033[0m no .env found - Twitch/Kick sign-in will be unavailable until you copy .env.example to .env and fill it in\n'
fi

say "Starting database and cache"
compose up -d db redis identity_db >/dev/null

printf '    waiting for mysql'
for i in $(seq 1 60); do
    if compose exec -T db mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1; then
        printf '\n'; ok "database is up"; break
    fi
    printf '.'; sleep 2
    [ "$i" = "60" ] && { printf '\n'; die "database did not come up in time - check: docker compose logs db"; }
done

# The init script only runs on an empty data directory. If the schemas are not
# there, the volume predates this script and must be recreated.
TABLES=$(compose exec -T db mysql -uroot -proot -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema IN ('main','world')" 2>/dev/null | tr -d '\r')
if [ "${TABLES:-0}" -lt 100 ]; then
    die "schemas are missing (found ${TABLES:-0} tables). The database volume was created before this script - rerun with: ./tools/install.sh --fresh"
fi
ok "schemas present (${TABLES} tables)"

# ---------------------------------------------------------------- install
INSTALLED=$(compose exec -T db mysql -uroot -proot -N -B -e \
    "SELECT installed FROM world.config LIMIT 1" 2>/dev/null | tr -d '\r')

if [ "${INSTALLED:-0}" = "1" ]; then
    say "World already installed - skipping map generation"
    ok "use --fresh to reinstall from scratch"
else
    say "Installing world '${WORLD_ID}' (map generation, this takes a while)"
    MH_PASSWORD=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')
    compose run --rm -T php \
        php /travian/main_script/copyable/include/install.php "$WORLD_ID" "$MH_PASSWORD" \
        || die "installer failed - see the output above and $ERROR_LOG"
    echo "$MH_PASSWORD" > tools/.multihunter_password
    ok "installer finished (multihunter password saved to tools/.multihunter_password)"
fi

# ---------------------------------------------------------------- verify
say "Verifying installation"
check() {
    local label="$1" query="$2" min="$3"
    local value
    value=$(compose exec -T db mysql -uroot -proot -N -B -e "$query" 2>/dev/null | tr -d '\r')
    if [ -z "$value" ] || [ "$value" -lt "$min" ] 2>/dev/null; then
        die "$label: got '${value:-nothing}', expected at least $min - the install did not complete"
    fi
    ok "$label: $value"
}

check "world config installed" "SELECT installed FROM world.config LIMIT 1" 1
check "map tiles (wdata)"      "SELECT COUNT(*) FROM world.wdata" 1000
check "villages available"     "SELECT COUNT(*) FROM world.available_villages" 100
check "oases (odata)"          "SELECT COUNT(*) FROM world.odata" 10
check "system accounts"        "SELECT COUNT(*) FROM world.users" 3
check "game worlds listed"     "SELECT COUNT(*) FROM main.gameServers" 1

# Identity side lives in Postgres, so it is checked separately.
IDENTITY_TABLES=$(compose exec -T identity_db psql -U "${IDENTITY_DB_USER:-identity}" -d "${IDENTITY_DB_NAME:-identity}" -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='identity'" 2>/dev/null | tr -d '\r')
if [ "${IDENTITY_TABLES:-0}" -lt 4 ]; then
    die "identity schema is missing (found ${IDENTITY_TABLES:-0} tables) - rerun with --fresh"
fi
ok "identity schema: ${IDENTITY_TABLES} tables"

# Landscape is what used to be skipped because of the libpng crash.
LANDSCAPE=$(compose exec -T db mysql -uroot -proot -N -B -e \
    "SELECT COUNT(*) FROM world.wdata WHERE landscape > 0" 2>/dev/null | tr -d '\r')
if [ "${LANDSCAPE:-0}" -lt 100 ]; then
    printf '    \033[0;33mwarn\033[0m landscape tiles: %s (forests/lakes/hills may be missing)\n' "${LANDSCAPE:-0}"
else
    ok "landscape tiles: $LANDSCAPE"
fi

# ---------------------------------------------------------------- serve
say "Starting web server and game engine"
compose up -d php nginx engine >/dev/null
sleep 3

for svc in php nginx engine; do
    state=$(compose ps -q "$svc" | xargs -r docker inspect -f '{{.State.Status}}' 2>/dev/null || echo "missing")
    [ "$state" = "running" ] || die "service '$svc' is $state - check: docker compose logs $svc"
    ok "$svc running"
done

# Retry rather than probe once: when php has just been recreated nginx needs a
# DNS refresh cycle (resolver valid=10s) before it stops answering 502.
for path in / /login/; do
    HTTP="000"
    for i in $(seq 1 8); do
        HTTP=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080${path}" 2>/dev/null || echo "000")
        case "$HTTP" in 200|302) break ;; esac
        sleep 3
    done
    case "$HTTP" in
        200|302) ok "http://localhost:8080${path} responded $HTTP" ;;
        *) printf '    \033[0;33mwarn\033[0m http://localhost:8080%s responded %s - check: docker compose logs nginx php\n' "$path" "$HTTP" ;;
    esac
done

say "Done"
cat <<EOF
    Sign in   http://localhost:8080/login/     (Twitch / Kick)
    Shop      http://localhost:8080/shop/      (coins -> gold)
    Game      http://localhost:8080/

    Logs      docker compose logs -f engine
    Stop      docker compose down
    Reinstall ./tools/install.sh --fresh
EOF
