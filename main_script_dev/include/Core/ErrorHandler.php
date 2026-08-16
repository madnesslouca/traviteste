<?php

namespace Core;

use const ERROR_LOG_FILE;
use const IS_DEV;
use function json_encode;
use function restore_exception_handler;

class ErrorHandler
{
    private static $instance;
    private $isCLI = false;
    private $logged = [];

    public function __construct()
    {
        error_reporting(E_ALL & ~E_NOTICE | E_STRICT);
        ini_set('display_errors', IS_DEV);
        set_error_handler([$this, 'handleErrors']);
        register_shutdown_function([$this, 'handleFatalErrors']);
        $this->isCLI = php_sapi_name() == 'cli';
        if ($this->isCLI) {
            set_exception_handler([$this, 'handleExceptions']);
        }
    }

    public function setAsCGI()
    {
        if ($this->isCLI) {
            restore_exception_handler();
            $this->isCLI = false;
        }
    }

    public static function getInstance()
    {
        if (!(self::$instance instanceof ErrorHandler)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($message)
    {
        if (is_array($message)) {
            $message = json_encode($message);
        }
        //Map rendering raises one libpng warning per tile, so the same line can
        //be written hundreds of times per request. Repeating it at most once a
        //minute keeps the log readable instead of growing to hundreds of MB.
        $hash = md5($message);
        $now = time();
        if (isset($this->logged[$hash]) && ($now - $this->logged[$hash]) < 60) {
            return;
        }
        if (sizeof($this->logged) > 500) {
            $this->logged = [];
        }
        $this->logged[$hash] = $now;
        $file = $this->getErrorLogsLocation();
        //is_writable() returns false for a file that does not exist yet, which
        //silently threw away every log line on a fresh install.
        $writable = is_file($file) ? is_writable($file) : is_writable(dirname($file));
        if ($writable) {
            file_put_contents($file, '[' . date("Y.m.d H:i:s") . '] ' . $message . PHP_EOL, FILE_APPEND);
        }
    }

    public function handleExceptions($e)
    {
        if ($e instanceof \Exception) {
            $this->log($e->getMessage());
        } else if ($e instanceof \Error) {
            $this->log($e->getMessage());
        }
        return TRUE;
    }

    public function handleFatalErrors()
    {
        $error = error_get_last();
        if (is_array($error) && isset($error["type"]) && $error["type"] == E_ERROR) {
            $this->handleErrors($error["type"], $error["message"], $error["file"], $error["line"]);
        }
    }

    public function handleErrors($errNumber, $errMessage, $errFile, $errLine)
    {
        switch ($errNumber) {
            case E_ERROR             :
            case E_CORE_ERROR        :
            case E_COMPILE_ERROR     :
            case E_PARSE             :
            case E_USER_ERROR        :
            case E_RECOVERABLE_ERROR :
                $type = 'fatal error';
                break;
            default                  :
                $type = 'warning';
                break;
        }
        $trace = array_reverse(debug_backtrace());
        array_pop($trace);
        $items = [];
        foreach ($trace as $item) {
            $items[] = (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . (isset($item['function']) ? $item['function'] : 'unknown') . '()';
        }
        $errString = 'Backtrace from ' . $type . ' \'' . $errMessage . '\' at ' . $errFile . ' ' . $errLine . ': ' . join(' | ',
                $items);
        $this->log($errString);
        //Only a real fatal error may abort a CLI run. Rethrowing every warning
        //killed the installer on the first libpng warning raised by the
        //landscape generator, leaving the world half written.
        if ($this->isCLI && $type == 'fatal error') {
            throw new \ErrorException($errString, 0, $errNumber, $errFile, $errLine);
        }
        return TRUE;
    }

    private function getErrorLogsLocation()
    {
        return ERROR_LOG_FILE;
    }
}
