<?php
/**
 * Settles shop orders stranded in `pending`.
 *
 * A purchase debits coins in PostgreSQL and credits gold in MariaDB, which
 * cannot share a transaction. If the process dies in between, the order stays
 * pending. This decides each one by asking the game whether it holds the order's
 * reference: if it does the gold landed and the order is fulfilled, if not the
 * coins are returned.
 *
 * Safe to run repeatedly, and safe to run while the shop is live - orders
 * younger than the grace period are left alone.
 *
 * Usage:
 *   docker compose run --rm -T php php /travian/tools/reconcile_orders.php [--age=300] [--dry-run]
 */
require "/travian/sections/api/include/bootstrap.php";

use Core\Shop\ShopService;
use Database\IdentityDB;

$age = 300;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--age=(\d+)$/', $arg, $m)) {
        $age = (int)$m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        exit("Unknown argument: {$arg}\nUsage: reconcile_orders.php [--age=SECONDS] [--dry-run]\n");
    }
}

$db = IdentityDB::getInstance();
$stmt = $db->prepare(
    "SELECT o.reference, o.coin_cost, o.gold_amount, o.world_unique_id, o.game_uid,
            o.created_at, i.provider, i.provider_login
     FROM shop_orders o
              JOIN oauth_identities i ON i.id = o.identity_id
     WHERE o.status = 'pending'
       AND o.created_at < now() - (:age || ' seconds')::interval
     ORDER BY o.created_at"
);
$stmt->execute(['age' => $age]);
$pending = $stmt->fetchAll();

if (!sizeof($pending)) {
    echo "No stranded orders older than {$age}s.\n";
    exit(0);
}

printf("%d stranded order(s) older than %ds:\n\n", sizeof($pending), $age);
foreach ($pending as $order) {
    printf("  %s  %s/%s  %d coins -> %d gold  (uid %d, world %d, %s)\n",
        $order['reference'],
        $order['provider'],
        $order['provider_login'],
        $order['coin_cost'],
        $order['gold_amount'],
        $order['game_uid'],
        $order['world_unique_id'],
        $order['created_at']);
}

if ($dryRun) {
    echo "\n--dry-run: nothing was changed.\n";
    exit(0);
}

echo "\nSettling...\n";
$shop = new ShopService();
$report = $shop->reconcile($age);

printf("\n  fulfilled (gold had landed): %d\n", sizeof($report['fulfilled']));
foreach ($report['fulfilled'] as $ref) {
    echo "    {$ref}\n";
}
printf("  refunded (gold never landed): %d\n", sizeof($report['refunded']));
foreach ($report['refunded'] as $ref) {
    echo "    {$ref}\n";
}
if (sizeof($report['failed'])) {
    printf("  left pending (could not decide): %d\n", sizeof($report['failed']));
    foreach ($report['failed'] as $line) {
        echo "    {$line}\n";
    }
    //Something needs a human; say so with a non-zero status.
    exit(1);
}
echo "\nDone.\n";
exit(0);
