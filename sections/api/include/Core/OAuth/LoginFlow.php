<?php

namespace Core\OAuth;

use Core\IdentityRepository;
use Core\LoginOperator;
use Core\Server;
use Database\ServerDB;
use PDO;
use RuntimeException;

/**
 * Turns a verified OAuth profile into a session in a game world.
 *
 * The game already lets a player in with a one-shot handshake rather than a
 * password, so nothing in main_script needs to change: we authenticate against
 * the provider, decide which game account the identity owns, and hand back the
 * URL the browser should follow.
 */
class LoginFlow
{
    private $identities;

    public function __construct(IdentityRepository $identities = null)
    {
        $this->identities = $identities ?: new IdentityRepository();
    }

    /**
     * @param string $provider
     * @param array  $profile       normalised provider profile
     * @param int    $worldUniqueId
     * @return array ['url' => string, 'identity_id' => int, 'world_unique_id' => int, 'game_uid' => int]
     *               game_uid is 0 while registration has not been completed
     */
    public function enter($provider, array $profile, $worldUniqueId)
    {
        $server = Server::getServerById((int)$worldUniqueId);
        if (!$server) {
            throw new RuntimeException('Unknown game world.');
        }
        if ($server['finished']) {
            throw new RuntimeException('This game world has finished.');
        }

        $identity = $this->identities->upsertIdentity($provider, $profile);
        $serverDB = ServerDB::getInstance($server['configFileLocation']);
        $resolver = new \Core\GameAccountResolver($this->identities);
        $existing = $resolver->resolve($identity['id'], $server);

        $context = [
            'identity_id'     => (int)$identity['id'],
            'world_unique_id' => (int)$server['id'],
            'game_uid'        => 0,
        ];

        if ($existing && $existing['game_uid'] > 0) {
            $context['game_uid'] = $existing['game_uid'];
            $context['url'] = $this->handshakeUrl($serverDB, $server, $existing['game_uid']);
            return $context;
        }

        //Either a brand new player, or one whose registration never finished:
        //in both cases send them through activation, reusing any name already
        //reserved for this identity.
        $username = $existing
            ? $existing['username']
            : $this->pickUsername($serverDB, $server['id'], $profile, $identity['id']);
        $context['url'] = $this->startRegistration($serverDB, $server, $identity, $username, $profile);
        return $context;
    }

    private function handshakeUrl(PDO $serverDB, array $server, $uid)
    {
        $loginHelper = new LoginOperator($serverDB);
        $handshake = $loginHelper->insertHandshake($uid, false);
        //ServerDB connects with PDO's default ERRMODE_SILENT, so a failed insert
        //returns quietly. Without this check the player would be handed a
        //handshake URL that the game rejects, with nothing logged anywhere.
        $stmt = $serverDB->prepare('SELECT COUNT(*) FROM login_handshake WHERE token = :token');
        $stmt->bindValue('token', sha1($handshake), PDO::PARAM_STR);
        $stmt->execute();
        if ((int)$stmt->fetchColumn() < 1) {
            throw new RuntimeException('Could not open a session in the game world. Please try again.');
        }
        return rtrim($server['gameWorldUrl'], '/') . '/login.php?detectLang&lowRes=0&handshake=' . $handshake;
    }

    private function startRegistration(PDO $serverDB, array $server, array $identity, $username, array $profile)
    {
        if ($server['registerClosed']) {
            throw new RuntimeException('Registration is closed for this game world.');
        }
        //Reserve the name before the game sees it, so two identities cannot race
        //onto the same username between here and activate.php.
        $this->identities->linkGameAccount($identity['id'], $server['id'], null, $username);

        //The game requires a password and an e-mail on the account. With OAuth
        //the password is never used - the player always arrives by handshake -
        //so it is random and thrown away.
        $password = bin2hex(random_bytes(24));
        $email = $this->emailFor($identity, $profile);
        $token = \Core\ActivateHandler::addActivation($username, $password, $email, 0, $serverDB);
        return rtrim($server['gameWorldUrl'], '/') . '/activate.php?token=' . $token . '&auto=1';
    }

    /**
     * Derives a game username from the provider login.
     *
     * The game only accepts 3-15 characters and rejects '@', so the provider
     * login is stripped down and, if it collides, given a numeric suffix.
     */
    private function pickUsername(PDO $serverDB, $worldUniqueId, array $profile, $identityId)
    {
        $base = preg_replace('/[^a-zA-Z0-9_]/', '', $profile['login']);
        if (strlen($base) < 3) {
            $base = 'player' . substr(preg_replace('/\D/', '', $profile['id']), 0, 6);
        }
        $base = substr($base, 0, 15);

        $candidate = $base;
        for ($i = 1; $i <= 50; $i++) {
            $taken = $this->identities->isUsernameTaken($worldUniqueId, $candidate, $identityId)
                || $this->existsInGame($serverDB, $candidate);
            if (!$taken) {
                return $candidate;
            }
            $suffix = (string)$i;
            $candidate = substr($base, 0, 15 - strlen($suffix)) . $suffix;
        }
        throw new RuntimeException('Could not derive a free username from "' . $profile['login'] . '".');
    }

    private function existsInGame(PDO $serverDB, $username)
    {
        $stmt = $serverDB->prepare(
            'SELECT (SELECT COUNT(id) FROM users WHERE name = :n1)
                  + (SELECT COUNT(id) FROM activation WHERE name = :n2)'
        );
        $stmt->bindValue('n1', $username, PDO::PARAM_STR);
        $stmt->bindValue('n2', $username, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Twitch and Kick both return a real address when the scope is granted. The
     * synthetic fallback exists only so the game's NOT NULL column is satisfied
     * when a provider withholds it; it is never mailed to.
     */
    private function emailFor(array $identity, array $profile)
    {
        if (!empty($profile['email'])) {
            return $profile['email'];
        }
        return sprintf('%s_%s@oauth.invalid', $identity['provider'], $identity['provider_user_id']);
    }
}
