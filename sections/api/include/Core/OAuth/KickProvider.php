<?php

namespace Core\OAuth;

use RuntimeException;

/**
 * Kick OAuth 2.1, authorization code grant with mandatory PKCE (S256).
 *
 * https://docs.kick.com/getting-started/generating-tokens-oauth2-flow
 */
class KickProvider extends AbstractProvider
{
    public function getName()
    {
        return 'kick';
    }

    protected function getAuthorizeUrl()
    {
        return 'https://id.kick.com/oauth/authorize';
    }

    protected function getTokenUrl()
    {
        return 'https://id.kick.com/oauth/token';
    }

    protected function getScopes()
    {
        return ['user:read'];
    }

    public function requiresPkce()
    {
        return true;
    }

    protected function fetchProfile($accessToken)
    {
        //Called without an id, this returns the authorised user.
        $response = $this->request('GET', 'https://api.kick.com/public/v1/users', [], [
            'Authorization: Bearer ' . $accessToken,
        ]);
        $user = isset($response['data'][0]) ? $response['data'][0] : null;
        if (!$user || empty($user['user_id'])) {
            throw new RuntimeException('Kick returned no user profile.');
        }
        $login = isset($user['name']) && $user['name'] !== '' ? $user['name'] : (string)$user['user_id'];
        return [
            'id'     => (string)$user['user_id'],
            'login'  => $login,
            'name'   => $login,
            'email'  => isset($user['email']) && $user['email'] !== '' ? $user['email'] : null,
            'avatar' => isset($user['profile_picture']) ? $user['profile_picture'] : null,
        ];
    }
}
