<?php
// Created by "php tools/init.php". Never commit config.php.
return [
    'app_url' => 'https://place.parto.at',
    'db_dsn' => 'mysql:host=localhost;dbname=DATENBANK;charset=utf8mb4',
    'db_user' => 'BENUTZER',
    'db_pass' => 'PASSWORT',
    'encryption_key' => 'GENERATED',
    'app_key_hash' => 'GENERATED',
    'allowed_origins' => ['https://place.parto.at', 'tauri://localhost', 'http://localhost:5173'],
];
