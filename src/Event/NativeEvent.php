<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Event;

use Symfony\Contracts\EventDispatcher\Event;

abstract class NativeEvent extends Event
{
    abstract public static function getEventName(): string;
}