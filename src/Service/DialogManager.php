<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\Service;

use SymfonyNativeBridge\Driver\NativeDriverInterface;
use SymfonyNativeBridge\ValueObject\DialogOptions;

class DialogManager
{
    public function __construct(
        private readonly NativeDriverInterface $driver,
    ) {}

    /**
     * @return string[]|null
     */
    public function openFile(
        string $title = 'Open File',
        ?string $defaultPath = null,
        array $filters = [],
        bool $multiple = false,
    ): ?array {
        $properties = ['openFile'];
        if ($multiple) {
            $properties[] = 'multiSelections';
        }

        return $this->driver->showOpenDialog(new DialogOptions(
            title: $title,
            defaultPath: $defaultPath,
            filters: $filters,
            properties: $properties,
        ));
    }

    public function openFolder(string $title = 'Open Folder', ?string $defaultPath = null): ?array
    {
        return $this->driver->showOpenDialog(new DialogOptions(
            title: $title,
            defaultPath: $defaultPath,
            properties: ['openDirectory'],
        ));
    }

    public function saveFile(
        string $title = 'Save File',
        ?string $defaultPath = null,
        array $filters = [],
    ): ?string {
        return $this->driver->showSaveDialog(new DialogOptions(
            title: $title,
            defaultPath: $defaultPath,
            filters: $filters,
        ));
    }

    public function alert(string $message, string $title = 'Alert'): void
    {
        $this->driver->showMessageBox($title, $message, ['OK'], 'info');
    }

    public function confirm(string $message, string $title = 'Confirm'): bool
    {
        $index = $this->driver->showMessageBox($title, $message, ['Cancel', 'OK'], 'question');

        return $index === 1;
    }

    public function error(string $message, string $title = 'Error'): void
    {
        $this->driver->showMessageBox($title, $message, ['OK'], 'error');
    }

    public function warning(string $message, string $title = 'Warning'): void
    {
        $this->driver->showMessageBox($title, $message, ['OK'], 'warning');
    }

    public function ask(string $message, string $title = '', array $buttons = ['No', 'Yes']): int
    {
        return $this->driver->showMessageBox($title, $message, $buttons, 'question');
    }
}
