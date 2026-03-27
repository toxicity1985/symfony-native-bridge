<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

/**
 * Fired when the OS delivers a deep-link URL to the application.
 *
 * Example: the user clicks `myapp://dashboard?ref=email` in a browser or email client.
 *
 * The URL is pre-parsed so you can react without calling parse_url() yourself:
 *
 *     #[AsNativeListener(DeepLinkReceivedEvent::class)]
 *     public function onDeepLink(DeepLinkReceivedEvent $event): void
 *     {
 *         if ($event->host === 'dashboard') {
 *             $this->windowManager->openRoute('dashboard');
 *         }
 *     }
 */
final class DeepLinkReceivedEvent extends NativeEvent
{
    public const NAME = 'native.protocol.deep_link';

    public readonly string $scheme;
    public readonly string $host;
    public readonly string $path;
    /** @var array<string, string> */
    public readonly array $query;

    public function __construct(public readonly string $url)
    {
        $parsed = parse_url($url);

        $this->scheme = $parsed['scheme'] ?? '';
        $this->host   = $parsed['host']   ?? '';
        $this->path   = $parsed['path']   ?? '';

        parse_str($parsed['query'] ?? '', $query);
        $this->query = $query;
    }

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
