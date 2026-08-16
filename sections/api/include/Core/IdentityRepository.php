<?php

namespace Core;

use Database\IdentityDB;
use PDO;

/**
 * Reads and writes the identity/economy side of the world (PostgreSQL).
 *
 * Nothing here touches the game database. The only link between the two is
 * game_accounts, which stores a world id and a game user id as plain integers.
 */
class IdentityRepository
{
    /** An OAuth round trip older than this is treated as abandoned. */
    const STATE_TTL_SECONDS = 900;

    private $db;

    public function __construct(PDO $db = null)
    {
        $this->db = $db ?: IdentityDB::getInstance();
    }

    // ------------------------------------------------------------ login flow

    /**
     * Opens an OAuth round trip and returns its state token.
     *
     * @param string      $provider
     * @param string|null $codeVerifier  PKCE verifier, for providers that need it
     * @param int|null    $worldUniqueId world the player picked before logging in
     * @return string
     */
    public function createState($provider, $codeVerifier = null, $worldUniqueId = null, $redirectTo = null)
    {
        $this->pruneStates();
        $state = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_states (state, provider, code_verifier, world_unique_id, redirect_to)
             VALUES (:state, :provider, :verifier, :world, :redirect)'
        );
        $stmt->execute([
            'state'    => $state,
            'provider' => $provider,
            'verifier' => $codeVerifier,
            'world'    => $worldUniqueId,
            'redirect' => $redirectTo,
        ]);
        return $state;
    }

    /**
     * Validates and consumes a state token. Single use: the row is deleted as it
     * is read, so a replayed callback finds nothing.
     *
     * @return array|false
     */
    public function consumeState($state, $provider)
    {
        $stmt = $this->db->prepare(
            'DELETE FROM oauth_states
             WHERE state = :state
               AND provider = :provider
               AND created_at > now() - (:ttl || \' seconds\')::interval
             RETURNING state, provider, code_verifier, world_unique_id, redirect_to'
        );
        $stmt->execute([
            'state'    => $state,
            'provider' => $provider,
            'ttl'      => self::STATE_TTL_SECONDS,
        ]);
        $row = $stmt->fetch();
        return $row ?: false;
    }

    public function pruneStates()
    {
        $stmt = $this->db->prepare(
            'DELETE FROM oauth_states WHERE created_at < now() - (:ttl || \' seconds\')::interval'
        );
        $stmt->execute(['ttl' => self::STATE_TTL_SECONDS]);
    }

    // ------------------------------------------------------------ identities

    /**
     * Inserts or refreshes the identity behind a provider profile.
     *
     * @param string $provider
     * @param array  $profile normalised profile from an OAuth provider
     * @return array the stored identity row
     */
    public function upsertIdentity($provider, array $profile)
    {
        //A caller that does not know the login (the coins API often does not)
        //passes null, and every nullable field is then left as it was. Writing
        //EXCLUDED straight through would let a bare credit call overwrite a real
        //username with the numeric provider id.
        $login = isset($profile['login']) && $profile['login'] !== '' ? $profile['login'] : null;
        $stmt = $this->db->prepare(
            'INSERT INTO oauth_identities
                 (provider, provider_user_id, provider_login, display_name, email, avatar_url, last_login_at)
             VALUES (:provider, :pid, COALESCE(:login_ins, :pid_fallback), :name, :email, :avatar, now())
             ON CONFLICT (provider, provider_user_id) DO UPDATE SET
                 provider_login = COALESCE(:login_upd, oauth_identities.provider_login),
                 display_name   = COALESCE(EXCLUDED.display_name, oauth_identities.display_name),
                 email          = COALESCE(EXCLUDED.email, oauth_identities.email),
                 avatar_url     = COALESCE(EXCLUDED.avatar_url, oauth_identities.avatar_url),
                 last_login_at  = now()
             RETURNING *'
        );
        $stmt->execute([
            'provider'     => $provider,
            'pid'          => $profile['id'],
            'pid_fallback' => $profile['id'],
            'login_ins'    => $login,
            'login_upd'    => $login,
            'name'         => isset($profile['name']) && $profile['name'] !== '' ? $profile['name'] : $login,
            'email'        => isset($profile['email']) ? $profile['email'] : null,
            'avatar'       => isset($profile['avatar']) ? $profile['avatar'] : null,
        ]);
        return $stmt->fetch();
    }

    // ---------------------------------------------------------- game accounts

    /**
     * The game account this identity owns in a given world, if any.
     *
     * @return array|false
     */
    public function findGameAccount($identityId, $worldUniqueId)
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM game_accounts WHERE identity_id = :id AND world_unique_id = :world'
        );
        $stmt->execute(['id' => $identityId, 'world' => $worldUniqueId]);
        $row = $stmt->fetch();
        return $row ?: false;
    }

    /**
     * Records which account an identity owns in a world.
     *
     * $gameUid may be null: the username is reserved as soon as registration
     * starts, but the game only assigns a user id once activate.php has run.
     */
    public function linkGameAccount($identityId, $worldUniqueId, $gameUid, $username)
    {
        $stmt = $this->db->prepare(
            'INSERT INTO game_accounts (identity_id, world_unique_id, game_uid, username)
             VALUES (:id, :world, :uid, :username)
             ON CONFLICT (identity_id, world_unique_id) DO UPDATE SET
                 game_uid = COALESCE(EXCLUDED.game_uid, game_accounts.game_uid),
                 username = EXCLUDED.username
             RETURNING *'
        );
        $stmt->execute([
            'id'       => $identityId,
            'world'    => $worldUniqueId,
            'uid'      => $gameUid,
            'username' => $username,
        ]);
        return $stmt->fetch();
    }

    /** True when the name is already reserved by someone else in this world. */
    public function isUsernameTaken($worldUniqueId, $username, $exceptIdentityId = null)
    {
        $sql = 'SELECT 1 FROM game_accounts
                WHERE world_unique_id = :world AND lower(username) = lower(:username)';
        $params = ['world' => $worldUniqueId, 'username' => $username];
        if ($exceptIdentityId !== null) {
            $sql .= ' AND identity_id <> :identity';
            $params['identity'] = $exceptIdentityId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    // ---------------------------------------------------------------- economy
    //
    // There is none here, on purpose. The stream bot owns the watch-time points:
    // it credits them, debits purchases and records redemptions, atomically and
    // in one place. A balance kept on this side would be a second answer to the
    // same question, and the two would drift.
    //
    // Balances come from Core\Bot\BotClient; what this side owns is the delivery
    // of gold, in Core\Shop\RedemptionConsumer.
}
