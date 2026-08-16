#!/usr/bin/env php
<?php
/**
 * Foreground game engine, for running under a process supervisor (Docker).
 *
 * main_script/include/AutomationEngine.php daemonises itself: it forks and the
 * parent calls exit(0). That is right for systemd Type=forking, but inside a
 * container it makes PID 1 exit and takes the container down with it. This
 * entry point launches the very same jobs and then stays in the foreground.
 *
 * Usage: php tools/engine.php
 */
declare(ticks=1);

if (php_sapi_name() != 'cli') {
    exit("CLI Only!");
}

set_time_limit(0);
ini_set('memory_limit', -1);
ini_set('display_errors', 1);

require "/travian/main_script/copyable/include/env.php";

/**
 * Waits for a TCP port to accept connections.
 *
 * The container restarts on its own (restart: unless-stopped), but depends_on
 * only orders the initial `up`. After a host reboot the engine came back before
 * the database did, died instantly, and spun in a restart loop. Waiting turns
 * that into a few quiet seconds.
 */
function wait_for_port($host, $port, $seconds = 90)
{
    $deadline = time() + $seconds;
    $announced = false;
    while (time() < $deadline) {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            return true;
        }
        if (!$announced) {
            echo '[engine ' . date('Y-m-d H:i:s') . "] waiting for {$host}:{$port}..." . PHP_EOL;
            $announced = true;
        }
        sleep(2);
    }
    return false;
}

{
    //Read the connection details without booting the whole application, which
    //would try to talk to the database before we know it is there.
    require CONNECTION_FILE;
    global $connection;
    $dbHost = isset($connection['database']['hostname']) ? $connection['database']['hostname'] : 'db';
    if (!wait_for_port($dbHost, 3306)) {
        fwrite(STDERR, "[engine] database {$dbHost}:3306 never came up" . PHP_EOL);
        exit(1);
    }
    $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
    $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
    if (!wait_for_port($redisHost, $redisPort)) {
        fwrite(STDERR, "[engine] redis {$redisHost}:{$redisPort} never came up" . PHP_EOL);
        exit(1);
    }
}

if (IS_DEV) {
    require "/travian/main_script_dev/include/bootstrap.php";
} else {
    require "/travian/main_script/include/bootstrap.php";
}

use Core\Jobs;

global $PIDs, $loop;
$PIDs = [];
$loop = TRUE;

function engine_log($message)
{
    echo '[engine ' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function engine_shutdown()
{
    global $PIDs, $loop;
    $loop = FALSE;
    engine_log('shutting down, stopping ' . sizeof($PIDs) . ' worker(s)');
    foreach ($PIDs as $name => $pid) {
        if ($pid > 0) {
            posix_kill($pid, SIGTERM);
        }
        unset($PIDs[$name]);
    }
}

pcntl_signal(SIGTERM, 'engine_shutdown');
pcntl_signal(SIGINT, 'engine_shutdown');
pcntl_signal(SIGHUP, 'engine_shutdown');

if (!Core\Config::getInstance()->dynamic->installed) {
    engine_log('world is not installed yet - run tools/install.sh first');
    exit(1);
}

engine_log('starting jobs for world ' . getWorldId());
Jobs\Launcher::lunchJobs();
engine_log('running (' . sizeof($PIDs) . ' worker processes)');

while ($loop) {
    pcntl_signal_dispatch();
    //Reap finished workers so they do not pile up as zombies. If every worker is
    //gone there is nothing left to supervise, so exit and let Docker restart us.
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        $name = array_search($pid, $PIDs, TRUE);
        if ($name !== FALSE) {
            unset($PIDs[$name]);
            engine_log("worker '{$name}' exited (status " . pcntl_wexitstatus($status) . ")");
        }
    }
    if ($loop && !sizeof($PIDs)) {
        engine_log('all workers exited, stopping');
        exit(1);
    }
    sleep(1);
}

engine_log('stopped');
exit(0);
