<?php

namespace Core;

use Database\ServerDB;
use PDO;

/**
 * Answers "which game user id does this identity own in this world?".
 *
 * A link is written the moment a username is reserved, but the game only mints
 * the user id later, when activate.php runs. Both the login flow and the shop
 * need to close that gap, so the lookup lives here instead of in either of them.
 */
class GameAccountResolver
{
    private $identities;

    public function __construct(IdentityRepository $identities = null)
    {
        $this->identities = $identities ?: new IdentityRepository();
    }

    /**
     * @param int   $identityId
     * @param array $server row from Server::getServerById()
     * @return array|false ['game_uid' => int, 'username' => string], or false
     *                     when this identity has no link in the world at all
     */
    public function resolve($identityId, array $server)
    {
        $link = $this->identities->findGameAccount($identityId, (int)$server['id']);
        if (!$link) {
            return false;
        }
        if ($link['game_uid'] !== null) {
            return ['game_uid' => (int)$link['game_uid'], 'username' => $link['username']];
        }

        //Pending link: the game is the authority on whether the account exists.
        $serverDB = ServerDB::getInstance($server['configFileLocation']);
        $stmt = $serverDB->prepare('SELECT id FROM users WHERE name = :name LIMIT 1');
        $stmt->bindValue('name', $link['username'], PDO::PARAM_STR);
        $stmt->execute();
        $uid = $stmt->fetchColumn();

        if (!$uid) {
            return ['game_uid' => 0, 'username' => $link['username']];
        }
        $this->identities->linkGameAccount($identityId, (int)$server['id'], (int)$uid, $link['username']);
        return ['game_uid' => (int)$uid, 'username' => $link['username']];
    }
}
