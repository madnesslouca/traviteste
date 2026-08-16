-- Shop: turning watch-time coins into in-game gold.
--
-- The debit (coins, PostgreSQL) and the credit (gold, MariaDB) cannot share a
-- transaction, so every exchange is written down as an order and walked through
-- states. An order that dies half way is visible and can be settled, instead of
-- silently eating someone's coins.

CREATE SCHEMA IF NOT EXISTS identity;

-- ---------------------------------------------------------------- packages
-- Editable without touching code: this is the price list.
CREATE TABLE IF NOT EXISTS identity.shop_packages
(
    id          BIGSERIAL PRIMARY KEY,
    code        TEXT        NOT NULL UNIQUE,
    title       TEXT        NOT NULL,
    coin_cost   BIGINT      NOT NULL CHECK (coin_cost > 0),
    gold_amount INTEGER     NOT NULL CHECK (gold_amount > 0),
    sort_order  INTEGER     NOT NULL DEFAULT 0,
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO identity.shop_packages (code, title, coin_cost, gold_amount, sort_order)
VALUES ('starter', 'Starter pouch', 100, 10, 1),
       ('small', 'Small chest', 450, 50, 2),
       ('medium', 'Merchant crate', 850, 100, 3),
       ('large', 'War chest', 4000, 500, 4)
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------- orders
-- pending   coins debited, gold not yet credited
-- fulfilled gold credited in the game
-- refunded  gold could not be credited, coins given back
CREATE TABLE IF NOT EXISTS identity.shop_orders
(
    id              BIGSERIAL PRIMARY KEY,
    reference       TEXT        NOT NULL UNIQUE,
    identity_id     BIGINT      NOT NULL REFERENCES identity.oauth_identities (id) ON DELETE CASCADE,
    package_id      BIGINT      NOT NULL REFERENCES identity.shop_packages (id),
    world_unique_id INTEGER     NOT NULL,
    game_uid        INTEGER     NOT NULL,
    coin_cost       BIGINT      NOT NULL,
    gold_amount     INTEGER     NOT NULL,
    status          TEXT        NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'fulfilled', 'refunded')),
    failure_reason  TEXT        NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    settled_at      TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS shop_orders_identity_idx
    ON identity.shop_orders (identity_id, created_at DESC);

-- Anything still pending is an exchange that needs a human or a retry.
CREATE INDEX IF NOT EXISTS shop_orders_pending_idx
    ON identity.shop_orders (created_at)
    WHERE status = 'pending';
