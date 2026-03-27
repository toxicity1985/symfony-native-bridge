<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;

/**
 * Registers custom URL scheme handlers (deep-links) with the OS.
 *
 * After registration the OS will launch your application when the user
 * clicks a link with the matching scheme (e.g. `myapp://...`).
 * The bridge will then fire a DeepLinkReceivedEvent so your PHP code
 * can react to the URL.
 *
 * Usage — register on app ready:
 *
 *     #[AsNativeListener(AppReadyEvent::class)]
 *     public function onReady(): void
 *     {
 *         $this->protocols->register('myapp');
 *     }
 *
 * Usage — react to incoming deep-links:
 *
 *     #[AsNativeListener(DeepLinkReceivedEvent::class)]
 *     public function onDeepLink(DeepLinkReceivedEvent $event): void
 *     {
 *         // $event->url    → "myapp://dashboard?ref=email"
 *         // $event->scheme → "myapp"
 *         // $event->host   → "dashboard"
 *         // $event->query  → ['ref' => 'email']
 *     }
 */
class ProtocolManager
{
    /** @var string[] */
    private array $registered = [];

    public function __construct(
        private readonly NativeDriverInterface $driver,
    ) {}

    /**
     * Register a custom URL scheme as the default handler for this application.
     * Safe to call multiple times — duplicates are silently ignored.
     */
    public function register(string $scheme): void
    {
        if (in_array($scheme, $this->registered, true)) {
            return;
        }

        $this->driver->registerProtocol($scheme);
        $this->registered[] = $scheme;
    }

    /**
     * Unregister a previously registered URL scheme.
     */
    public function unregister(string $scheme): void
    {
        $this->driver->unregisterProtocol($scheme);
        $this->registered = array_values(
            array_filter($this->registered, fn($s) => $s !== $scheme)
        );
    }

    /** @return string[] */
    public function getRegistered(): array
    {
        return $this->registered;
    }
}
