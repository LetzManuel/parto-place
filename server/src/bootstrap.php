<?php
declare(strict_types=1);

namespace Place;

use PDO;
use Place\Adapters\LgThinqAdapter;
use Place\Adapters\NukiAdapter;
use Place\Adapters\SpotifyAdapter;

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Adapters/Adapter.php';
require_once __DIR__ . '/Adapters/NukiAdapter.php';
require_once __DIR__ . '/Adapters/LgThinqAdapter.php';
require_once __DIR__ . '/Adapters/SpotifyAdapter.php';

/** @return array<string,mixed> */
function load_config(): array
{
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) {
        throw new \RuntimeException('config.php fehlt. Bitte zuerst "php tools/init.php" ausführen.');
    }
    return require $file;
}

/** @param array<string,mixed> $config */
function make_app(array $config, ?HttpClient $http = null, ?PDO $pdo = null, ?\Closure $clock = null): array
{
    $http ??= new CurlHttp();
    $clock ??= static fn (): int => time();
    $pdo ??= new PDO($config['db_dsn'], $config['db_user'] ?? null, $config['db_pass'] ?? null);
    $store = new Store($pdo, new Crypto($config['encryption_key']));
    $spotify = new SpotifyAdapter($http, $clock);
    $service = new Service($store, [
        'nuki' => new NukiAdapter($http),
        'lg' => new LgThinqAdapter($http),
        'spotify' => $spotify,
    ], $clock);
    return [$store, $service, new Api($config, $store, $service, $spotify)];
}
