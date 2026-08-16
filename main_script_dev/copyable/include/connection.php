<?php
global $connection;
$connection = [
    'speed' => '3',
    'round_length' => 'auto',
    'worldId' => 'dev',
    'secure_hash_code' => 'local-dev-key',
    'title' => 'Local Dev',
    'gameWorldUrl' => 'http://localhost:8080/',
    'serverName' => 'Local Dev',
    'auto_reinstall' => 0,
    'auto_reinstall_start_after' => 86400,
    'engine_filename' => 'local_engine',
    'database' => [
        'hostname' => 'db',
        'username' => 'travian',
        'password' => 'travian',
        'database' => 'world',
        'charset' => 'utf8mb4',
    ],
];
