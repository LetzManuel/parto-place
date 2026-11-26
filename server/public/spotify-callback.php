<?php
declare(strict_types=1);

$_GET['r'] = 'spotify-callback';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/http_entry.php';
