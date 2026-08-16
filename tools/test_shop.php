<?php
/**
 * End-to-end check of the coin -> gold exchange.
 *
 * Exercises ShopService against the real databases, because the interesting
 * part of this code is precisely that it spans two of them: coins are debited
 * in PostgreSQL and gold credited in MariaDB, with no shared transaction.
 *
 * Usage: docker compose run --rm -T php php /travian/tools/test_shop.php
 */
require "/travian/sections/api/include/bootstrap.php";

use Core\IdentityRepository;
use Core\Shop\ShopService;
use Database\ServerDB;
use Core\Server;

$failures = 0;

function check($label, $actual, $expected)
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    printf("  %s %-46s got %s, expected %s\n",
        $ok ? "\033[0;32mok\033[0m  " : "\033[0;31mFAIL\033[0m",
        $label,
        var_export($actual, true),
        var_export($expected, true));
}

$identities = new IdentityRepository();
$shop = new ShopService();

//A throwaway identity, so repeated runs never collide.
$providerUserId = 'test_' . bin2hex(random_bytes(6));
$identity = $identities->upsertIdentity('twitch', [
    'id'    => $providerUserId,
    'login' => 'shoptester',
    'name'  => 'Shop Tester',
    'email' => 'shoptester@example.invalid',
]);
$identityId = (int)$identity['id'];

$worlds = Server::getGameWorldsListForRegistration(true);
if (!sizeof($worlds)) {
    exit("No open game world to test against.\n");
}
$worldId = (int)$worlds[0]['id'];
$server = Server::getServerById($worldId);
$serverDB = ServerDB::getInstance($server['configFileLocation']);

//uid 2 is the Multihunter account the installer always creates.
$testUid = 2;
$goldBefore = (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$testUid}")->fetchColumn();

echo "\nIdentity {$identityId}, world {$worldId}, uid {$testUid}, gold before {$goldBefore}\n\n";

echo "buying with no coins and no character\n";
try {
    $shop->purchase($identityId, 'starter', $worldId);
    check('refuses without a character', false, true);
} catch (Throwable $e) {
    check('refuses without a character', strpos($e->getMessage(), 'character') !== false, true);
}

$identities->linkGameAccount($identityId, $worldId, $testUid, 'shoptester' . substr($providerUserId, -4));

echo "\nbuying with an empty balance\n";
try {
    $shop->purchase($identityId, 'starter', $worldId);
    check('refuses without coins', false, true);
} catch (Throwable $e) {
    check('refuses without coins', strpos($e->getMessage(), 'Not enough coins') !== false, true);
}

echo "\ncrediting 1000 coins\n";
$identities->addCoins($identityId, 1000, 'test_grant', 'test:' . $providerUserId);
check('balance after credit', $identities->getBalance($identityId), 1000);

echo "\nbuying the starter package (100 coins -> 10 gold)\n";
$order = $shop->purchase($identityId, 'starter', $worldId);
check('order status', $order['status'], 'fulfilled');
check('coins debited', $identities->getBalance($identityId), 900);

$goldAfter = (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$testUid}")->fetchColumn();
check('gold credited in the game', $goldAfter - $goldBefore, 10);

echo "\nbuying something unaffordable\n";
try {
    $shop->purchase($identityId, 'large', $worldId);
    check('refuses when too expensive', false, true);
} catch (Throwable $e) {
    check('refuses when too expensive', strpos($e->getMessage(), 'Not enough coins') !== false, true);
}
check('balance untouched after refusal', $identities->getBalance($identityId), 900);

echo "\nrefund path: pointing the link at a player who does not exist\n";
$identities->linkGameAccount($identityId, $worldId, 999999, 'ghost' . substr($providerUserId, -4));
try {
    $shop->purchase($identityId, 'starter', $worldId);
    check('reports the failure', false, true);
} catch (Throwable $e) {
    check('reports the failure', strpos($e->getMessage(), 'coins were returned') !== false, true);
}
check('coins returned after a failed credit', $identities->getBalance($identityId), 900);

$refunded = $shop->getOrders($identityId, 5);
check('failed order recorded as refunded', $refunded[0]['status'], 'refunded');

// ------------------------------------------------------------- reconciliation
//Both halves of the ambiguous case: an order stranded in `pending` where the
//gold did land, and one where it did not. The game's credit ledger is what
//tells them apart.
echo "\nreconciling a stranded order whose gold DID land\n";
$identities->linkGameAccount($identityId, $worldId, $testUid, 'shoptester' . substr($providerUserId, -4));
$identities->addCoins($identityId, 1000, 'test_grant', 'test2:' . $providerUserId);

$db = \Database\IdentityDB::getInstance();
$landedRef = 'order:' . bin2hex(random_bytes(16));
$package = $shop->getPackageByCode('starter');
$db->prepare("INSERT INTO shop_orders (reference, identity_id, package_id, world_unique_id, game_uid, coin_cost, gold_amount, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, now() - interval '1 hour')")
   ->execute([$landedRef, $identityId, $package['id'], $worldId, $testUid, $package['coin_cost'], $package['gold_amount']]);
$identities->addCoins($identityId, -$package['coin_cost'], 'shop_purchase', $landedRef);
//Stand in for the crash: the gold was credited, the order never settled.
$serverDB->prepare("INSERT INTO shop_gold_credits (reference, uid, gold, time) VALUES (?, ?, ?, ?)")
         ->execute([$landedRef, $testUid, $package['gold_amount'], time()]);
$balanceBefore = $identities->getBalance($identityId);

$report = $shop->reconcile(60);
check('recognised as already paid', in_array($landedRef, $report['fulfilled'], true), true);
check('coins NOT returned (player was paid)', $identities->getBalance($identityId), $balanceBefore);

echo "\nreconciling a stranded order whose gold did NOT land\n";
$lostRef = 'order:' . bin2hex(random_bytes(16));
$db->prepare("INSERT INTO shop_orders (reference, identity_id, package_id, world_unique_id, game_uid, coin_cost, gold_amount, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, now() - interval '1 hour')")
   ->execute([$lostRef, $identityId, $package['id'], $worldId, $testUid, $package['coin_cost'], $package['gold_amount']]);
$identities->addCoins($identityId, -$package['coin_cost'], 'shop_purchase', $lostRef);
$balanceBefore = $identities->getBalance($identityId);

$report = $shop->reconcile(60);
check('recognised as unpaid', in_array($lostRef, $report['refunded'], true), true);
check('coins returned', $identities->getBalance($identityId), $balanceBefore + (int)$package['coin_cost']);

echo "\nrecent orders are left alone\n";
$freshRef = 'order:' . bin2hex(random_bytes(16));
$db->prepare("INSERT INTO shop_orders (reference, identity_id, package_id, world_unique_id, game_uid, coin_cost, gold_amount)
              VALUES (?, ?, ?, ?, ?, ?, ?)")
   ->execute([$freshRef, $identityId, $package['id'], $worldId, $testUid, $package['coin_cost'], $package['gold_amount']]);
$report = $shop->reconcile(300);
check('in-flight purchase untouched', in_array($freshRef, $report['fulfilled'], true)
    || in_array($freshRef, $report['refunded'], true), false);

echo "\ncrediting the same reference twice\n";
$doubleRef = 'order:' . bin2hex(random_bytes(16));
$goldNow = (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$testUid}")->fetchColumn();
$credit = new ReflectionMethod($shop, 'creditGold');
$credit->setAccessible(true);
$credit->invoke($shop, $server, $testUid, 25, $doubleRef);
$credit->invoke($shop, $server, $testUid, 25, $doubleRef);
$goldAfterDouble = (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$testUid}")->fetchColumn();
check('gold credited only once', $goldAfterDouble - $goldNow, 25);

//Leave both databases as we found them. Without this the throwaway identity
//keeps its link to (world, uid 2) and the next run collides on it.
echo "\ncleaning up\n";
$serverDB->exec("DELETE FROM shop_gold_credits WHERE uid={$testUid}");
$serverDB->exec("UPDATE users SET bought_gold={$goldBefore} WHERE id={$testUid}");
$db->prepare('DELETE FROM oauth_identities WHERE id = ?')->execute([$identityId]);
echo "  done\n";

echo "\n";
if ($failures) {
    echo "\033[0;31m{$failures} check(s) failed\033[0m\n";
    exit(1);
}
echo "\033[0;32mall checks passed\033[0m\n";
exit(0);
