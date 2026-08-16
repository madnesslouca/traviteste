-- Identity and economy schema.
--
-- This lives in PostgreSQL, separate from the game's MariaDB. The game database
-- stays an internal detail of main_script; everything that is operated - who a
-- player is, how much watch time they have, what they can spend - lives here.
--
-- Safe to run against an existing database: everything is IF NOT EXISTS and
-- confined to the `identity` schema, so it will not collide with the tables of
-- an existing Twitch integration.

CREATE SCHEMA IF NOT EXISTS identity;

-- ---------------------------------------------------------------- identities
-- One row per (provider, provider account). A single person streaming on both
-- Twitch and Kick therefore has two rows, both able to point at the same
-- game account.
CREATE TABLE IF NOT EXISTS identity.oauth_identities
(
    id                BIGSERIAL PRIMARY KEY,
    provider          TEXT        NOT NULL CHECK (provider IN ('twitch', 'kick')),
    provider_user_id  TEXT        NOT NULL,
    provider_login    TEXT        NOT NULL,
    display_name      TEXT        NULL,
    email             TEXT        NULL,
    avatar_url        TEXT        NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_login_at     TIMESTAMPTZ NULL,
    UNIQUE (provider, provider_user_id)
);

-- ---------------------------------------------------------------- game links
-- Which game account an identity owns, per game world. worldUniqueId and uid
-- refer to the MariaDB side (main.gameServers.id and world.users.id); they are
-- deliberately plain integers, not foreign keys, because they live in another
-- engine.
-- game_uid is nullable on purpose. A brand new player is written here the
-- moment the username is reserved, but the game only mints the real user id
-- later, when activate.php runs. Until then the row is a pending link, resolved
-- by username on the next login.
CREATE TABLE IF NOT EXISTS identity.game_accounts
(
    id               BIGSERIAL PRIMARY KEY,
    identity_id      BIGINT      NOT NULL REFERENCES identity.oauth_identities (id) ON DELETE CASCADE,
    world_unique_id  INTEGER     NOT NULL,
    game_uid         INTEGER     NULL,
    username         TEXT        NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (identity_id, world_unique_id)
);

CREATE INDEX IF NOT EXISTS game_accounts_world_idx
    ON identity.game_accounts (world_unique_id);

-- One identity per game account, but only once the account actually exists.
CREATE UNIQUE INDEX IF NOT EXISTS game_accounts_world_uid_uniq
    ON identity.game_accounts (world_unique_id, game_uid)
    WHERE game_uid IS NOT NULL;

-- Usernames are unique per world in the game, so they must be here too:
-- this is what stops two identities racing for the same reserved name.
CREATE UNIQUE INDEX IF NOT EXISTS game_accounts_world_username_uniq
    ON identity.game_accounts (world_unique_id, lower(username));

-- ---------------------------------------------------------------- login flow
-- Short-lived rows guarding a single OAuth round trip: `state` defeats CSRF and
-- `code_verifier` is the PKCE secret Kick requires. Rows are consumed on
-- callback and swept by age.
CREATE TABLE IF NOT EXISTS identity.oauth_states
(
    state           TEXT PRIMARY KEY,
    provider        TEXT        NOT NULL CHECK (provider IN ('twitch', 'kick')),
    code_verifier   TEXT        NULL,
    world_unique_id INTEGER     NULL,
    redirect_to     TEXT        NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS oauth_states_created_idx
    ON identity.oauth_states (created_at);

-- ---------------------------------------------------------------- economy
-- Ledger, not a running total: every credit and debit is a row, and the balance
-- is their sum. This is what makes a market auditable - you can always answer
-- "where did these coins come from" without trusting a mutable counter.
CREATE TABLE IF NOT EXISTS identity.coin_ledger
(
    id           BIGSERIAL PRIMARY KEY,
    identity_id  BIGINT      NOT NULL REFERENCES identity.oauth_identities (id) ON DELETE CASCADE,
    amount       BIGINT      NOT NULL,
    reason       TEXT        NOT NULL,
    reference    TEXT        NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (amount <> 0)
);

CREATE INDEX IF NOT EXISTS coin_ledger_identity_idx
    ON identity.coin_ledger (identity_id);

-- An idempotency guard for watch-time credits: crediting the same stream minute
-- twice is the easiest way to accidentally mint currency.
CREATE UNIQUE INDEX IF NOT EXISTS coin_ledger_reference_uniq
    ON identity.coin_ledger (identity_id, reason, reference)
    WHERE reference IS NOT NULL;

CREATE OR REPLACE VIEW identity.coin_balances AS
SELECT i.id                                  AS identity_id,
       i.provider,
       i.provider_login,
       COALESCE(SUM(l.amount), 0)::BIGINT    AS balance
FROM identity.oauth_identities i
         LEFT JOIN identity.coin_ledger l ON l.identity_id = i.id
GROUP BY i.id, i.provider, i.provider_login;
