<?php

namespace Core\Shop;

use Core\Bot\BotClient;
use Core\GameAccountResolver;
use Core\IdentityRepository;
use Core\Server;
use Database\IdentityDB;
use Database\ServerDB;
use PDO;
use RuntimeException;

/**
 * Turns the bot's redemptions into in-game gold.
 *
 * This is the single delivery path, whichever way the purchase started: from the
 * bot's own web store, or from inside the game. Both end as a `pending`
 * redemption on the bot, and both are settled here.
 *
 * Ordering matters. The gold is credited first and the bot is told afterwards,
 * because a redemption marked fulfilled without gold is invisible - nobody would
 * ever look at it again - while one still pending after the gold landed is
 * caught on the next run by the local delivery record.
 */
class RedemptionConsumer
{
    private $db;
    private $bot;
    private $identities;

    public function __construct(BotClient $bot = null, PDO $db = null, IdentityRepository $identities = null)
    {
        $this->db = $db ?: IdentityDB::getInstance();
        $this->bot = $bot ?: new BotClient();
        $this->identities = $identities ?: new IdentityRepository($this->db);
    }

    /**
     * @param int $limit how many redemptions to pull in one pass
     * @return array ['delivered' => [...], 'unmatched' => [...], 'skipped' => [...], 'failed' => [...]]
     */
    public function run($limit = 100)
    {
        $report = ['delivered' => [], 'unmatched' => [], 'skipped' => [], 'failed' => []];
        $goldItems = $this->getGoldItems();
        if (!$goldItems) {
            return $report;
        }

        foreach ($this->bot->listRedemptions('pending', $limit) as $redemption) {
            $id = isset($redemption['id']) ? (int)$redemption['id'] : 0;
            $itemId = isset($redemption['item_id']) ? (int)$redemption['item_id'] : 0;
            if (!$id) {
                continue;
            }
            //Redemptions for anything else belong to the streamer, not to us.
            if (!isset($goldItems[$itemId])) {
                $report['skipped'][] = $id;
                continue;
            }
            try {
                $outcome = $this->deliver($redemption, (int)$goldItems[$itemId]);
                $report[$outcome][] = $id;
            } catch (\Throwable $e) {
                $this->record($redemption, $goldItems[$itemId], null, null, null, 'failed', $e->getMessage());
                $report['failed'][] = $id . ': ' . $e->getMessage();
            }
        }
        return $report;
    }

    /** @return string one of delivered|unmatched */
    private function deliver(array $redemption, $goldAmount)
    {
        $redemptionId = (int)$redemption['id'];
        $platform = isset($redemption['platform']) ? $redemption['platform'] : null;
        $platformUserId = isset($redemption['platform_user_id']) ? (string)$redemption['platform_user_id'] : '';
        $userName = isset($redemption['user_name']) ? $redemption['user_name'] : null;

        //Match on the stable id only. Falling back to the name here would undo
        //the whole point of the bot's identity work: a recycled login would
        //collect someone else's gold.
        $identity = $platform && $platformUserId !== ''
            ? $this->findIdentity($platform, $platformUserId)
            : null;

        if (!$identity) {
            $this->record($redemption, $goldAmount, null, null, null, 'unmatched',
                $platformUserId === ''
                    ? 'redemption carries no platform_user_id'
                    : 'no game account linked to this viewer');
            return 'unmatched';
        }

        $account = $this->findGameAccount((int)$identity['id']);
        if (!$account) {
            $this->record($redemption, $goldAmount, (int)$identity['id'], null, null, 'unmatched',
                'viewer has not signed into a game world yet');
            return 'unmatched';
        }

        $this->creditGold(
            $account['server'],
            $account['game_uid'],
            $goldAmount,
            'redemption:' . $this->bot->getChannel() . ':' . $redemptionId
        );

        $this->record($redemption, $goldAmount, (int)$identity['id'],
            (int)$account['server']['id'], $account['game_uid'], 'delivered', null);

        //Gold is in. If this call fails the delivery record already exists, so
        //the next run will not pay twice - it will just try to close it again.
        $this->bot->setRedemptionStatus($redemptionId, 'fulfilled');
        return 'delivered';
    }

    /** @return array|null */
    private function findIdentity($platform, $platformUserId)
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM oauth_identities WHERE provider = :provider AND provider_user_id = :pid'
        );
        $stmt->execute(['provider' => strtolower($platform), 'pid' => $platformUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * The world this viewer plays in, with the game user id resolved.
     *
     * @return array|null ['server' => array, 'game_uid' => int]
     */
    private function findGameAccount($identityId)
    {
        $stmt = $this->db->prepare(
            'SELECT world_unique_id FROM game_accounts WHERE identity_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute(['id' => $identityId]);
        $resolver = new GameAccountResolver($this->identities);
        foreach ($stmt->fetchAll() as $row) {
            $server = Server::getServerById((int)$row['world_unique_id']);
            if (!$server) {
                continue;
            }
            $account = $resolver->resolve($identityId, $server);
            if ($account && $account['game_uid'] > 0) {
                return ['server' => $server, 'game_uid' => $account['game_uid']];
            }
        }
        return null;
    }

    /**
     * Credits gold exactly once, guarded by the reference in the game database.
     * Same mechanism the in-game shop uses, so a redemption delivered by either
     * path can never pay twice.
     */
    private function creditGold(array $server, $gameUid, $goldAmount, $reference)
    {
        $serverDB = ServerDB::getInstance($server['configFileLocation']);
        $serverDB->beginTransaction();
        try {
            $stmt = $serverDB->prepare(
                'INSERT IGNORE INTO shop_gold_credits (reference, uid, gold, time)
                 VALUES (:reference, :uid, :gold, :time)'
            );
            $stmt->bindValue('reference', $reference, PDO::PARAM_STR);
            $stmt->bindValue('uid', $gameUid, PDO::PARAM_INT);
            $stmt->bindValue('gold', $goldAmount, PDO::PARAM_INT);
            $stmt->bindValue('time', time(), PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() < 1) {
                $serverDB->commit();
                return;
            }
            $stmt = $serverDB->prepare('UPDATE users SET bought_gold = bought_gold + :gold WHERE id = :uid');
            $stmt->bindValue('gold', $goldAmount, PDO::PARAM_INT);
            $stmt->bindValue('uid', $gameUid, PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException("No such player (uid {$gameUid}) in world {$server['worldId']}.");
            }
            $serverDB->commit();
        } catch (\Throwable $e) {
            $serverDB->rollBack();
            throw $e;
        }
    }

    private function record(array $redemption, $goldAmount, $identityId, $worldId, $gameUid, $status, $detail)
    {
        $stmt = $this->db->prepare(
            'INSERT INTO redemption_deliveries
                 (bot_redemption_id, channel_name, platform, platform_user_id, user_name,
                  bot_item_id, gold_amount, identity_id, world_unique_id, game_uid, status, detail)
             VALUES (:rid, :channel, :platform, :pid, :user, :item, :gold, :identity, :world, :uid, :status, :detail)
             ON CONFLICT (channel_name, bot_redemption_id) DO UPDATE SET
                 status = EXCLUDED.status,
                 detail = EXCLUDED.detail'
        );
        $stmt->execute([
            'rid'      => (int)$redemption['id'],
            'channel'  => $this->bot->getChannel(),
            'platform' => isset($redemption['platform']) ? $redemption['platform'] : null,
            'pid'      => isset($redemption['platform_user_id']) ? (string)$redemption['platform_user_id'] : null,
            'user'     => isset($redemption['user_name']) ? $redemption['user_name'] : null,
            'item'     => (int)$redemption['item_id'],
            'gold'     => (int)$goldAmount,
            'identity' => $identityId,
            'world'    => $worldId,
            'uid'      => $gameUid,
            'status'   => $status,
            'detail'   => $detail === null ? null : substr($detail, 0, 500),
        ]);
    }

    /** @return array bot_item_id => gold_amount */
    private function getGoldItems()
    {
        $stmt = $this->db->prepare(
            'SELECT bot_item_id, gold_amount FROM gold_items WHERE channel_name = :channel AND is_active'
        );
        $stmt->execute(['channel' => $this->bot->getChannel()]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['bot_item_id']] = (int)$row['gold_amount'];
        }
        return $map;
    }
}
