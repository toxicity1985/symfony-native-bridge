<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class WindowMovedEvent extends NativeEvent
{
    public const NAME = 'native.window.moved';

    public function __construct(
        public readonly string $windowId,
        public readonly int $x,
        public readonly int $y,
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}
