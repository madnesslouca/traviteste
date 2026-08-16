<?php

namespace Core\OAuth;

use RuntimeException;

/**
 * Shared authorization-code plumbing for the OAuth providers.
 *
 * Subclasses only describe the provider: its endpoints, scopes, how to read a
 * profile out of its response, and whether it demands PKCE.
 */
abstract class AbstractProvider
{
    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;

    public function __construct($clientId, $clientSecret, $redirectUri)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
    }

    /** Short name used in URLs and stored in oauth_identities.provider. */
    abstract public function getName();

    abstract protected function getAuthorizeUrl();

    abstract protected function getTokenUrl();

    abstract protected function getScopes();

    /** True when the provider requires PKCE (Kick does, Twitch does not). */
    abstract public function requiresPkce();

    /**
     * Normalises the provider's profile response into:
     * ['id' => string, 'login' => string, 'name' => string, 'email' => string|null, 'avatar' => string|null]
     */
    abstract protected function fetchProfile($accessToken);

    public function isConfigured()
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * URL to send the browser to. $codeVerifier is ignored unless the provider
     * uses PKCE, in which case its S256 challenge is derived here.
     */
    public function getAuthorizationUrl($state, $codeVerifier = null)
    {
        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $this->getScopes()),
            'state'         => $state,
        ];
        if ($this->requiresPkce()) {
            if (empty($codeVerifier)) {
                throw new RuntimeException($this->getName() . ' requires PKCE but no code verifier was given.');
            }
            $params['code_challenge'] = self::challengeFor($codeVerifier);
            $params['code_challenge_method'] = 'S256';
        }
        return $this->getAuthorizeUrl() . '?' . http_build_query($params);
    }

    /**
     * Exchanges the callback code for a profile.
     *
     * @return array normalised profile, see fetchProfile()
     */
    public function authenticate($code, $codeVerifier = null)
    {
        $body = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirectUri,
        ];
        if ($this->requiresPkce()) {
            $body['code_verifier'] = $codeVerifier;
        }
        $response = $this->request('POST', $this->getTokenUrl(), $body);
        if (empty($response['access_token'])) {
            throw new RuntimeException($this->getName() . ' did not return an access token.');
        }
        return $this->fetchProfile($response['access_token']);
    }

    /** PKCE verifier: 43-128 chars from the unreserved set. */
    public static function generateCodeVerifier()
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private static function challengeFor($codeVerifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $body    form-encoded for POST, ignored for GET
     * @param array  $headers extra request headers
     * @return array decoded JSON
     */
    protected function request($method, $url, array $body = [], array $headers = [])
    {
        $ch = curl_init();
        $headers[] = 'Accept: application/json';
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            //These are the defaults, but an OAuth token exchange is exactly the
            //place where silently skipping verification would be a disaster.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException($this->getName() . ' request failed: ' . $error);
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : substr($raw, 0, 200);
            throw new RuntimeException($this->getName() . " responded {$status}: {$detail}");
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($this->getName() . ' returned a malformed response.');
        }
        return $decoded;
    }
}
