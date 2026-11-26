<?php
declare(strict_types=1);

namespace Place;

use Place\Adapters\SpotifyAdapter;

/**
 * JSON API. Every route except the Spotify callback needs the app key
 * (Authorization: Bearer …). Routes are passed as ?r=… so no URL rewriting is needed.
 */
final class Api
{
    private const VENDORS_BY_TOKEN = ['nuki', 'lg'];

    /** @param array<string,mixed> $config */
    public function __construct(private array $config, private Store $store, private Service $service, private SpotifyAdapter $spotify)
    {
    }

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-case names
     * @return array{status:int, body:mixed, redirect?:string}
     */
    public function handle(string $method, array $query, array $headers, string $rawBody): array
    {
        try {
            $route = $query['r'] ?? '';
            if ($route === 'spotify-callback' && $method === 'GET') {
                return $this->spotifyCallback($query);
            }
            $this->authorize($headers['authorization'] ?? '');
            $body = $rawBody === '' ? [] : json_decode($rawBody, true);
            if (!is_array($body)) {
                throw new ApiError(400, 'Ungültige Anfrage');
            }
            return match ([$method, $route]) {
                ['GET', 'status'] => $this->status($query),
                ['POST', 'command'] => ['status' => 200, 'body' => $this->service->command(
                    (string) ($body['deviceId'] ?? ''),
                    is_array($body['command'] ?? null) ? $body['command'] : [],
                    (string) ($body['expiresAt'] ?? ''),
                )],
                ['POST', 'connect'] => $this->connect($body),
                ['POST', 'disconnect'] => $this->disconnect($body),
                ['POST', 'spotify-start'] => $this->spotifyStart($body),
                ['POST', 'room'] => $this->room($body),
                ['GET', 'history'] => ['status' => 200, 'body' => ['events' => $this->store->events()]],
                default => throw new ApiError(404, 'Unbekannte Route'),
            };
        } catch (ApiError $e) {
            return ['status' => $e->httpStatus, 'body' => ['error' => $e->getMessage()]];
        }
    }

    private function authorize(string $header): void
    {
        $given = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        if ($given === '' || !hash_equals((string) $this->config['app_key_hash'], hash('sha256', $given))) {
            throw new ApiError(401, 'Nicht angemeldet');
        }
    }

    /** @param array<string,string> $query */
    private function status(array $query): array
    {
        if (($query['refresh'] ?? '') === '1') {
            $this->service->pollAll();
        }
        return ['status' => 200, 'body' => [
            'devices' => $this->store->devices(),
            'states' => $this->store->states(),
            'links' => $this->store->links(),
            'serverTime' => Store::now(),
        ]];
    }

    /** @param array<string,mixed> $body */
    private function connect(array $body): array
    {
        $vendor = (string) ($body['vendor'] ?? '');
        $token = trim((string) ($body['token'] ?? ''));
        if (!in_array($vendor, self::VENDORS_BY_TOKEN, true) || $token === '') {
            throw new ApiError(400, 'Hersteller und Token angeben');
        }
        $secret = ['token' => $token];
        if ($vendor === 'lg') {
            $secret['country'] = strtoupper((string) ($body['country'] ?? 'AT'));
        }
        return ['status' => 200, 'body' => ['devices' => $this->service->connect($vendor, $secret)]];
    }

    /** @param array<string,mixed> $body */
    private function disconnect(array $body): array
    {
        $this->service->disconnect((string) ($body['vendor'] ?? ''));
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /** @param array<string,mixed> $body */
    private function room(array $body): array
    {
        $this->store->setRoom((string) ($body['deviceId'] ?? ''), mb_substr(trim((string) ($body['room'] ?? '')), 0, 60));
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /** @param array<string,mixed> $body */
    private function spotifyStart(array $body): array
    {
        $clientId = trim((string) ($body['clientId'] ?? ''));
        if (!preg_match('/^[0-9a-f]{32}$/i', $clientId)) {
            throw new ApiError(400, 'Spotify Client ID hat 32 Zeichen (0-9, a-f)');
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->store->saveOauth($state, 'spotify', $verifier, $clientId);
        return ['status' => 200, 'body' => ['url' => SpotifyAdapter::authorizeUrl($clientId, $this->redirectUri(), $state, $verifier)]];
    }

    /** @param array<string,string> $query */
    private function spotifyCallback(array $query): array
    {
        $back = rtrim((string) $this->config['app_url'], '/') . '/#mehr';
        $pending = $this->store->takeOauth((string) ($query['state'] ?? ''));
        if ($pending === null || !empty($query['error']) || empty($query['code'])) {
            return ['status' => 302, 'body' => null, 'redirect' => $back . '?spotify=fehler'];
        }
        try {
            $secret = $this->spotify->exchangeCode($pending['client_id'], (string) $query['code'], $this->redirectUri(), $pending['verifier']);
            $this->service->connect('spotify', $secret);
        } catch (VendorError|ApiError) {
            return ['status' => 302, 'body' => null, 'redirect' => $back . '?spotify=fehler'];
        }
        return ['status' => 302, 'body' => null, 'redirect' => $back . '?spotify=ok'];
    }

    private function redirectUri(): string
    {
        return rtrim((string) $this->config['app_url'], '/') . '/spotify-callback.php';
    }
}
