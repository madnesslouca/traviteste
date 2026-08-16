<?php
/**
 * Delivers the stream bot's redemptions as in-game gold.
 *
 * This is the single delivery path: a purchase made in the bot's own web store
 * and one made inside the game both end as a `pending` redemption, and both are
 * settled here. Buying in-game also runs this immediately, so this command is
 * what catches everything else.
 *
 * Safe to run repeatedly and concurrently: a redemption is credited at most once,
 * guarded by a unique reference in the game database.
 *
 * Usage:
 *   docker compose run --rm -T php php /travian/tools/deliver_redemptions.php [--limit=100] [--loop=SECONDS]
 */
require "/travian/sections/api/include/bootstrap.php";

use Core\Bot\BotClient;
use Core\Shop\RedemptionConsumer;

$limit = 100;
$loop = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int)$m[1]);
    } elseif (preg_match('/^--loop=(\d+)$/', $arg, $m)) {
        $loop = max(5, (int)$m[1]);
    } else {
        exit("Unknown argument: {$arg}\nUsage: deliver_redemptions.php [--limit=N] [--loop=SECONDS]\n");
    }
}

$bot = new BotClient();
if (!$bot->isConfigured()) {
    fwrite(STDERR, "Stream bot is not configured: set BOT_API_URL, BOT_INTERNAL_KEY and BOT_CHANNEL_NAME in .env\n");
    exit(1);
}

function deliver_once(BotClient $bot, $limit)
{
    $consumer = new RedemptionConsumer($bot);
    $report = $consumer->run($limit);

    $stamp = date('Y-m-d H:i:s');
    $counts = sprintf(
        'delivered=%d unmatched=%d skipped=%d failed=%d',
        sizeof($report['delivered']), sizeof($report['unmatched']),
        sizeof($report['skipped']), sizeof($report['failed'])
    );
    //Stay quiet when there is nothing to do, so this can run on a short interval
    //without burying anything that matters.
    if ($report['delivered'] || $report['unmatched'] || $report['failed']) {
        echo "[{$stamp}] {$counts}\n";
        foreach ($report['delivered'] as $id) {
            echo "    delivered  #{$id}\n";
        }
        foreach ($report['unmatched'] as $id) {
            echo "    unmatched  #{$id}  (viewer has no linked game account)\n";
        }
        foreach ($report['failed'] as $line) {
            echo "    failed     {$line}\n";
        }
    }
    return $report;
}

if (!$loop) {
    $report = deliver_once($bot, $limit);
    if (!$report['delivered'] && !$report['unmatched'] && !$report['failed']) {
        echo "Nothing pending.\n";
    }
    //Failures need a human; unmatched ones are a queue, not an error.
    exit(sizeof($report['failed']) ? 1 : 0);
}

echo "Delivering every {$loop}s. Ctrl-C to stop.\n";
while (true) {
    try {
        deliver_once($bot, $limit);
    } catch (Throwable $e) {
        //Never let one bad pass kill the loop: the bot may just be restarting.
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] pass failed: ' . $e->getMessage() . "\n");
    }
    sleep($loop);
}
