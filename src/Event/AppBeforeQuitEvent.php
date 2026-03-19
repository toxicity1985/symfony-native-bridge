<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

final class AppBeforeQuitEvent extends NativeEvent
{
    public const NAME = 'native.app.before_quit';

    private bool $prevented = false;

    public function prevent(): void
    {
        $this->prevented = true;
    }

    public function isPrevented(): bool
    {
        return $this->prevented;
    }

    public static function getEventName(): string
    {
        return self::NAME;
    }
}