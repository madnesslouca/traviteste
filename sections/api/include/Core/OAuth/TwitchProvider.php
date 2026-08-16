<?php

namespace Core\OAuth;

use RuntimeException;

/**
 * Twitch OAuth 2.0, authorization code grant.
 *
 * https://dev.twitch.tv/docs/authentication/
 */
class TwitchProvider extends AbstractProvider
{
    public function getName()
    {
        return 'twitch';
    }

    protected function getAuthorizeUrl()
    {
        return 'https://id.twitch.tv/oauth2/authorize';
    }

    protected function getTokenUrl()
    {
        return 'https://id.twitch.tv/oauth2/token';
    }

    protected function getScopes()
    {
        //user:read:email is what makes helix/users return the address; without
        //it we would have to invent a synthetic e-mail for the game tables.
        return ['user:read:email'];
    }

    public function requiresPkce()
    {
        return false;
    }

    protected function fetchProfile($accessToken)
    {
        $response = $this->request('GET', 'https://api.twitch.tv/helix/users', [], [
            'Authorization: Bearer ' . $accessToken,
            'Client-Id: ' . $this->clientId,
        ]);
        if (empty($response['data'][0]['id'])) {
            throw new RuntimeException('Twitch returned no user profile.');
        }
        $user = $response['data'][0];
        return [
            'id'     => (string)$user['id'],
            'login'  => isset($user['login']) ? $user['login'] : (string)$user['id'],
            'name'   => isset($user['display_name']) ? $user['display_name'] : $user['login'],
            'email'  => isset($user['email']) && $user['email'] !== '' ? $user['email'] : null,
            'avatar' => isset($user['profile_image_url']) ? $user['profile_image_url'] : null,
        ];
    }
}
