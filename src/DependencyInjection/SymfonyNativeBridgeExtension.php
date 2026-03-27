<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\AbstractExtension;
use SymfonyNativeBridge\Bridge\IpcBridge;
use SymfonyNativeBridge\Bridge\SymfonyEventBridgeIpcBridge;
use SymfonyNativeBridge\Driver\NativeDriverInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class SymfonyNativeBridgeExtension extends AbstractExtension
{
    public function getAlias(): string
    {
        return 'symfony_native_bridge';
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->enumNode('driver')
                    ->values(['electron', 'tauri'])
                    ->defaultValue('electron')
                    ->info('The native runtime driver to use (electron or tauri)')
                ->end()
                ->booleanNode('strict')
                    ->defaultFalse()
                    ->info('When true, throws typed exceptions instead of silent null when runtime is absent or crashed')
                ->end()
                ->arrayNode('app')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')->defaultValue('Symfony Native App')->end()
                        ->scalarNode('version')->defaultValue('1.0.0')->end()
                        ->scalarNode('description')->defaultValue('A Symfony desktop application')->end()
                        ->scalarNode('author')->defaultValue('')->end()
                        ->scalarNode('identifier')->defaultValue('com.example.symfony-native')->end()
                        ->scalarNode('icon')->defaultValue(null)->end()
                    ->end()
                ->end()
                ->arrayNode('window')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('width')->defaultValue(1200)->end()
                        ->integerNode('height')->defaultValue(800)->end()
                        ->integerNode('min_width')->defaultValue(800)->end()
                        ->integerNode('min_height')->defaultValue(600)->end()
                        ->booleanNode('resizable')->defaultTrue()->end()
                        ->booleanNode('fullscreen')->defaultFalse()->end()
                        ->booleanNode('frame')->defaultTrue()->end()
                        ->booleanNode('transparent')->defaultFalse()->end()
                        ->scalarNode('background_color')->defaultValue('#ffffff')->end()
                    ->end()
                ->end()
                ->arrayNode('updater')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('url')->defaultNull()->end()
                        ->booleanNode('auto_check')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('build')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('output_dir')->defaultValue('dist')->end()
                        ->arrayNode('targets')
                            ->scalarPrototype()->end()
                            ->defaultValue(['current'])
                        ->end()
                        ->booleanNode('php_binary_embedded')->defaultTrue()->end()
                        ->scalarNode('php_binary_path')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, \Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Store config as parameters
        $container->parameters()
            ->set('symfony_native_bridge.driver', $config['driver'])
            ->set('symfony_native_bridge.app', $config['app'])
            ->set('symfony_native_bridge.window', $config['window'])
            ->set('symfony_native_bridge.updater', $config['updater'])
            ->set('symfony_native_bridge.build', $config['build']);

        // IPC Bridge — use the Symfony-aware subclass that dispatches events
        $services->set(SymfonyEventBridgeIpcBridge::class)
            ->arg('$driver', $config['driver'])
            ->arg('$eventDispatcher', service('event_dispatcher'))
            ->arg('$logger', service('logger')->nullOnInvalid())
            ->arg('$strict', $config['strict'])
            ->public()
        ;

        // Alias base class → subclass so drivers can type-hint IpcBridge
        $services->alias(IpcBridge::class, SymfonyEventBridgeIpcBridge::class)->public();

        // Driver
        $driverClass = match ($config['driver']) {
            'tauri'  => \SymfonyNativeBridge\Driver\TauriDriver::class,
            default  => \SymfonyNativeBridge\Driver\ElectronDriver::class,
        };

        $services->set(NativeDriverInterface::class, $driverClass)
            ->arg('$ipcBridge', service(SymfonyEventBridgeIpcBridge::class))
            ->arg('$config', $config)
            ->public();

        // Native route registry (populated at compile time by NativeRoutePass)
        $services->set(\SymfonyNativeBridge\Service\NativeRouteRegistry::class)
            ->public();

        // Services
        $services->set(\SymfonyNativeBridge\Service\WindowManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->arg('$defaultConfig', $config['window'])
            ->arg('$routeRegistry', service(\SymfonyNativeBridge\Service\NativeRouteRegistry::class)->nullOnInvalid())
            ->arg('$urlGenerator', service('router')->nullOnInvalid())
            ->public();

        $services->set(\SymfonyNativeBridge\Service\TrayManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->public();

        $services->set(\SymfonyNativeBridge\Service\NotificationManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->public();

        $services->set(\SymfonyNativeBridge\Service\DialogManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->public();

        $services->set(\SymfonyNativeBridge\Service\AppManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->arg('$appConfig', $config['app'])
            ->arg('$updaterConfig', $config['updater'])
            ->public();

        $services->set(\SymfonyNativeBridge\Service\StorageManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->public();

        $services->set(\SymfonyNativeBridge\Service\ProtocolManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->public();

        $services->set(\SymfonyNativeBridge\Service\ClipboardManager::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->public();

        // Commands
        $services->set(\SymfonyNativeBridge\Command\NativeServeCommand::class)
            ->arg('$driver',     service(NativeDriverInterface::class))
            ->arg('$appConfig',  $config['app'])
            ->arg('$ipcBridge',  service(\SymfonyNativeBridge\Bridge\SymfonyEventBridgeIpcBridge::class))
            ->arg('$logger',     service('logger')->nullOnInvalid())
            ->tag('console.command');

        $services->set(\SymfonyNativeBridge\Command\NativeBuildCommand::class)
            ->arg('$driver', service(NativeDriverInterface::class))
            ->arg('$buildConfig', $config['build'])
            ->arg('$appConfig', $config['app'])
            ->tag('console.command');

        $services->set(\SymfonyNativeBridge\Command\NativeInstallCommand::class)
            ->arg('$driver', $config['driver'])
            ->arg('$projectDir', '%kernel.project_dir%')
            ->tag('console.command');

        $services->set(\SymfonyNativeBridge\Command\NativePhpEmbedCommand::class)
            ->arg('$projectDir', '%kernel.project_dir%')
            ->tag('console.command');
    }
}
