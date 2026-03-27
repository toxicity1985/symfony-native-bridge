<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyNativeBridge\Attribute\NativeRoute;
use SymfonyNativeBridge\Service\NativeRouteRegistry;

/**
 * Scans all service definitions for controllers annotated with #[NativeRoute]
 * and registers them in NativeRouteRegistry via addMethodCall().
 *
 * Route name resolution order:
 *   1. Explicit `route` parameter on #[NativeRoute]
 *   2. First #[Route(name: '…')] found on a public method of the controller
 *
 * @throws \LogicException When no Symfony route name can be determined
 */
class NativeRoutePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(NativeRouteRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(NativeRouteRegistry::class);

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflClass = new \ReflectionClass($class);
            $attrs     = $reflClass->getAttributes(NativeRoute::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var NativeRoute $nativeRoute */
            $nativeRoute = $attrs[0]->newInstance();

            $routeName = $nativeRoute->route !== ''
                ? $nativeRoute->route
                : $this->detectRouteName($reflClass);

            $registry->addMethodCall('register', [
                $nativeRoute->name,
                $routeName,
                [
                    'title'       => $nativeRoute->title,
                    'width'       => $nativeRoute->width,
                    'height'      => $nativeRoute->height,
                    'resizable'   => $nativeRoute->resizable,
                    'frame'       => $nativeRoute->frame,
                    'transparent' => $nativeRoute->transparent,
                    'alwaysOnTop' => $nativeRoute->alwaysOnTop,
                    'label'       => $nativeRoute->label,
                ],
            ]);
        }
    }

    /**
     * Returns the first named Symfony route found on any public method of the controller.
     *
     * @throws \LogicException When no named route can be found
     */
    private function detectRouteName(\ReflectionClass $reflClass): string
    {
        foreach ($reflClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attr) {
                /** @var Route $route */
                $route = $attr->newInstance();
                if ($route->getName() !== null && $route->getName() !== '') {
                    return $route->getName();
                }
            }
        }

        throw new \LogicException(
            "Cannot auto-detect a Symfony route name for #[NativeRoute('{$reflClass->getName()}')]. " .
            "Either add a `name` parameter to a #[Route] method, or pass `route: 'your_route'` " .
            "explicitly to #[NativeRoute]."
        );
    }
}
