<?php
declare(strict_types=1);

namespace Place\Adapters;

/**
 * One adapter per vendor. Adapters never store or log secrets themselves;
 * they get them from the encrypted store and may return a refreshed secret.
 */
interface Adapter
{
    public function vendor(): string;

    /**
     * Check the secret and list the vendor's devices.
     * @param array<string,mixed> $secret
     * @return list<array{id:string,vendor:string,kind:string,name:string,ref:string}>
     */
    public function discover(array &$secret): array;

    /**
     * Read the current state of the given devices.
     * @param array<string,mixed> $secret may be updated (token refresh)
     * @param list<array<string,string>> $devices
     * @return array<string,array<string,mixed>> deviceId => values
     */
    public function poll(array &$secret, array $devices): array;

    /**
     * Run a command. Returns the new values when known right away, or null
     * when the vendor only accepted it (confirmation comes with the next poll).
     * @param array<string,mixed> $secret
     * @param array<string,string> $device
     * @param array<string,mixed> $command
     * @return array<string,mixed>|null
     */
    public function execute(array &$secret, array $device, array $command): ?array;
}
