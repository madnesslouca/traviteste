<?php

namespace Core\Shop;

use Core\IdentityRepository;
use Core\Server;
use Database\IdentityDB;
use Database\ServerDB;
use PDO;
use RuntimeException;

/**
 * Exchanges watch-time coins for in-game gold.
 *
 * The two sides live in different engines - coins in PostgreSQL, gold in the
 * world's MariaDB - so there is no transaction spanning both. Instead each
 * exchange is an order that moves pending -> fulfilled, or pending -> refunded
 * if the game could not be credited. Nothing is ever lost silently: an order
 * stuck in `pending` is a visible, replayable failure.
 */
class ShopService
{
    private $db;
    private $identities;

    public function __construct(PDO $db = null, IdentityRepository $identities = null)
    {
        $this->db = $db ?: IdentityDB::getInstance();
        $this->identities = $identities ?: new IdentityRepository($this->db);
    }

    /** @return array active packages, cheapest first */
    public function getPackages()
    {
        $stmt = $this->db->query(
            'SELECT id, code, title, coin_cost, gold_amount
             FROM shop_packages WHERE is_active ORDER BY sort_order, coin_cost'
        );
        return $stmt->fetchAll();
    }

    public function getPackageByCode($code)
    {
        $stmt = $this->db->prepare('SELECT * FROM shop_packages WHERE code = :code AND is_active');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Unknown package.');
        }
        return $row;
    }

    /** @return array the identity's recent exchanges */
    public function getOrders($identityId, $limit = 10)
    {
        $stmt = $this->db->prepare(
            'SELECT o.reference, o.coin_cost, o.gold_amount, o.status, o.created_at, p.title
             FROM shop_orders o
                      JOIN shop_packages p ON p.id = o.package_id
             WHERE o.identity_id = :id
             ORDER BY o.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('id', $identityId, PDO::PARAM_INT);
        $stmt->bindValue('limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Buys a package: debits coins, credits gold, settles the order.
     *
     * @param int    $identityId
     * @param string $packageCode
     * @param int    $worldUniqueId
     * @return array the settled order
     */
    public function purchase($identityId, $packageCode, $worldUniqueId)
    {
        $package = $this->getPackageByCode($packageCode);
        $server = Server::getServerById((int)$worldUniqueId);
        if (!$server) {
            throw new RuntimeException('Unknown game world.');
        }
        //Resolve rather than read: a player who registered a moment ago still
        //has a pending link, and the game only just gave them a user id.
        $resolver = new \Core\GameAccountResolver($this->identities);
        $account = $resolver->resolve($identityId, $server);
        if (!$account || $account['game_uid'] < 1) {
            throw new RuntimeException('You do not have a character in this game world yet.');
        }
        $gameUid = $account['game_uid'];

        $reference = 'order:' . bin2hex(random_bytes(16));

        //Debit and order creation share a transaction, so coins can never leave
        //the ledger without an order recording why.
        $this->db->beginTransaction();
        try {
            $balance = $this->identities->getBalance($identityId);
            if ($balance < $package['coin_cost']) {
                throw new RuntimeException(sprintf(
                    'Not enough coins: you have %d and this costs %d.',
                    $balance, $package['coin_cost']
                ));
            }
            $order = $this->createOrder($reference, $identityId, $package, $worldUniqueId, $gameUid);
            $this->identities->addCoins($identityId, -$package['coin_cost'], 'shop_purchase', $reference);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        //Past this point the coins are gone, so a failure has to be repaired
        //rather than thrown away.
        try {
            $this->creditGold($server, $gameUid, (int)$package['gold_amount'], $reference);
        } catch (\Throwable $e) {
            $this->refund($order, $identityId, $package, $e->getMessage());
            throw new RuntimeException(
                'The game world could not be credited, so your coins were returned. Please try again shortly.'
            );
        }

        return $this->markFulfilled($order);
    }

    /**
     * Settles orders left stranded in `pending`.
     *
     * An order is only stranded if the process died between debiting coins and
     * recording the outcome. Which side it died on is knowable, not guessable:
     * the game holds the order's reference if and only if the gold landed.
     *
     * @param int $olderThanSeconds grace period, so an in-flight purchase on
     *                              another request is never touched
     * @return array report: ['fulfilled' => [...], 'refunded' => [...], 'failed' => [...]]
     */
    public function reconcile($olderThanSeconds = 300)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM shop_orders
             WHERE status = 'pending'
               AND created_at < now() - (:age || ' seconds')::interval
             ORDER BY created_at"
        );
        $stmt->execute(['age' => $olderThanSeconds]);
        $stranded = $stmt->fetchAll();

        $report = ['fulfilled' => [], 'refunded' => [], 'failed' => []];
        foreach ($stranded as $order) {
            try {
                $server = Server::getServerById((int)$order['world_unique_id']);
                if (!$server) {
                    throw new RuntimeException("world {$order['world_unique_id']} no longer exists");
                }
                if ($this->wasGoldCredited($server, $order['reference'])) {
                    //Gold landed; only the bookkeeping was lost.
                    $this->markFulfilled($order);
                    $report['fulfilled'][] = $order['reference'];
                    continue;
                }
                //Gold never landed, so give the coins back.
                $package = ['coin_cost' => $order['coin_cost']];
                $this->refund($order, (int)$order['identity_id'], $package, 'reconciled: gold was never credited');
                $report['refunded'][] = $order['reference'];
            } catch (\Throwable $e) {
                //Leave it pending rather than settle it wrongly - a stuck order
                //is recoverable, a wrong one is not.
                $report['failed'][] = $order['reference'] . ': ' . $e->getMessage();
            }
        }
        return $report;
    }

    private function createOrder($reference, $identityId, array $package, $worldUniqueId, $gameUid)
    {
        $stmt = $this->db->prepare(
            'INSERT INTO shop_orders
                 (reference, identity_id, package_id, world_unique_id, game_uid, coin_cost, gold_amount)
             VALUES (:ref, :identity, :package, :world, :uid, :cost, :gold)
             RETURNING *'
        );
        $stmt->execute([
            'ref'      => $reference,
            'identity' => $identityId,
            'package'  => $package['id'],
            'world'    => $worldUniqueId,
            'uid'      => $gameUid,
            'cost'     => $package['coin_cost'],
            'gold'     => $package['gold_amount'],
        ]);
        return $stmt->fetch();
    }

    /**
     * Credits gold, exactly once.
     *
     * The reference is written into the game database in the same transaction as
     * the balance change, which does two things: replaying a credit becomes a
     * no-op, and an interrupted order stops being ambiguous - if the reference is
     * there, the player was paid.
     *
     * bought_gold is the transferable balance the game shows alongside gift_gold;
     * Session::getGold() is the sum of the two.
     */
    private function creditGold(array $server, $gameUid, $goldAmount, $reference)
    {
        $serverDB = ServerDB::getInstance($server['configFileLocation']);
        $serverDB->beginTransaction();
        try {
            //INSERT IGNORE rather than a prior SELECT: the unique key decides,
            //so two concurrent credits cannot both win.
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
                //Already credited by an earlier attempt; nothing left to do.
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

    /** True when the game already recorded this order's gold. */
    private function wasGoldCredited(array $server, $reference)
    {
        $serverDB = ServerDB::getInstance($server['configFileLocation']);
        $stmt = $serverDB->prepare('SELECT COUNT(*) FROM shop_gold_credits WHERE reference = :reference');
        $stmt->bindValue('reference', $reference, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    private function refund($order, $identityId, array $package, $reason)
    {
        $this->identities->addCoins(
            $identityId,
            (int)$package['coin_cost'],
            'shop_refund',
            $order['reference'] . ':refund'
        );
        $stmt = $this->db->prepare(
            "UPDATE shop_orders
             SET status = 'refunded', failure_reason = :reason, settled_at = now()
             WHERE id = :id"
        );
        $stmt->execute(['reason' => substr($reason, 0, 500), 'id' => $order['id']]);
    }

    private function markFulfilled($order)
    {
        $stmt = $this->db->prepare(
            "UPDATE shop_orders SET status = 'fulfilled', settled_at = now()
             WHERE id = :id RETURNING *"
        );
        $stmt->execute(['id' => $order['id']]);
        return $stmt->fetch();
    }
}
