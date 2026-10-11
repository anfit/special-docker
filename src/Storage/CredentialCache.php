<?php

declare(strict_types=1);

namespace Special\Storage;

use Aws\CacheInterface;

/**
 * File-backed credential cache for the AWS SDK.
 *
 * PHP serves each request in a fresh process, so without a cache the SDK would call the EC2 metadata
 * service on every request. Instance-profile credentials are short-lived; the SDK refreshes them itself
 * when the cached value is close to expiry, so this only has to persist the opaque value it hands us.
 */
final class CredentialCache implements CacheInterface
{
    public function __construct(private string $path)
    {
    }

    public function get($key)
    {
        $all = $this->read();
        return $all[$key] ?? null;
    }

    public function set($key, $value, $ttl = 0)
    {
        $all = $this->read();
        $all[$key] = $value;
        $temporary = $this->path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, serialize($all), LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $this->path);
        }
    }

    public function remove($key)
    {
        $all = $this->read();
        unset($all[$key]);
        @file_put_contents($this->path, serialize($all), LOCK_EX);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        // Credentials are plain value objects; refuse to instantiate anything else.
        $decoded = @unserialize($raw, ['allowed_classes' => [\Aws\Credentials\Credentials::class]]);

        return is_array($decoded) ? $decoded : [];
    }
}
