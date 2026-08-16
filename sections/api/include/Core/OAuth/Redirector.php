<?php

namespace Core\OAuth;

use Core\IdentityRepository;
use Core\Server;
use RuntimeException;

/**
 * The two browser-facing halves of the OAuth round trip.
 *
 * start()    picks a world, opens a state, sends the user to the provider
 * callback() verifies the state, exchanges the code, resolves the game account
 */
class Redirector
{
    private $identities;

    public function __construct(IdentityRepository $identities = null)
    {
        $this->identities = $identities ?: new IdentityRepository();
    }

    public static function loginUrl()
    {
        return rtrim(getenv('OAUTH_PUBLIC_URL') ?: 'http://localhost:8080', '/') . '/login/';
    }

    /**
     * @param string $providerName
     * @param int    $worldUniqueId 0 to fall back to the only open world
     * @return string provider authorization URL
     */
    public function start($providerName, $worldUniqueId)
    {
        $provider = ProviderFactory::make($this->normalise($providerName));
        $worldUniqueId = $this->resolveWorld($worldUniqueId);

        $codeVerifier = $provider->requiresPkce() ? AbstractProvider::generateCodeVerifier() : null;
        $state = $this->identities->createState($provider->getName(), $codeVerifier, $worldUniqueId);

        return $provider->getAuthorizationUrl($state, $codeVerifier);
    }

    /**
     * @return string URL in the game world to send the player to
     */
    public function callback($providerName, $code, $state, $providerError = null)
    {
        $name = $this->normalise($providerName);
        if (!empty($providerError)) {
            //The user pressed "cancel", or the provider refused the request.
            throw new RuntimeException($providerError);
        }
        if (empty($code) || empty($state)) {
            throw new RuntimeException('The sign-in response was incomplete. Please try again.');
        }

        //Consuming the state is what proves this callback belongs to a round
        //trip we started; it is single use, so a replayed URL fails here.
        $round = $this->identities->consumeState($state, $name);
        if (!$round) {
            throw new RuntimeException('This sign-in link has expired. Please try again.');
        }

        $provider = ProviderFactory::make($name);
        $profile = $provider->authenticate($code, $round['code_verifier']);

        $flow = new LoginFlow($this->identities);
        $context = $flow->enter($name, $profile, $round['world_unique_id']);

        //The shop lives outside the game and has no handshake to trust, so the
        //verified identity is carried in a session cookie from here.
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['identity_id'] = $context['identity_id'];
        $_SESSION['world_unique_id'] = $context['world_unique_id'];
        $_SESSION['game_uid'] = $context['game_uid'];

        return $context['url'];
    }

    /** The API bootstrap does not start a session; only these routes need one. */
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => strpos((string)getenv('OAUTH_PUBLIC_URL'), 'https://') === 0,
        ]);
        session_start();
    }

    /** @return int|null the signed-in identity, if any */
    public static function currentIdentityId()
    {
        self::startSession();
        return isset($_SESSION['identity_id']) ? (int)$_SESSION['identity_id'] : null;
    }

    public static function currentWorldId()
    {
        self::startSession();
        return isset($_SESSION['world_unique_id']) ? (int)$_SESSION['world_unique_id'] : null;
    }

    private function normalise($providerName)
    {
        $name = strtolower(trim((string)$providerName));
        if (!ProviderFactory::supports($name)) {
            throw new RuntimeException('Unknown sign-in provider.');
        }
        return $name;
    }

    /**
     * A world must be chosen before leaving for the provider, because the
     * callback carries nothing but the state token.
     */
    private function resolveWorld($worldUniqueId)
    {
        if ($worldUniqueId > 0) {
            $server = Server::getServerById($worldUniqueId);
            if (!$server) {
                throw new RuntimeException('Unknown game world.');
            }
            return (int)$server['id'];
        }
        $worlds = Server::getGameWorldsListForRegistration(true);
        if (sizeof($worlds) == 1) {
            return (int)$worlds[0]['id'];
        }
        throw new RuntimeException('Please choose a game world before signing in.');
    }
}
