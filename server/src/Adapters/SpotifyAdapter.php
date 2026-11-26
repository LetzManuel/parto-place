<?php
declare(strict_types=1);

namespace Place\Adapters;

use Place\HttpClient;
use Place\VendorError;
use function Place\json_or_fail;

/**
 * Spotify Web API (development mode, needs Premium). Sign-in with
 * Authorization Code + PKCE, so no client secret is stored.
 * Player endpoints: https://developer.spotify.com/documentation/web-api
 */
final class SpotifyAdapter implements Adapter
{
    public const SCOPES = 'user-read-playback-state user-modify-playback-state';
    private const API = 'https://api.spotify.com/v1';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    public function __construct(private HttpClient $http, private \Closure $clock)
    {
    }

    public function vendor(): string
    {
        return 'spotify';
    }

    public static function authorizeUrl(string $clientId, string $redirectUri, string $state, string $verifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return 'https://accounts.spotify.com/authorize?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge_method' => 'S256',
            'code_challenge' => $challenge,
        ]);
    }

    /** @return array<string,mixed> secret */
    public function exchangeCode(string $clientId, string $code, string $redirectUri, string $verifier): array
    {
        $res = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => $verifier,
        ]));
        $t = json_or_fail($res, 'Spotify');
        return ['clientId' => $clientId] + $this->tokenFields($t, null);
    }

    public function discover(array &$secret): array
    {
        $this->player($secret); // proves the token works
        return [['id' => 'spotify:player', 'vendor' => 'spotify', 'kind' => 'media', 'name' => 'Spotify', 'ref' => 'player']];
    }

    public function poll(array &$secret, array $devices): array
    {
        return ['spotify:player' => self::values($this->player($secret))];
    }

    public function execute(array &$secret, array $device, array $command): ?array
    {
        if (($command['type'] ?? '') !== 'pause') {
            throw new VendorError('Befehl passt nicht zu Spotify');
        }
        $res = $this->http->request('PUT', self::API . '/me/player/pause', $this->auth($secret));
        if ($res['status'] === 404) {
            return ['kind' => 'media', 'playing' => false];
        }
        json_or_fail($res, 'Spotify');
        return ['kind' => 'media', 'playing' => false];
    }

    /** @return array<string,mixed>|null */
    private function player(array &$secret): ?array
    {
        $res = $this->http->request('GET', self::API . '/me/player', $this->auth($secret));
        if ($res['status'] === 204) {
            return null; // nothing active
        }
        $data = json_or_fail($res, 'Spotify');
        return is_array($data) ? $data : null;
    }

    /** @return array<string,string> */
    private function auth(array &$secret): array
    {
        $now = ($this->clock)();
        if (($secret['expiresAt'] ?? 0) <= $now + 60) {
            $res = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $secret['refreshToken'] ?? '',
                'client_id' => $secret['clientId'] ?? '',
            ]));
            if ($res['status'] === 400 || $res['status'] === 401) {
                throw new VendorError('Spotify: Anmeldung abgelaufen, bitte neu verbinden');
            }
            $secret = array_merge($secret, $this->tokenFields(json_or_fail($res, 'Spotify'), $secret['refreshToken'] ?? null));
        }
        return ['Authorization' => 'Bearer ' . $secret['accessToken']];
    }

    /** @return array<string,mixed> */
    private function tokenFields(mixed $t, ?string $oldRefresh): array
    {
        if (!is_array($t) || empty($t['access_token'])) {
            throw new VendorError('Spotify: unerwartete Antwort');
        }
        return [
            'accessToken' => $t['access_token'],
            'refreshToken' => $t['refresh_token'] ?? $oldRefresh,
            'expiresAt' => ($this->clock)() + (int) ($t['expires_in'] ?? 3600),
        ];
    }

    /** @return array<string,mixed> */
    public static function values(?array $p): array
    {
        if ($p === null) {
            return ['kind' => 'media', 'playing' => false];
        }
        $v = ['kind' => 'media', 'playing' => (bool) ($p['is_playing'] ?? false)];
        if (!empty($p['item']['name'])) {
            $v['title'] = (string) $p['item']['name'];
        }
        if (!empty($p['device']['name'])) {
            $v['on'] = (string) $p['device']['name'];
        }
        return $v;
    }
}
