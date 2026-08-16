<?php

namespace Core\OAuth;

use RuntimeException;

/**
 * Builds a configured provider from environment variables.
 *
 * The redirect URI is derived from OAUTH_PUBLIC_URL rather than from the
 * incoming request: providers match it against what was registered, and reading
 * it off the request would let a spoofed Host header redirect the callback.
 */
class ProviderFactory
{
    private static $providers = [
        'twitch' => TwitchProvider::class,
        'kick'   => KickProvider::class,
    ];

    public static function names()
    {
        return array_keys(self::$providers);
    }

    public static function supports($name)
    {
        return isset(self::$providers[$name]);
    }

    /**
     * @param string $name
     * @return AbstractProvider
     */
    public static function make($name)
    {
        if (!self::supports($name)) {
            throw new RuntimeException("Unknown OAuth provider \"{$name}\".");
        }
        $class = self::$providers[$name];
        $prefix = strtoupper($name);
        $provider = new $class(
            getenv($prefix . '_CLIENT_ID') ?: '',
            getenv($prefix . '_CLIENT_SECRET') ?: '',
            self::redirectUri($name)
        );
        if (!$provider->isConfigured()) {
            throw new RuntimeException(
                "{$name} login is not configured: set {$prefix}_CLIENT_ID and {$prefix}_CLIENT_SECRET in .env"
            );
        }
        return $provider;
    }

    public static function redirectUri($name)
    {
        $base = rtrim(getenv('OAUTH_PUBLIC_URL') ?: 'http://localhost:8080', '/');
        return $base . '/v1/oauth/' . $name . '/callback';
    }

    /** Providers that actually have credentials, for rendering the login page. */
    public static function configured()
    {
        $available = [];
        foreach (self::names() as $name) {
            try {
                self::make($name);
                $available[] = $name;
            } catch (RuntimeException $e) {
                //Not configured, so it simply is not offered.
            }
        }
        return $available;
    }
}
