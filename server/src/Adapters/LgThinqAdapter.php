<?php
declare(strict_types=1);

namespace Place\Adapters;

use Place\HttpClient;
use Place\VendorError;
use function Place\json_or_fail;

/**
 * LG ThinQ Connect with a personal access token (connect-pat.lgthinq.com).
 * Request format follows LG's official SDK (github.com/thinq-connect/pythinqconnect, Apache-2.0).
 * Appliances are reported (running / finished), not controlled.
 */
final class LgThinqAdapter implements Adapter
{
    /** Public API key shipped in LG's official SDK (thinqconnect/const.py), not a user secret. */
    private const API_KEY = 'v6GFvkweNo7DK7yD3ylIZ9w52aKBU0eJ7wLXkSR3';
    private const IDLE = ['POWER_OFF', 'INITIAL', 'SLEEP', 'STANDBY', ''];
    private const FINISHED = ['END', 'COMPLETE'];

    public function __construct(private HttpClient $http)
    {
    }

    public function vendor(): string
    {
        return 'lg';
    }

    public function discover(array &$secret): array
    {
        $secret['clientId'] ??= 'parto-place-' . bin2hex(random_bytes(8));
        $list = $this->get($secret, 'devices');
        $devices = [];
        foreach (is_array($list) ? $list : [] as $d) {
            $info = $d['deviceInfo'] ?? [];
            $devices[] = [
                'id' => 'lg:' . $d['deviceId'],
                'vendor' => 'lg',
                'kind' => 'appliance',
                'name' => (string) ($info['alias'] ?? self::typeName((string) ($info['deviceType'] ?? ''))),
                'ref' => (string) $d['deviceId'],
            ];
        }
        return $devices;
    }

    public function poll(array &$secret, array $devices): array
    {
        $out = [];
        foreach ($devices as $d) {
            $out[$d['id']] = self::values($this->get($secret, 'devices/' . rawurlencode($d['ref']) . '/state'));
        }
        return $out;
    }

    public function execute(array &$secret, array $device, array $command): ?array
    {
        throw new VendorError('LG-Geräte werden nur angezeigt, nicht gesteuert');
    }

    private function get(array $secret, string $endpoint): mixed
    {
        $country = strtoupper((string) ($secret['country'] ?? 'AT'));
        $headers = [
            'Authorization' => 'Bearer ' . $secret['token'],
            'x-country' => $country,
            'x-message-id' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'x-client-id' => (string) $secret['clientId'],
            'x-api-key' => self::API_KEY,
            'x-service-phase' => 'OP',
            'Accept' => 'application/json',
        ];
        $data = json_or_fail($this->http->request('GET', 'https://api-' . self::region($country) . '.lgthinq.com/' . $endpoint, $headers), 'LG ThinQ');
        return is_array($data) ? ($data['response'] ?? null) : null;
    }

    /** Europe uses the "eic" region; America "aic"; Asia/Pacific "kic". */
    private static function region(string $country): string
    {
        $america = ['US', 'CA', 'MX', 'BR', 'AR', 'CL', 'CO', 'PE'];
        $asia = ['KR', 'JP', 'CN', 'AU', 'NZ', 'IN', 'SG', 'TW', 'HK', 'TH', 'VN', 'PH', 'ID', 'MY', 'BD'];
        return in_array($country, $america, true) ? 'aic' : (in_array($country, $asia, true) ? 'kic' : 'eic');
    }

    private static function typeName(string $type): string
    {
        return match ($type) {
            'DEVICE_WASHER' => 'Waschmaschine',
            'DEVICE_DRYER' => 'Trockner',
            'DEVICE_DISH_WASHER' => 'Geschirrspüler',
            'DEVICE_REFRIGERATOR' => 'Kühlschrank',
            'DEVICE_AIR_CONDITIONER' => 'Klimaanlage',
            default => 'LG-Gerät',
        };
    }

    /**
     * State is an object, or a list of locations (washtower, washcombo).
     * @return array<string,mixed>
     */
    public static function values(mixed $state): array
    {
        $parts = is_array($state) && array_is_list($state) ? $state : [$state];
        $run = 'idle';
        $remaining = null;
        foreach ($parts as $p) {
            if (!is_array($p)) {
                continue;
            }
            $cur = strtoupper((string) ($p['runState']['currentState'] ?? ''));
            $timer = $p['timer'] ?? [];
            $min = isset($timer['remainHour']) || isset($timer['remainMinute'])
                ? (int) ($timer['remainHour'] ?? 0) * 60 + (int) ($timer['remainMinute'] ?? 0)
                : null;
            if (in_array($cur, self::FINISHED, true) && $run === 'idle') {
                $run = 'finished';
            } elseif (!in_array($cur, self::IDLE, true) && !in_array($cur, self::FINISHED, true)) {
                $run = 'running';
                $remaining = $min;
            }
        }
        $v = ['kind' => 'appliance', 'state' => $run];
        if ($remaining) {
            $v['remainingMin'] = $remaining;
        }
        return $v;
    }
}
