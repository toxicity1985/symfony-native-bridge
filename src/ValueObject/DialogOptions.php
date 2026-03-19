<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\ValueObject;

final class DialogOptions
{
    /**
     * @param array<array{name: string, extensions: string[]}> $filters
     * @param string[] $properties  e.g. ['openFile', 'multiSelections', 'openDirectory']
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $defaultPath = null,
        public readonly array $filters = [],
        public readonly array $properties = ['openFile'],
        public readonly ?string $buttonLabel = null,
        public readonly ?string $message = null,
    ) {}
}
