<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;
final class AppActivatedEvent extends NativeEvent
{
    public const NAME = 'native.app.activated';

    /** @param bool $hasVisibleWindows macOS-specific */
    public function __construct(public readonly bool $hasVisibleWindows = false) {}

    public static function getEventName(): string
    {
        return self::NAME;
    }
}