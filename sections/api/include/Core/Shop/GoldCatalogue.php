<?php

namespace Core\Shop;

use Core\Bot\BotClient;
use Database\IdentityDB;
use PDO;
use RuntimeException;

/**
 * The gold side of the bot's store.
 *
 * The bot's items carry a name and a price in points, but no notion of how much
 * gold they are worth - that mapping lives here, in identity.gold_items. This
 * class joins the two so the shop can show "450 pontos -> 50 gold" without the
 * bot needing to know the game exists.
 */
class GoldCatalogue
{
    private $db;
    private $bot;

    public function __construct(BotClient $bot = null, PDO $db = null)
    {
        $this->bot = $bot ?: new BotClient();
        $this->db = $db ?: IdentityDB::getInstance();
    }

    /**
     * Store items that pay out gold, with their price and amount.
     *
     * Items the bot has but we have no mapping for are left out: they are the
     * streamer's own rewards, not ours to sell.
     *
     * @return array of ['id','item_name','price','gold_amount','description']
     */
    public function listGoldItems()
    {
        $map = $this->goldMap();
        if (!$map) {
            return [];
        }
        $items = [];
        foreach ($this->bot->getItems() as $item) {
            $id = isset($item['id']) ? (int)$item['id'] : 0;
            if (!isset($map[$id])) {
                continue;
            }
            if (isset($item['enabled']) && !$item['enabled']) {
                continue;
            }
            $items[] = [
                'id'          => $id,
                'item_name'   => isset($item['item_name']) ? $item['item_name'] : ('Item ' . $id),
                'price'       => isset($item['price']) ? (int)$item['price'] : 0,
                'description' => isset($item['description']) ? $item['description'] : null,
                'gold_amount' => $map[$id],
            ];
        }
        usort($items, function ($a, $b) {
            return $a['price'] == $b['price'] ? 0 : ($a['price'] > $b['price'] ? 1 : -1);
        });
        return $items;
    }

    /** @throws RuntimeException when the item does not pay gold */
    public function goldFor($botItemId)
    {
        $map = $this->goldMap();
        if (!isset($map[(int)$botItemId])) {
            throw new RuntimeException('That item does not pay out gold.');
        }
        return $map[(int)$botItemId];
    }

    /** @return array bot_item_id => gold_amount */
    private function goldMap()
    {
        $stmt = $this->db->prepare(
            'SELECT bot_item_id, gold_amount FROM gold_items
             WHERE channel_name = :channel AND is_active'
        );
        $stmt->execute(['channel' => $this->bot->getChannel()]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['bot_item_id']] = (int)$row['gold_amount'];
        }
        return $map;
    }

    /** What this player has received recently, for the shop's history table. */
    public function recentDeliveries($identityId, $limit = 10)
    {
        $stmt = $this->db->prepare(
            'SELECT bot_redemption_id, gold_amount, status, detail, created_at
             FROM redemption_deliveries
             WHERE identity_id = :id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('id', $identityId, PDO::PARAM_INT);
        $stmt->bindValue('limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
