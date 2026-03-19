<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use SymfonyNativeBridge\Attribute\AsNativeListener;

/**
 * Reads #[AsNativeListener] attributes from all tagged services and
 * registers them as kernel.event_listener entries so Symfony's
 * EventDispatcher picks them up automatically — no YAML needed.
 */
class NativeListenerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflClass = new \ReflectionClass($class);

            // Class-level attribute  →  __invoke method
            foreach ($reflClass->getAttributes(AsNativeListener::class) as $attr) {
                /** @var AsNativeListener $instance */
                $instance = $attr->newInstance();
                $definition->addTag('kernel.event_listener', [
                    'event'    => $instance->event,
                    'method'   => $instance->method ?? '__invoke',
                    'priority' => $instance->priority,
                ]);
            }

            // Method-level attributes
            foreach ($reflClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(AsNativeListener::class) as $attr) {
                    /** @var AsNativeListener $instance */
                    $instance = $attr->newInstance();
                    $definition->addTag('kernel.event_listener', [
                        'event'    => $instance->event,
                        'method'   => $instance->method ?? $method->getName(),
                        'priority' => $instance->priority,
                    ]);
                }
            }
        }
    }
}
