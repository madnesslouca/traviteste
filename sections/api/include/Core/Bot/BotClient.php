<?php

namespace Core\Bot;

use RuntimeException;

/**
 * Client for the stream bot's internal store API.
 *
 * The bot owns the watch-time points: it credits them, debits purchases and
 * records redemptions, all atomically on its side. This side never holds a coin
 * balance - it reads, it asks for a purchase, and it delivers what was redeemed.
 *
 * The internal key authenticates server-to-server calls and must never reach a
 * browser, so every method here is meant to be called from the backend only.
 */
class BotClient
{
    private $baseUrl;
    private $internalKey;
    private $channel;

    public function __construct($baseUrl = null, $internalKey = null, $channel = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: (getenv('BOT_API_URL') ?: ''), '/');
        $this->internalKey = $internalKey ?: (getenv('BOT_INTERNAL_KEY') ?: '');
        $this->channel = strtolower($channel ?: (getenv('BOT_CHANNEL_NAME') ?: ''));
    }

    public function isConfigured()
    {
        return $this->baseUrl !== '' && $this->internalKey !== '' && $this->channel !== '';
    }

    public function getChannel()
    {
        return $this->channel;
    }

    private function assertConfigured()
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Stream bot is not configured: set BOT_API_URL, BOT_INTERNAL_KEY and BOT_CHANNEL_NAME in .env'
            );
        }
    }

    /**
     * Balance for a viewer.
     *
     * Always sends platform + platform_user_id. The bot accepts a bare name for
     * chat commands, but that only finds a viewer it already knows - and a
     * recycled login must never see someone else's points.
     *
     * @return array ['points' => int, 'currency_name' => string]
     */
    public function getBalance($platform, $platformUserId, $userName)
    {
        $this->assertConfigured();
        $path = sprintf(
            '/api/store/balance/%s/%s?%s',
            rawurlencode($this->channel),
            rawurlencode(strtolower($userName)),
            http_build_query(['platform' => $platform, 'platform_user_id' => $platformUserId])
        );
        $data = $this->request('GET', $path);
        return [
            'points'        => isset($data['points']) ? (int)$data['points'] : 0,
            'currency_name' => isset($data['currency_name']) ? $data['currency_name'] : 'pontos',
        ];
    }

    /** @return array list of ['id','item_name','price','description','enabled'] */
    public function getItems()
    {
        $this->assertConfigured();
        $data = $this->request('GET', '/api/store/items/' . rawurlencode($this->channel));
        return isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    }

    /**
     * Buys an item on the viewer's behalf.
     *
     * The bot debits the points and records the redemption in one transaction,
     * so there is nothing to roll back here if the delivery later fails.
     *
     * @throws RuntimeException with the bot's own message on a refusal (400)
     */
    public function purchase($platform, $platformUserId, $userName, $itemId)
    {
        $this->assertConfigured();
        return $this->request('POST', '/api/store/purchase', [
            'channel_name'     => $this->channel,
            'user_name'        => strtolower($userName),
            'item_id'          => (int)$itemId,
            'platform'         => $platform,
            'platform_user_id' => (string)$platformUserId,
        ]);
    }

    /** @return array redemptions, each with viewer_id / platform / platform_user_id */
    public function listRedemptions($status = 'pending', $limit = 100)
    {
        $this->assertConfigured();
        $path = sprintf(
            '/api/store/redemptions/%s?%s',
            rawurlencode($this->channel),
            http_build_query(['status' => $status, 'limit' => (int)$limit])
        );
        $data = $this->request('GET', $path);
        return isset($data['redemptions']) && is_array($data['redemptions']) ? $data['redemptions'] : [];
    }

    /** Marks a redemption as handled. Valid: pending, fulfilled, cancelled. */
    public function setRedemptionStatus($redemptionId, $status)
    {
        $this->assertConfigured();
        $path = sprintf(
            '/api/store/redemptions/%s/%d',
            rawurlencode($this->channel),
            (int)$redemptionId
        );
        return $this->request('PATCH', $path, ['status' => $status]);
    }

    private function request($method, $path, array $body = null)
    {
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'x-internal-key: ' . $this->internalKey,
        ];
        $options = [
            CURLOPT_URL            => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Stream bot unreachable: ' . $error);
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            //The bot puts domain refusals ("not enough points") in `detail`,
            //and they are meant to be shown to the player as-is.
            $detail = is_array($decoded) && isset($decoded['detail'])
                ? $decoded['detail']
                : substr((string)$raw, 0, 200);
            throw new RuntimeException($detail);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
