-- Delivering the bot's redemptions as in-game gold.
--
-- There is deliberately no coin ledger here. The bot owns the points: it credits
-- watch time, it debits purchases, and it does both atomically. A second ledger
-- on this side would be a second answer to "how many coins does this person
-- have", and the two would drift.
--
-- What this side owns is the delivery: which redemption became how much gold,
-- for which player, and whether that already happened.

CREATE SCHEMA IF NOT EXISTS identity;

-- ---------------------------------------------------------------- gold items
-- The bot's store items have no quantity field - only id, name, price and
-- description - so the "how much gold is this worth" mapping lives here.
-- Prices in points stay in the bot's store, where the streamer already edits
-- them; this table only says what the item pays out in game.
CREATE TABLE IF NOT EXISTS identity.gold_items
(
    id           BIGSERIAL PRIMARY KEY,
    channel_name TEXT        NOT NULL,
    bot_item_id  INTEGER     NOT NULL,
    gold_amount  INTEGER     NOT NULL CHECK (gold_amount > 0),
    label        TEXT        NULL,
    is_active    BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (channel_name, bot_item_id)
);

-- ---------------------------------------------------------------- deliveries
-- One row per redemption we have looked at. `delivered` means the gold is in
-- the game and the bot was told; `unmatched` means we could not find a game
-- account for that viewer, which is a queue for a human rather than a failure
-- to retry forever.
CREATE TABLE IF NOT EXISTS identity.redemption_deliveries
(
    id                BIGSERIAL PRIMARY KEY,
    bot_redemption_id BIGINT      NOT NULL,
    channel_name      TEXT        NOT NULL,
    platform          TEXT        NULL,
    platform_user_id  TEXT        NULL,
    user_name         TEXT        NULL,
    bot_item_id       INTEGER     NOT NULL,
    gold_amount       INTEGER     NOT NULL,
    identity_id       BIGINT      NULL REFERENCES identity.oauth_identities (id) ON DELETE SET NULL,
    world_unique_id   INTEGER     NULL,
    game_uid          INTEGER     NULL,
    status            TEXT        NOT NULL DEFAULT 'delivered'
        CHECK (status IN ('delivered', 'unmatched', 'failed')),
    detail            TEXT        NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- A redemption is delivered at most once, no matter how often the consumer
    -- runs or how many run at the same time.
    UNIQUE (channel_name, bot_redemption_id)
);

CREATE INDEX IF NOT EXISTS redemption_deliveries_status_idx
    ON identity.redemption_deliveries (status, created_at);

-- ------------------------------------------------------------ retired tables
-- coin_ledger / coin_balances / shop_packages / shop_orders belonged to a design
-- where this side kept its own currency. The bot already does that, better and
-- atomically, so they are dropped rather than left behind for someone to wire
-- back up by mistake.
DROP VIEW IF EXISTS identity.coin_balances;
DROP TABLE IF EXISTS identity.shop_orders;
DROP TABLE IF EXISTS identity.shop_packages;
DROP TABLE IF EXISTS identity.coin_ledger;
