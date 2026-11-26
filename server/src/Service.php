<?php
declare(strict_types=1);

namespace Place;

use Place\Adapters\Adapter;

/** Connect, poll and command logic, shared by the API and the cron job. */
final class Service
{
    /** @param array<string,Adapter> $adapters keyed by vendor */
    public function __construct(private Store $store, private array $adapters, private \Closure $clock)
    {
    }

    public function adapter(string $vendor): Adapter
    {
        return $this->adapters[$vendor] ?? throw new ApiError(400, 'Unbekannter Hersteller');
    }

    /**
     * Store a new vendor secret after checking it works.
     * @param array<string,mixed> $secret
     * @return int number of devices found
     */
    public function connect(string $vendor, array $secret): int
    {
        $adapter = $this->adapter($vendor);
        try {
            $devices = $adapter->discover($secret);
        } catch (VendorError $e) {
            throw new ApiError(400, $e->getMessage());
        }
        $this->store->saveLink($vendor, $secret);
        $this->store->replaceDevices($vendor, $devices);
        $this->pollVendor($vendor);
        return count($devices);
    }

    public function disconnect(string $vendor): void
    {
        $this->adapter($vendor);
        $this->store->deleteLink($vendor);
    }

    /** Poll every connected vendor. Errors are stored per vendor, never thrown. */
    public function pollAll(): void
    {
        foreach ($this->store->links() as $link) {
            if (isset($this->adapters[$link['vendor']])) {
                $this->pollVendor($link['vendor']);
            }
        }
    }

    public function pollVendor(string $vendor): void
    {
        $secret = $this->store->linkSecret($vendor);
        if ($secret === null) {
            return;
        }
        $before = $secret;
        $adapter = $this->adapters[$vendor];
        try {
            $devices = $this->store->devices($vendor);
            $known = array_column($devices, 'id');
            $values = $adapter->poll($secret, $devices);
            foreach ($values as $deviceId => $v) {
                if (in_array($deviceId, $known, true)) { // ignore devices we do not list (e.g. Nuki Opener)
                    $this->store->saveState($deviceId, $v, 'poll', true);
                }
            }
            $status = ['ok', ''];
        } catch (VendorError $e) {
            $this->store->markUnreachable($vendor);
            $status = ['error', $e->getMessage()];
        }
        if ($secret !== $before) {
            $this->store->saveLink($vendor, $secret, $status[0], $status[1]);
        } else {
            $this->store->setLinkStatus($vendor, $status[0], $status[1]);
        }
    }

    /**
     * @param array<string,mixed> $command
     * @return array{status:string, reason?:string}
     */
    public function command(string $deviceId, array $command, string $expiresAt): array
    {
        $now = ($this->clock)();
        $exp = strtotime($expiresAt);
        if ($exp === false || $exp <= $now) {
            return ['status' => 'failed', 'reason' => 'zu spät, nicht ausgeführt'];
        }
        if (($command['type'] ?? '') === 'unlock' && !in_array($command['confirmedWith'] ?? '', ['biometric', 'pin'], true)) {
            return ['status' => 'failed', 'reason' => 'Aufsperren braucht eine Bestätigung'];
        }
        $device = $this->store->device($deviceId) ?? throw new ApiError(404, 'Unbekanntes Gerät');
        $secret = $this->store->linkSecret($device['vendor']) ?? throw new ApiError(400, 'Hersteller nicht verbunden');
        $before = $secret;
        try {
            $values = $this->adapter($device['vendor'])->execute($secret, $device, $command);
        } catch (VendorError $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        } finally {
            if ($secret !== $before) {
                $this->store->saveLink($device['vendor'], $secret);
            }
        }
        $this->store->logEvent($deviceId, (string) $command['type'], 'app');
        if ($values !== null) {
            $this->store->saveState($deviceId, $values, 'command', true);
            return ['status' => 'done'];
        }
        return ['status' => 'accepted'];
    }
}

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}
