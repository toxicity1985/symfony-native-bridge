<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;

readonly class StorageManager
{
    public function __construct(
        private NativeDriverInterface $driver,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->driver->storeGet($key);

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->driver->storeSet($key, $value);
    }

    public function delete(string $key): void
    {
        $this->driver->storeDelete($key);
    }

    public function has(string $key): bool
    {
        return $this->driver->storeGet($key) !== null;
    }

    public function remember(string $key, \Closure $callback): mixed
    {
        $existing = $this->driver->storeGet($key);
        if ($existing !== null) {
            return $existing;
        }

        $value = $callback();
        $this->driver->storeSet($key, $value);

        return $value;
    }
}

