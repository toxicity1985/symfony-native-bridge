<?php

declare(strict_types=1);

namespace SymfonyNativeBridge;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use SymfonyNativeBridge\DependencyInjection\NativeListenerPass;
use SymfonyNativeBridge\DependencyInjection\NativeRoutePass;
use SymfonyNativeBridge\DependencyInjection\SymfonyNativeBridgeExtension;

class SymfonyNativeBridgeBundle extends AbstractBundle
{
    public function getContainerExtension(): SymfonyNativeBridgeExtension
    {
        return new SymfonyNativeBridgeExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new NativeListenerPass());
        $container->addCompilerPass(new NativeRoutePass());
    }
}
