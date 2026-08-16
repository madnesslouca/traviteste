<?php
if (!(php_sapi_name() == 'cli')) {
    exit("CLI Only!");
}
require __DIR__ . "/env.php";
if (IS_DEV) {
    require "/travian/main_script_dev/include/bootstrap.php";
} else {
    require "/travian/main_script/include/bootstrap.php";
}
use Core\Config;
use Core\Database\DB;
use Model\InstallerModel;

mt_srand(make_seed());

class shell_installer
{
    public function __construct($password)
    {
        if (empty($password)) {
            $password = sha1(time());
        }
        if (Config::getInstance()->dynamic->installed) {
            echo 'Installation is already completed.' . PHP_EOL;
            return;
        }
        ini_set('memory_limit', -1);
        set_time_limit(0);
        $installer = new InstallerModel();
        //Announcing each step matters: when the installer died half way through
        //there was no way to tell how far it had got.
        $this->step('building map array');
        $installer->mapToArray();
        //Landscape generation used to be commented out here because a libpng
        //warning aborted the whole run. That is fixed in ErrorHandler, so the
        //map gets its forests, lakes and hills again.
        $this->step('generating landscape');
        $installer->createRestOfTheMap();
        $this->step('writing map to database');
        $installer->pushMapToDB();
        $this->step('creating system accounts');
        $installer->finalize($password);
        $db = DB::getInstance();
        $db->query("UPDATE odata SET lasttrain=" . Config::getProperty('game', 'start_time'));
        $this->step('done');
        echo "[install] multihunter password: {$password}" . PHP_EOL;
    }

    private function step($label)
    {
        echo '[install] ' . $label . '... (' . date('H:i:s') . ')' . PHP_EOL;
    }
}

//Without this the installer failed silently: an uncaught error only reached the
//exception handler, which logged one line and let the process exit with 0.
try {
    new shell_installer(isset($argv[2]) ? trim($argv[2]) : '');
} catch (\Throwable $e) {
    fwrite(STDERR, '[install] FAILED: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
exit(0);
