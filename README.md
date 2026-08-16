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

Watch-time points belong to the stream bot. It credits them, debits purchases and
records redemptions — atomically, in one place. This side never holds a balance:
a second ledger would be a second answer to "how many points does this person
have", and the two would drift.

What this side owns is **delivery**: turning a redemption into in-game gold.

```
bot's web store  → purchase → redemption pending ─┐
                                                   ├→ this side credits gold,
in-game shop     → purchase → redemption pending ─┘   marks it fulfilled
```

Both routes end the same way, so there is one delivery path, not two. Buying
in-game delivers immediately; anything else is picked up by the consumer:

```bash
docker compose run --rm -T php php /travian/tools/deliver_redemptions.php
docker compose run --rm -T php php /travian/tools/deliver_redemptions.php --loop=30
```

Point it at your bot in `.env`:

```
BOT_API_URL=http://otakudotts-bot:8765
BOT_INTERNAL_KEY=...        # the bot's internal key, never sent to a browser
BOT_CHANNEL_NAME=yourchannel
```

### How much gold is an item worth

The bot's store items carry a name and a price in points, but no quantity, so the
payout lives here in `identity.gold_items`. Create the items in the bot's store as
usual, then map them:

```sql
INSERT INTO identity.gold_items (channel_name, bot_item_id, gold_amount, label)
VALUES ('yourchannel', 42, 50, 'Small chest');
```

Redemptions for items with no mapping are left alone — they are the streamer's own
rewards, not ours to deliver.

### Matching the player

A redemption is matched on `platform` + `platform_user_id`, never on the name. A
Twitch login that changes hands must not collect the previous owner's gold, so a
redemption without a stable id is queued as `unmatched` rather than guessed at.
Unmatched rows sit in `identity.redemption_deliveries` for a human to look at.

Gold is credited exactly once, guarded by a unique reference in the game database
— running the consumer twice, or concurrently, cannot pay twice.

```bash
docker compose run --rm -T php php /travian/tools/test_shop.php
docker compose run --rm -T php php /travian/tools/test_oauth.php
```

Those cover delivery, double-delivery, unmatched viewers, refusal to match by
name, and the sign-in flow (PKCE, single-use state, registration, handshake).

## Layout

```
main_script/        the game (MariaDB, mysqli)
sections/api/       lobby API, OAuth, shop (PostgreSQL, PDO)
  include/Core/OAuth/    providers, login flow, redirector
  include/Core/Bot/      client for the stream bot's internal store API
  include/Core/Shop/     redemption -> gold delivery
docker/             images and database bootstrap
tools/              install.sh, engine.php, deliver_redemptions.php, tests
```

Two databases, on purpose:

- **MariaDB** holds world state and stays an internal detail of the game.
- **PostgreSQL** holds identities, the gold-item mapping and the delivery record
  (not a coin balance — the bot owns that). It survives a world being wiped and
  reinstalled, and the schema is confined to `identity`, so
  it drops into a database that already holds other tables. Point `IDENTITY_DB_*`
  at your own instance and stop the bundled `identity_db` service.

The game engine (`tools/engine.php`) runs in the foreground rather than
daemonising, because the upstream `AutomationEngine.php` forks and exits the
parent — which kills PID 1 inside a container. **Nothing in the world advances
while it is down**: no buildings finish, no troops move.

---

## Known gaps

- The token exchange and profile parsing have not been exercised against live
  Twitch/Kick credentials. Everything after the profile is covered by tests.
- Redemption delivery is a command, not a scheduled job; nothing runs it for you
  unless you keep `--loop` running.
- The shop has no link from inside the game, and there is no logout.
- The admin panel's "install new server" flow assumes a VPS with systemd and
  Cloudflare DNS; it does not work under Docker. Use `tools/install.sh`.
- `main_script` and `main_script_dev` are divergent forks of the same code.

## Credits

The underlying game is the TravianT4.6 fan-made recreation. This is a fan project,
not part of any official Travian product.
