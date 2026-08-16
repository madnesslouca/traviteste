# TravOtaku

A Travian T4.6 server that players sign into with **Twitch or Kick**, and where the
in-game gold is bought with coins earned watching the stream.

Built on the TravianT4.6 fan-made recreation. Not affiliated with Travian Games GmbH.

---

## Running it

Requires Docker with Compose v2. Nothing else — PHP, MariaDB, Redis and PostgreSQL
all run in containers.

```bash
./tools/install.sh --fresh
```

The script builds the images, loads both schemas, generates the world map, starts
the game engine and then **verifies the result** — map tiles, villages, oases,
landscape, system accounts. It fails loudly instead of leaving a half-installed
world behind.

| | |
|---|---|
| Sign in | http://localhost:8080/login/ |
| Shop | http://localhost:8080/shop/ |
| Game | http://localhost:8080/ |

Drop `--fresh` to reinstall without destroying the database volume.

```bash
docker compose logs -f engine    # the game engine's workers
docker compose down              # stop everything
```

### On Windows

Run it from Git Bash. The script sets `MSYS_NO_PATHCONV`, without which Git Bash
rewrites container paths like `/travian/...` into `C:/Program Files/Git/travian/...`
before Docker ever sees them.

---

## Sign-in

Accounts are **OAuth only** — there is no password anywhere. Copy the sample
config and fill it in:

```bash
cp .env.example .env
```

Register an app with each provider and set the redirect URIs to match
`OAUTH_PUBLIC_URL` exactly:

| Provider | Console | Redirect URI |
|---|---|---|
| Twitch | https://dev.twitch.tv/console/apps | `<OAUTH_PUBLIC_URL>/v1/oauth/twitch/callback` |
| Kick | https://kick.com/settings/developer | `<OAUTH_PUBLIC_URL>/v1/oauth/kick/callback` |

Kick requires PKCE (S256); Twitch does not. Both are handled by the same provider
layer, with that difference isolated in `KickProvider`.

The game itself was not modified to support this: it already admits players
through a one-shot handshake rather than a password, so OAuth simply produces
that handshake.

---

## Coins and the shop

Coins live in PostgreSQL, gold lives in the game's MariaDB.

**Crediting coins** — this is where your watch-time system pays into the economy:

```bash
curl -X POST http://localhost:8080/v1/coins/credit \
  -H "Authorization: Bearer $COINS_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "provider": "twitch",
        "provider_user_id": "123456",
        "provider_login": "someviewer",
        "amount": 500,
        "reference": "watch:2026-08-09T13:00"
      }'
```

`reference` makes the call idempotent: replaying it returns `duplicate: true` and
credits nothing. Retry freely. An identity is created on first credit, so viewers
can bank coins long before they ever open the game.

`POST /v1/coins/balance` returns the current balance for a provider account.

**Spending them** happens at `/shop/`. Prices live in `identity.shop_packages` and
are edited with SQL, not code.

### Why exchanges are orders

The debit (PostgreSQL) and the credit (MariaDB) cannot share a transaction. Every
exchange is therefore written down as an order that moves `pending → fulfilled`,
or `pending → refunded` when the game cannot be credited — in which case the coins
are returned by a compensating ledger entry.

Balances are the **sum of a ledger**, never a mutable counter, so you can always
answer where a coin came from.

```bash
docker compose run --rm -T php php /travian/tools/test_shop.php
```

That exercises the refusal, purchase and refund paths against the real databases.

---

## Layout

```
main_script/        the game (MariaDB, mysqli)
sections/api/       lobby API, OAuth, shop (PostgreSQL, PDO)
  include/Core/OAuth/    providers, login flow, redirector
  include/Core/Shop/     coin -> gold exchange
  include/Core/Coins/    server-to-server coin API
docker/             images and database bootstrap
tools/              install.sh, engine.php, test_shop.php
```

Two databases, on purpose:

- **MariaDB** holds world state and stays an internal detail of the game.
- **PostgreSQL** holds identities, watch-time coins and the ledger. It survives a
  world being wiped and reinstalled, and the schema is confined to `identity`, so
  it drops into a database that already holds other tables. Point `IDENTITY_DB_*`
  at your own instance and stop the bundled `identity_db` service.

The game engine (`tools/engine.php`) runs in the foreground rather than
daemonising, because the upstream `AutomationEngine.php` forks and exits the
parent — which kills PID 1 inside a container. **Nothing in the world advances
while it is down**: no buildings finish, no troops move.

---

## Known gaps

- The OAuth flow has not been exercised against live Twitch/Kick credentials.
- Orders stranded in `pending` (a crash between debit and credit) have an index to
  find them but no reconciliation command yet.
- The shop has no link from inside the game, and there is no logout.
- The admin panel's "install new server" flow assumes a VPS with systemd and
  Cloudflare DNS; it does not work under Docker. Use `tools/install.sh`.
- `main_script` and `main_script_dev` are divergent forks of the same code.

## Credits

The underlying game is the TravianT4.6 fan-made recreation. This is a fan project,
not part of any official Travian product.
