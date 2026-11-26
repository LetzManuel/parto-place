<?php
declare(strict_types=1);

namespace Place;

use PDO;

/** Database access. SQL kept to what MySQL/MariaDB and SQLite (tests) both understand. */
final class Store
{
    public function __construct(private PDO $db, private Crypto $crypto)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    // ---- vendor links (encrypted secrets) ----

    /** @param array<string,mixed> $secret */
    public function saveLink(string $vendor, array $secret, string $status = 'ok', string $error = ''): void
    {
        $this->db->prepare('REPLACE INTO vendor_link (vendor, secret, status, error, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$vendor, $this->crypto->seal($secret), $status, $error, self::now()]);
    }

    /** @return array<string,mixed>|null */
    public function linkSecret(string $vendor): ?array
    {
        $st = $this->db->prepare('SELECT secret FROM vendor_link WHERE vendor = ?');
        $st->execute([$vendor]);
        $row = $st->fetch();
        return $row ? $this->crypto->open($row['secret']) : null;
    }

    public function setLinkStatus(string $vendor, string $status, string $error = ''): void
    {
        $this->db->prepare('UPDATE vendor_link SET status = ?, error = ?, updated_at = ? WHERE vendor = ?')
            ->execute([$status, $error, self::now(), $vendor]);
    }

    /** @return list<array{vendor:string,status:string,error:string,updated_at:string}> */
    public function links(): array
    {
        return $this->db->query('SELECT vendor, status, error, updated_at FROM vendor_link ORDER BY vendor')->fetchAll();
    }

    /** Disconnecting deletes the secret at once, plus its devices and states. */
    public function deleteLink(string $vendor): void
    {
        $this->db->prepare('DELETE FROM vendor_link WHERE vendor = ?')->execute([$vendor]);
        $this->db->prepare('DELETE FROM device_state WHERE device_id IN (SELECT id FROM device WHERE vendor = ?)')->execute([$vendor]);
        $this->db->prepare('DELETE FROM device WHERE vendor = ?')->execute([$vendor]);
    }

    // ---- devices and states ----

    /** @param list<array{id:string,vendor:string,kind:string,name:string,ref:string}> $devices */
    public function replaceDevices(string $vendor, array $devices): void
    {
        $keep = array_column($devices, 'id');
        foreach ($this->devices($vendor) as $old) {
            if (!in_array($old['id'], $keep, true)) {
                $this->db->prepare('DELETE FROM device_state WHERE device_id = ?')->execute([$old['id']]);
                $this->db->prepare('DELETE FROM device WHERE id = ?')->execute([$old['id']]);
            }
        }
        $up = $this->db->prepare('REPLACE INTO device (id, vendor, kind, name, room, ref) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($devices as $d) {
            $room = $this->deviceRoom($d['id']);
            $up->execute([$d['id'], $vendor, $d['kind'], $d['name'], $room, $d['ref']]);
        }
    }

    private function deviceRoom(string $id): string
    {
        $st = $this->db->prepare('SELECT room FROM device WHERE id = ?');
        $st->execute([$id]);
        return (string) ($st->fetchColumn() ?: '');
    }

    /** @return list<array<string,string>> */
    public function devices(?string $vendor = null): array
    {
        if ($vendor === null) {
            return $this->db->query('SELECT id, vendor, kind, name, room, ref FROM device ORDER BY name')->fetchAll();
        }
        $st = $this->db->prepare('SELECT id, vendor, kind, name, room, ref FROM device WHERE vendor = ? ORDER BY name');
        $st->execute([$vendor]);
        return $st->fetchAll();
    }

    /** @return array<string,string>|null */
    public function device(string $id): ?array
    {
        $st = $this->db->prepare('SELECT id, vendor, kind, name, room, ref FROM device WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $values */
    public function saveState(string $deviceId, array $values, string $source, bool $reachable, ?string $observedAt = null): void
    {
        $this->db->prepare('REPLACE INTO device_state (device_id, vals, observed_at, source, reachable) VALUES (?, ?, ?, ?, ?)')
            ->execute([$deviceId, json_encode($values, JSON_THROW_ON_ERROR), $observedAt ?? self::now(), $source, $reachable ? 1 : 0]);
    }

    /** @return list<array<string,mixed>> */
    public function states(): array
    {
        $rows = $this->db->query('SELECT device_id, vals, observed_at, source, reachable FROM device_state')->fetchAll();
        return array_map(fn ($r) => [
            'deviceId' => $r['device_id'],
            'values' => json_decode($r['vals'], true),
            'observedAt' => $r['observed_at'],
            'source' => $r['source'],
            'reachable' => (bool) $r['reachable'],
        ], $rows);
    }

    public function markUnreachable(string $vendor): void
    {
        $this->db->prepare('UPDATE device_state SET reachable = 0 WHERE device_id IN (SELECT id FROM device WHERE vendor = ?)')->execute([$vendor]);
    }

    public function setRoom(string $deviceId, string $room): void
    {
        $this->db->prepare('UPDATE device SET room = ? WHERE id = ?')->execute([$room, $deviceId]);
    }

    // ---- history ----

    public function logEvent(string $deviceId, string $what, string $how): void
    {
        $this->db->prepare('INSERT INTO home_event (at, device_id, what, how) VALUES (?, ?, ?, ?)')
            ->execute([self::now(), $deviceId, $what, $how]);
    }

    /** @return list<array<string,string>> */
    public function events(int $limit = 50): array
    {
        $st = $this->db->prepare('SELECT at, device_id, what, how FROM home_event ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $st->execute();
        return $st->fetchAll();
    }

    // ---- OAuth (Spotify PKCE) ----

    public function saveOauth(string $state, string $vendor, string $verifier, string $clientId): void
    {
        $this->db->prepare('DELETE FROM oauth_pending WHERE created_at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - 900)]);
        $this->db->prepare('INSERT INTO oauth_pending (state, vendor, verifier, client_id, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$state, $vendor, $this->crypto->seal(['v' => $verifier]), $clientId, self::now()]);
    }

    /** @return array{vendor:string,verifier:string,client_id:string}|null */
    public function takeOauth(string $state): ?array
    {
        $st = $this->db->prepare('SELECT vendor, verifier, client_id, created_at FROM oauth_pending WHERE state = ?');
        $st->execute([$state]);
        $row = $st->fetch();
        $this->db->prepare('DELETE FROM oauth_pending WHERE state = ?')->execute([$state]);
        if (!$row || strtotime($row['created_at']) < time() - 900) {
            return null;
        }
        return ['vendor' => $row['vendor'], 'verifier' => $this->crypto->open($row['verifier'])['v'], 'client_id' => $row['client_id']];
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
