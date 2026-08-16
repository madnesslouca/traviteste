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

//Leave the world as we found it.
$serverDB->exec("UPDATE users SET bought_gold={$goldBefore} WHERE id={$testUid}");

echo "\n";
if ($failures) {
    echo "\033[0;31m{$failures} check(s) failed\033[0m\n";
    exit(1);
}
echo "\033[0;32mall checks passed\033[0m\n";
exit(0);
