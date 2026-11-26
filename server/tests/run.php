<?php
declare(strict_types=1);

// Dependency-free tests:  php server/tests/run.php
// Uses SQLite in memory and a fake HTTP client; never calls a real vendor.

require dirname(__DIR__) . '/src/bootstrap.php';

use Place\Adapters\LgThinqAdapter;
use Place\Adapters\NukiAdapter;
use Place\Adapters\SpotifyAdapter;
use Place\Crypto;
use Place\HttpClient;

final class FakeHttp implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];
    /** @var array<string,list<array{status:int,body:string}>> "METHOD url-prefix" => queued responses */
    public array $routes = [];

    public function on(string $method, string $urlPrefix, int $status, mixed $body): void
    {
        $this->routes["$method $urlPrefix"][] = ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        foreach ($this->routes as $key => &$queue) {
            [$m, $prefix] = explode(' ', $key, 2);
            if ($m === $method && str_starts_with($url, $prefix) && $queue) {
                return count($queue) > 1 ? array_shift($queue) : $queue[0];
            }
        }
        throw new RuntimeException("unexpected request $method $url");
    }
}

function sqlite(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $schema = (string) file_get_contents(dirname(__DIR__) . '/schema.sql');
    $schema = str_replace(['AUTO_INCREMENT', 'DEFAULT CHARSET=utf8mb4', 'INTEGER NOT NULL PRIMARY KEY'], ['', '', 'INTEGER PRIMARY KEY AUTOINCREMENT'], $schema);
    foreach (explode(';', $schema) as $sql) {
        $sql = trim((string) preg_replace('/--.*$/m', '', $sql));
        if ($sql !== '') {
            $pdo->exec($sql);
        }
    }
    return $pdo;
}

const APP_KEY = 'test-app-key';
function app(FakeHttp $http, int &$now): array
{
    $config = [
        'app_url' => 'https://place.example',
        'encryption_key' => Crypto::newKey(),
        'app_key_hash' => hash('sha256', APP_KEY),
    ];
    $clock = function () use (&$now): int { return $now; };
    return [...Place\make_app($config, $http, sqlite(), $clock), $config];
}

function call(Place\Api $api, string $method, string $route, array $body = [], string $key = APP_KEY): array
{
    return $api->handle($method, ['r' => $route], ['authorization' => 'Bearer ' . $key], $body ? json_encode($body) : '');
}

$tests = [];
$test = function (string $name, callable $fn) use (&$tests) { $tests[$name] = $fn; };
function eq(mixed $a, mixed $b, string $msg = ''): void
{
    if ($a !== $b) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($b, true) . ', got ' . var_export($a, true));
    }
}

$nukiList = [
    ['smartlockId' => 111, 'type' => 4, 'name' => 'Haustür', 'state' => ['state' => 3, 'doorState' => 2, 'batteryCritical' => false]],
    ['smartlockId' => 222, 'type' => 2, 'name' => 'Opener', 'state' => ['state' => 1]],
];

$test('rejects requests without the app key', function () {
    $now = 1_800_000_000;
    [, , $api] = app(new FakeHttp(), $now);
    eq(call($api, 'GET', 'status', [], 'wrong')['status'], 401);
    eq($api->handle('GET', ['r' => 'status'], [], '')['status'], 401);
});

$test('connects Nuki, skips the Opener and stores the token encrypted', function () use ($nukiList) {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api.nuki.io/smartlock', 200, $nukiList);
    [$store, , $api] = app($http, $now);
    $res = call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 'secret-nuki-token']);
    eq($res['body'], ['devices' => 1]);
    eq($http->calls[0]['headers']['Authorization'], 'Bearer secret-nuki-token');
    $status = call($api, 'GET', 'status')['body'];
    eq($status['devices'][0]['id'], 'nuki:111');
    eq($status['states'][0]['values'], ['kind' => 'lock', 'lock' => 'unlocked', 'door' => 'closed', 'batteryCritical' => false]);
    eq(str_contains(json_encode($status), 'secret-nuki-token'), false, 'token never in API output');
    eq($store->linkSecret('nuki'), ['token' => 'secret-nuki-token']);
});

$test('a wrong Nuki token is refused and not stored', function () {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api.nuki.io/smartlock', 401, ['detailMessage' => 'unauthorized']);
    [$store, , $api] = app($http, $now);
    $res = call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 'bad']);
    eq($res['status'], 400);
    eq($res['body']['error'], 'Nuki: Zugang abgelehnt, bitte neu verbinden');
    eq($store->links(), []);
});

$test('lock command is accepted, unlock needs confirmation, late commands are dropped', function () use ($nukiList) {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api.nuki.io/smartlock', 200, $nukiList);
    $http->on('POST', 'https://api.nuki.io/smartlock/111/action', 204, '');
    [$store, , $api] = app($http, $now);
    call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 't']);
    $later = gmdate('Y-m-d\TH:i:s\Z', $now + 30);
    $res = call($api, 'POST', 'command', ['deviceId' => 'nuki:111', 'command' => ['type' => 'lock'], 'expiresAt' => $later]);
    eq($res['body'], ['status' => 'accepted']);
    $last = end($http->calls);
    eq($last['body'], '{"action":2}');
    $res = call($api, 'POST', 'command', ['deviceId' => 'nuki:111', 'command' => ['type' => 'unlock'], 'expiresAt' => $later]);
    eq($res['body'], ['status' => 'failed', 'reason' => 'Aufsperren braucht eine Bestätigung']);
    $res = call($api, 'POST', 'command', ['deviceId' => 'nuki:111', 'command' => ['type' => 'lock'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now - 1)]);
    eq($res['body'], ['status' => 'failed', 'reason' => 'zu spät, nicht ausgeführt']);
    eq(count($store->events()), 1, 'only the executed command is in the history');
});

$test('a failing poll marks devices unreachable instead of keeping old states', function () use ($nukiList) {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api.nuki.io/smartlock', 200, $nukiList); // discover
    $http->on('GET', 'https://api.nuki.io/smartlock', 200, $nukiList); // first poll
    $http->on('GET', 'https://api.nuki.io/smartlock', 500, '');
    [$store, $service, $api] = app($http, $now);
    call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 't']);
    $service->pollAll();
    $status = call($api, 'GET', 'status')['body'];
    eq($status['states'][0]['reachable'], false);
    eq($status['links'][0]['status'], 'error');
    eq($status['links'][0]['error'], 'Nuki: Fehler 500');
});

$test('LG washer: list, finished and running states with remaining time', function () {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api-eic.lgthinq.com/devices/w1/state', 200, ['response' => ['runState' => ['currentState' => 'RUNNING'], 'timer' => ['remainHour' => 1, 'remainMinute' => 5]]]);
    $http->on('GET', 'https://api-eic.lgthinq.com/devices', 200, ['response' => [['deviceId' => 'w1', 'deviceInfo' => ['deviceType' => 'DEVICE_WASHER', 'alias' => 'Waschmaschine']]]]);
    [$store, , $api] = app($http, $now);
    eq(call($api, 'POST', 'connect', ['vendor' => 'lg', 'token' => 'pat'])['body'], ['devices' => 1]);
    $h = $http->calls[0]['headers'];
    eq($h['x-country'], 'AT');
    eq($h['x-api-key'], 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3');
    eq(str_starts_with($h['x-client-id'], 'parto-place-'), true);
    eq(call($api, 'GET', 'status')['body']['states'][0]['values'], ['kind' => 'appliance', 'state' => 'running', 'remainingMin' => 65]);
    eq(LgThinqAdapter::values(['runState' => ['currentState' => 'END']]), ['kind' => 'appliance', 'state' => 'finished']);
    eq(LgThinqAdapter::values([['location' => ['locationName' => 'MAIN'], 'runState' => ['currentState' => 'POWER_OFF']]]), ['kind' => 'appliance', 'state' => 'idle']);
    eq(call($api, 'POST', 'command', ['deviceId' => 'lg:w1', 'command' => ['type' => 'setOn', 'on' => false], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + 30)])['body']['status'], 'failed');
});

$test('Spotify: PKCE start, callback, token refresh and pause', function () {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('POST', 'https://accounts.spotify.com/api/token', 200, ['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600]);
    $http->on('GET', 'https://api.spotify.com/v1/me/player', 200, ['is_playing' => true, 'item' => ['name' => 'Song'], 'device' => ['name' => 'Wohnzimmer']]);
    $http->on('PUT', 'https://api.spotify.com/v1/me/player/pause', 204, '');
    [$store, $service, $api] = app($http, $now);
    eq(call($api, 'POST', 'spotify-start', ['clientId' => 'nope'])['status'], 400);
    $start = call($api, 'POST', 'spotify-start', ['clientId' => str_repeat('ab', 16)])['body']['url'];
    parse_str((string) parse_url($start, PHP_URL_QUERY), $q);
    eq($q['code_challenge_method'], 'S256');
    eq($q['redirect_uri'], 'https://place.example/spotify-callback.php');
    $cb = $api->handle('GET', ['r' => 'spotify-callback', 'state' => $q['state'], 'code' => 'c1'], [], '');
    eq($cb['redirect'], 'https://place.example/#mehr?spotify=ok');
    eq($api->handle('GET', ['r' => 'spotify-callback', 'state' => $q['state'], 'code' => 'c1'], [], '')['redirect'], 'https://place.example/#mehr?spotify=fehler', 'state is single-use');
    eq(call($api, 'GET', 'status')['body']['states'][0]['values'], ['kind' => 'media', 'playing' => true, 'title' => 'Song', 'on' => 'Wohnzimmer']);
    // token expires -> refresh keeps the old refresh token when none is returned
    $now += 7200;
    $http->routes['POST https://accounts.spotify.com/api/token'] = [['status' => 200, 'body' => json_encode(['access_token' => 'a2', 'expires_in' => 3600])]];
    $res = call($api, 'POST', 'command', ['deviceId' => 'spotify:player', 'command' => ['type' => 'pause'], 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + 30)]);
    eq($res['body'], ['status' => 'done']);
    $secret = $store->linkSecret('spotify');
    eq([$secret['accessToken'], $secret['refreshToken']], ['a2', 'r1']);
    eq(end($http->calls)['headers']['Authorization'], 'Bearer a2');
    eq(SpotifyAdapter::values(null), ['kind' => 'media', 'playing' => false]);
});

$test('disconnect deletes secret, devices and states', function () use ($nukiList) {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api.nuki.io/smartlock', 200, $nukiList);
    [$store, , $api] = app($http, $now);
    call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 't']);
    call($api, 'POST', 'disconnect', ['vendor' => 'nuki']);
    eq($store->linkSecret('nuki'), null);
    eq(call($api, 'GET', 'status')['body']['devices'], []);
    eq(call($api, 'GET', 'status')['body']['states'], []);
});

$test('rooms survive re-discovery', function () use ($nukiList) {
    $now = 1_800_000_000;
    $http = new FakeHttp();
    $http->on('GET', 'https://api.nuki.io/smartlock', 200, $nukiList);
    [, , $api] = app($http, $now);
    call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 't']);
    call($api, 'POST', 'room', ['deviceId' => 'nuki:111', 'room' => 'Vorraum']);
    call($api, 'POST', 'connect', ['vendor' => 'nuki', 'token' => 't']);
    eq(call($api, 'GET', 'status')['body']['devices'][0]['room'], 'Vorraum');
});

$test('Nuki state mapping', function () {
    eq(NukiAdapter::values(['state' => 1, 'doorState' => 3])['lock'], 'locked');
    eq(NukiAdapter::values(['state' => 1, 'doorState' => 3])['door'], 'open');
    eq(NukiAdapter::values(['state' => 254])['lock'], 'jammed');
    eq(NukiAdapter::values(['state' => 5])['lock'], 'unlatched');
    eq(NukiAdapter::values([])['door'], 'unknown');
});

$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name\n    " . $e->getMessage() . "\n";
    }
}
echo "\n" . (count($tests) - $failed) . ' of ' . count($tests) . " passed\n";
exit($failed ? 1 : 0);
