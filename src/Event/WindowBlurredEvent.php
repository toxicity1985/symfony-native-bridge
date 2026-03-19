<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class WindowBlurredEvent extends NativeEvent
{
    public const NAME = 'native.window.blurred';

    public function __construct(public readonly string $windowId) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}