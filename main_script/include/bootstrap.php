<?php
use Core\Caching\Caching;
use Core\Caching\GlobalCaching;
use Core\Config;
use Core\Database\DB;
use Core\Database\GlobalDB;
use Core\Helper\WebService;
if (!function_exists("geoip_country_code_by_name")) {
    function geoip_country_code_by_name($ip)
    {
        return 'US';
    }
}
if (!extension_loaded("redis")) {
    die("Redis extension not available.");
}
$start_time = microtime(true);
if (php_sapi_name() != 'cli') {
    set_time_limit(120);
    ob_start();
    if (!session_start()) {
        logError("Could not start session.");
        die("Couldn't start session.");
    }
}
define("GLOBAL_CACHING_KEY", get_current_user());
define("ROOT_PATH", dirname(__DIR__) . DIRECTORY_SEPARATOR);
define("PUBLIC_INTERNAL_PATH", dirname(__DIR__) . "copyable/public/");
define("INCLUDE_PATH", __DIR__ . DIRECTORY_SEPARATOR);
define("RESOURCES_PATH", INCLUDE_PATH . "resources" . DIRECTORY_SEPARATOR);
define("LOCALE_PATH", RESOURCES_PATH . "Translation" . DIRECTORY_SEPARATOR);
define("TEMPLATES_PATH", RESOURCES_PATH . "Templates" . DIRECTORY_SEPARATOR);
require_once INCLUDE_PATH . "Core" . DIRECTORY_SEPARATOR . 'Autoloader.php';
require_once INCLUDE_PATH . "functions.general.php";
global $config;
$cache = Caching::getInstance();
$config = Config::getInstance();
if (!property_exists($config, 'db')) {
    die("Installation is not completed.");
}
$db = DB::getInstance();
{
    //The cached branch never assigned $config->dynamic, so it was disabled with
    //a hardcoded "true ||" and every request read the config row again. It now
    //assigns what it caches, and the cache is used for web requests only: the
    //engine must always see a fresh row.
    $_cache = php_sapi_name() == 'cli' ? false : $cache->get("WorldConfig");
    if ($_cache) {
        $config->dynamic = $_cache;
    } else {
        $result = $db->query("SELECT * FROM config");
        if (!$result->num_rows) {
            logError("No config row found.");
            exit("We are having issues, please try again in a moment. E1");
        }
        $config->dynamic = (object)$result->fetch_assoc();
        if (php_sapi_name() != 'cli') {
            $cache->set('WorldConfig', $config->dynamic, 300);
        }
    }
    //This used to read property_exists($config, 'startTime') - wrong object and
    //inverted, so it never caught a config row missing its columns.
    if (!property_exists($config->dynamic, 'startTime')) {
        logError("No column found in config row.");
        exit("We are having issues, please try again in a moment. E2");
    }
    $config->game->start_time = $config->dynamic->startTime;
    $config->settings->worldUniqueId = $config->dynamic->worldUniqueId;
    define("MAP_SIZE", $config->dynamic->map_size);
}
if (property_exists($config->dynamic, 'isRestore') && $config->dynamic->isRestore && !defined("IS_UPDATE")) {
    exit("We are having issues, please try again in a moment. E3");
}
require(INCLUDE_PATH . "config/config.after.php");
if (!$config->dynamic->installed) {
    $config->dynamic->maintenance = true;
}
function check_ip_access()
{
    $ip = WebService::ipAddress();
    if (!empty($ip)) {
        $ip = ip2long($ip);
        $cache = GlobalCaching::getInstance();
        $banned = $cache->get("IPCheck:$ip");
        if ($banned === false) {
            //The query returns a mysqli_result, and the old code tested it with
            //is_array()/sizeof() - so a banned IP was never actually blocked.
            //Resolve the row here and cache the same shape either way.
            $result = GlobalDB::getInstance()->query("SELECT * FROM banIP WHERE ip='$ip' AND (blockTill=0 OR blockTill > " . time() . ")");
            $banned = ($result && $result->num_rows) ? $result->fetch_assoc() : [];
            $cache->set("IPCheck:$ip", $banned, 1440);
        }
        if (is_array($banned) && sizeof($banned)) {
            exit("You are not allowed to access here.");
        }
    }
}

if (php_sapi_name() != 'cli') {
    Caching::getInstance();
    check_ip_access();
}
