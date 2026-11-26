<?php
declare(strict_types=1);

namespace Place\Adapters;

use Place\HttpClient;
use Place\VendorError;
use function Place\json_or_fail;

/**
 * Nuki Web API with a personal API token (web.nuki.io → Menü → API).
 * Docs: https://docs.nuki.io/guide/overview/concepts/
 */
final class NukiAdapter implements Adapter
{
    private const BASE = 'https://api.nuki.io';
    private const TYPE_OPENER = 2;
    private const ACTION_UNLOCK = 1;
    private const ACTION_LOCK = 2;

    public function __construct(private HttpClient $http)
    {
    }

    public function vendor(): string
    {
        return 'nuki';
    }

    public function discover(array &$secret): array
    {
        $devices = [];
        foreach ($this->smartlocks($secret) as $lock) {
            if ((int) ($lock['type'] ?? 0) === self::TYPE_OPENER) {
                continue; // Opener is not a lock; not supported yet
            }
            $devices[] = [
                'id' => 'nuki:' . $lock['smartlockId'],
                'vendor' => 'nuki',
                'kind' => 'lock',
                'name' => (string) ($lock['name'] ?? 'Nuki'),
                'ref' => (string) $lock['smartlockId'],
            ];
        }
        return $devices;
    }

    public function poll(array &$secret, array $devices): array
    {
        $out = [];
        foreach ($this->smartlocks($secret) as $lock) {
            $out['nuki:' . $lock['smartlockId']] = self::values($lock['state'] ?? []);
        }
        return $out;
    }

    public function execute(array &$secret, array $device, array $command): ?array
    {
        $action = match ($command['type'] ?? '') {
            'lock' => self::ACTION_LOCK,
            'unlock' => self::ACTION_UNLOCK,
            default => throw new VendorError('Befehl passt nicht zum Schloss'),
        };
        $res = $this->http->request('POST', self::BASE . '/smartlock/' . rawurlencode($device['ref']) . '/action', $this->headers($secret), json_encode(['action' => $action]));
        json_or_fail($res, 'Nuki');
        return null; // Nuki answers when the request is valid, not when the lock has moved
    }

    /** @return list<array<string,mixed>> */
    private function smartlocks(array $secret): array
    {
        $data = json_or_fail($this->http->request('GET', self::BASE . '/smartlock', $this->headers($secret)), 'Nuki');
        return is_array($data) ? $data : [];
    }

    /** @return array<string,string> */
    private function headers(array $secret): array
    {
        return [
            'Authorization' => 'Bearer ' . $secret['token'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Nuki lock states: 1 locked, 2 unlocking, 3 unlocked, 4 locking, 5 unlatched,
     * 6 unlocked (lock 'n' go), 7 unlatching, 254 motor blocked.
     * Door states: 2 closed, 3 open, others unknown.
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    public static function values(array $s): array
    {
        $lock = match ((int) ($s['state'] ?? 255)) {
            1 => 'locked',
            5, 7 => 'unlatched',
            254 => 'jammed',
            default => 'unlocked',
        };
        $door = match ((int) ($s['doorState'] ?? 0)) {
            2 => 'closed',
            3 => 'open',
            default => 'unknown',
        };
        return ['kind' => 'lock', 'lock' => $lock, 'door' => $door, 'batteryCritical' => (bool) ($s['batteryCritical'] ?? false)];
    }
}
