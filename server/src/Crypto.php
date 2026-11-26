<?php
declare(strict_types=1);

namespace Place;

/** Encrypts vendor secrets at rest (libsodium secretbox). The key lives only in config.php. */
final class Crypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('encryption_key in config.php is invalid');
        }
        $this->key = $key;
    }

    public static function newKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /** @param array<string,mixed> $data */
    public function seal(array $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, $this->key);
        return base64_encode($nonce . $box);
    }

    /** @return array<string,mixed> */
    public function open(string $sealed): array
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('secret unreadable');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('secret unreadable');
        }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
}
