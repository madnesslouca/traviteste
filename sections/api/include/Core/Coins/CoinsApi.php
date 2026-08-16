<?php

namespace Core\Coins;

use Core\IdentityRepository;
use Core\OAuth\ProviderFactory;
use RuntimeException;

/**
 * Server-to-server API for the watch-time system.
 *
 * This is the seam where an external service - the thing that knows who was
 * watching the stream and for how long - pays coins into the economy. It is
 * authenticated with a shared token, not with a user session, and every credit
 * is idempotent so a retried call cannot mint currency.
 */
class CoinsApi
{
    private $identities;

    public function __construct(IdentityRepository $identities = null)
    {
        $this->identities = $identities ?: new IdentityRepository();
    }

    /**
     * Constant-time check of the shared token.
     *
     * @throws RuntimeException when the token is missing, unset or wrong
     */
    public static function assertAuthorised()
    {
        $expected = getenv('COINS_API_TOKEN') ?: '';
        if ($expected === '') {
            //Refusing beats defaulting to open: an unset token would otherwise
            //leave coin minting exposed to anyone who found the URL.
            throw new RuntimeException('Coins API is disabled: set COINS_API_TOKEN in .env');
        }
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $given = preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) ? $m[1] : '';
        if ($given === '' || !hash_equals($expected, $given)) {
            throw new RuntimeException('Unauthorised.');
        }
    }

    /**
     * Credits coins to a provider account, creating the identity if this is the
     * first time we hear about them - someone can earn coins watching the stream
     * long before they ever open the game.
     *
     * @param array $payload provider, provider_user_id, amount, reference, [provider_login, reason]
     * @return array
     */
    public function credit(array $payload)
    {
        $provider = $this->requireProvider($payload);
        $providerUserId = $this->requireString($payload, 'provider_user_id');
        $reference = $this->requireString($payload, 'reference');
        $amount = isset($payload['amount']) ? (int)$payload['amount'] : 0;
        if ($amount <= 0) {
            throw new RuntimeException('"amount" must be a positive integer.');
        }
        $reason = isset($payload['reason']) && $payload['reason'] !== ''
            ? preg_replace('/[^a-z0-9_]/i', '', (string)$payload['reason'])
            : 'watch_time';

        //Null, not a fallback: a credit call that omits the login must leave the
        //stored username alone rather than replace it with the numeric id.
        $login = isset($payload['provider_login']) && $payload['provider_login'] !== ''
            ? (string)$payload['provider_login']
            : null;
        $identity = $this->identities->upsertIdentity($provider, [
            'id'    => $providerUserId,
            'login' => $login,
            'name'  => $login,
            'email' => null,
        ]);

        //False means the reference was already recorded, so this is a duplicate
        //delivery rather than a new credit. Reporting it as such lets the caller
        //retry freely.
        $applied = $this->identities->addCoins($identity['id'], $amount, $reason, $reference);

        return [
            'applied'   => $applied,
            'duplicate' => !$applied,
            'identity'  => [
                'provider' => $identity['provider'],
                'login'    => $identity['provider_login'],
            ],
            'balance'   => $this->identities->getBalance($identity['id']),
        ];
    }

    /** Current balance for a provider account. */
    public function balance(array $payload)
    {
        $provider = $this->requireProvider($payload);
        $providerUserId = $this->requireString($payload, 'provider_user_id');
        $identity = $this->identities->upsertIdentity($provider, [
            'id'    => $providerUserId,
            'login' => null,
            'name'  => null,
            'email' => null,
        ]);
        return [
            'balance' => $this->identities->getBalance($identity['id']),
        ];
    }

    private function requireProvider(array $payload)
    {
        $provider = isset($payload['provider']) ? strtolower(trim((string)$payload['provider'])) : '';
        if (!ProviderFactory::supports($provider)) {
            throw new RuntimeException('"provider" must be one of: ' . implode(', ', ProviderFactory::names()));
        }
        return $provider;
    }

    private function requireString(array $payload, $key)
    {
        if (!isset($payload[$key]) || trim((string)$payload[$key]) === '') {
            throw new RuntimeException("\"{$key}\" is required.");
        }
        return trim((string)$payload[$key]);
    }
}
