<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Attribute;

use Attribute;
use SymfonyNativeBridge\Event\NativeEvent;

/**
 * Marks a method (or class) as a listener for a native desktop event.
 *
 * Usage:
 *
 *     #[AsNativeListener(AppReadyEvent::class)]
 *     class BootstrapTrayListener
 *     {
 *         public function __invoke(AppReadyEvent $event): void { … }
 *     }
 *
 * It automatically sets the event name from the NativeEvent class method `getEventName()`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class AsNativeListener
{
    public string $event;

    /**
     * @param class-string<NativeEvent> $eventClass
     */
    public function __construct(
        string         $eventClass,
        public int     $priority = 0,
        public ?string $method = null,
    ) {
        // Force autoload
        if (!class_exists($eventClass)) {
            throw new \InvalidArgumentException(
                "Class {$eventClass} does not exist. Is it a valid NativeEvent subclass?"
            );
        }

        // Ensure it's a NativeEvent subclass
        if (!is_subclass_of($eventClass, NativeEvent::class)) {
            throw new \InvalidArgumentException(
                "Class {$eventClass} must extend " . NativeEvent::class
            );
        }

        // Use the abstract method to get the event name
        $this->event = $eventClass::getEventName();
    }
}