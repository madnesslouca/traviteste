<?php
/**
 * Checks the delivery of the bot's redemptions as in-game gold.
 *
 * The bot is stubbed - this is not about whether it answers, but about what we
 * do with what it answers: matching the viewer by stable id, refusing to guess
 * by name, paying exactly once, and leaving a visible record when there is
 * nobody to pay.
 *
 * Usage: docker compose run --rm -T php php /travian/tools/test_shop.php
 */
require "/travian/sections/api/include/bootstrap.php";

use Core\Bot\BotClient;
use Core\IdentityRepository;
use Core\Server;
use Core\Shop\RedemptionConsumer;
use Database\IdentityDB;
use Database\ServerDB;

$failures = 0;

function check($label, $actual, $expected)
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    printf("  %s %-50s got %s, expected %s\n",
        $ok ? "\033[0;32mok\033[0m  " : "\033[0;31mFAIL\033[0m",
        $label, var_export($actual, true), var_export($expected, true));
}

/** Stands in for the stream bot, and records what we told it. */
class FakeBot extends BotClient
{
    public $redemptions = [];
    public $statusCalls = [];
    private $channel;

    public function __construct($channel = 'testchannel')
    {
        $this->channel = $channel;
    }

    public function isConfigured() { return true; }

    public function getChannel() { return $this->channel; }

    public function listRedemptions($status = 'pending', $limit = 100)
    {
        return $this->redemptions;
    }

    public function setRedemptionStatus($redemptionId, $status)
    {
        $this->statusCalls[] = [$redemptionId, $status];
        return ['success' => true];
    }
}

$db = IdentityDB::getInstance();
$identities = new IdentityRepository($db);
$suffix = bin2hex(random_bytes(4));
$channel = 'testchan' . $suffix;

$worlds = Server::getGameWorldsListForRegistration(true);
if (!sizeof($worlds)) {
    exit("No open game world to test against.\n");
}
$worldId = (int)$worlds[0]['id'];
$server = Server::getServerById($worldId);
$serverDB = ServerDB::getInstance($server['configFileLocation']);

//A throwaway player, so repeated runs never collide and no system account is
//ever touched.
$playerName = 'deliv' . $suffix;
$serverDB->exec(
    "INSERT INTO users (name, email, password, kid, race, desc1, desc2, note, signupTime)
     VALUES ('{$playerName}', '{$playerName}@example.invalid', 'x', 0, 1, '', '', '', " . time() . ")"
);
$playerUid = (int)$serverDB->lastInsertId();
if ($playerUid < 3) {
    exit("Refusing to run: test player got uid {$playerUid}, which collides with a system account.\n");
}

$identity = $identities->upsertIdentity('twitch', [
    'id'     => 'deliv_' . $suffix,
    'login'  => $playerName,
    'name'   => $playerName,
    'email'  => "{$playerName}@example.invalid",
    'avatar' => null,
]);
$identityId = (int)$identity['id'];
$identities->linkGameAccount($identityId, $worldId, $playerUid, $playerName);

//The gold this channel's item pays out.
$db->prepare('INSERT INTO gold_items (channel_name, bot_item_id, gold_amount, label) VALUES (?,?,?,?)')
   ->execute([$channel, 4242, 50, 'War chest']);

$goldBefore = (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$playerUid}")->fetchColumn();
echo "\nIdentity {$identityId}, world {$worldId}, uid {$playerUid}, gold before {$goldBefore}\n";

$bot = new FakeBot($channel);
$consumer = new RedemptionConsumer($bot, $db, $identities);

echo "\ndelivering a redemption for a linked viewer\n";
$bot->redemptions = [[
    'id' => 9001, 'channel_name' => $channel, 'user_name' => $playerName,
    'item_id' => 4242, 'item_name' => 'War chest', 'price_paid' => 4000,
    'status' => 'pending', 'platform' => 'twitch', 'platform_user_id' => 'deliv_' . $suffix,
]];
$report = $consumer->run();
check('reported as delivered', $report['delivered'], [9001]);
check('gold credited in the game',
    (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$playerUid}")->fetchColumn() - $goldBefore, 50);
check('bot was told it is fulfilled', $bot->statusCalls, [[9001, 'fulfilled']]);

echo "\nthe same redemption arriving again\n";
$bot->statusCalls = [];
$report = $consumer->run();
check('not paid twice',
    (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$playerUid}")->fetchColumn() - $goldBefore, 50);

echo "\na redemption whose viewer never linked a game account\n";
$bot->redemptions = [[
    'id' => 9002, 'channel_name' => $channel, 'user_name' => 'estranho' . $suffix,
    'item_id' => 4242, 'item_name' => 'War chest', 'price_paid' => 4000,
    'status' => 'pending', 'platform' => 'twitch', 'platform_user_id' => 'nobody_' . $suffix,
]];
$bot->statusCalls = [];
$report = $consumer->run();
check('queued as unmatched', $report['unmatched'], [9002]);
check('not marked fulfilled on the bot', $bot->statusCalls, []);
$row = $db->query("SELECT status FROM redemption_deliveries WHERE bot_redemption_id=9002")->fetch();
check('recorded for a human to look at', $row['status'], 'unmatched');

echo "\na redemption carrying no stable id\n";
//This is the whole point of the bot's identity work: matching on the name here
//would let a recycled login collect someone else's gold.
$bot->redemptions = [[
    'id' => 9003, 'channel_name' => $channel, 'user_name' => $playerName,
    'item_id' => 4242, 'item_name' => 'War chest', 'price_paid' => 4000,
    'status' => 'pending', 'platform' => null, 'platform_user_id' => null,
]];
$bot->statusCalls = [];
$report = $consumer->run();
check('refuses to match by name', $report['unmatched'], [9003]);
check('no extra gold paid',
    (int)$serverDB->query("SELECT bought_gold FROM users WHERE id={$playerUid}")->fetchColumn() - $goldBefore, 50);

echo "\na redemption for something that is not gold\n";
$bot->redemptions = [[
    'id' => 9004, 'channel_name' => $channel, 'user_name' => $playerName,
    'item_id' => 7777, 'item_name' => 'Shout-out', 'price_paid' => 100,
    'status' => 'pending', 'platform' => 'twitch', 'platform_user_id' => 'deliv_' . $suffix,
]];
$bot->statusCalls = [];
$report = $consumer->run();
check('left for the streamer', $report['skipped'], [9004]);
check('bot untouched', $bot->statusCalls, []);

echo "\ncleaning up\n";
$serverDB->exec("DELETE FROM shop_gold_credits WHERE uid={$playerUid}");
$serverDB->exec("DELETE FROM users WHERE name = '{$playerName}'");
$db->prepare('DELETE FROM redemption_deliveries WHERE channel_name = ?')->execute([$channel]);
$db->prepare('DELETE FROM gold_items WHERE channel_name = ?')->execute([$channel]);
$db->prepare('DELETE FROM oauth_identities WHERE id = ?')->execute([$identityId]);
echo "  done\n\n";

if ($failures) {
    echo "\033[0;31m{$failures} check(s) failed\033[0m\n";
    exit(1);
}
echo "\033[0;32mall checks passed\033[0m\n";
exit(0);
