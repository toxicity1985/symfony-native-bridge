<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Exception\NativeException;

/**
 * Holds the mapping of native route names → Symfony route names + window option overrides.
 *
 * Populated at container compile time by NativeRoutePass.
 * Consumed at runtime by WindowManager::openRoute().
 */
class NativeRouteRegistry
{
    /** @var array<string, array{route: string, options: array<string, mixed>}> */
    private array $routes = [];

    /**
     * Called by NativeRoutePass via addMethodCall() during container compilation.
     *
     * @param array<string, mixed> $options Partial WindowOptions overrides (null values ignored)
     */
    public function register(string $name, string $route, array $options): void
    {
        $this->routes[$name] = [
            'route'   => $route,
            'options' => array_filter($options, fn($v) => $v !== null),
        ];
    }

    /**
     * @return array{route: string, options: array<string, mixed>}
     * @throws NativeException When the native route name is not registered
     */
    public function get(string $name): array
    {
        if (!isset($this->routes[$name])) {
            throw new NativeException(
                "Native route '{$name}' is not registered. " .
                "Did you forget #[NativeRoute('{$name}')] on a controller?"
            );
        }

        return $this->routes[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
    }

    /** @return array<string, array{route: string, options: array<string, mixed>}> */
    public function all(): array
    {
        return $this->routes;
    }
}
