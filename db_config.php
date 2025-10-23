<?php
// File: db_config.php

return [
    'host' => '127.0.0.1',
    'db'   => 'geniusmo_inwi',
    'user' => 'root',
    'pass' => 'super@00',
    'port'=> '3306' ,
    'debug' => true, // Set to false in production
    'charset' => 'utf8mb4',  // ← IMPORTANT: Must be utf8mb4
    'api' => [
        'host' => 'api.gamius.ma',
       'api_key' => ''
    ]

];
