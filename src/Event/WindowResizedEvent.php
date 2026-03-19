<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class WindowResizedEvent extends NativeEvent
{
    public const NAME = 'native.window.resized';

    public function __construct(
        public readonly string $windowId,
        public readonly int $width,
        public readonly int $height,
    ) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}