<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\ValueObject;

readonly final class NotificationOptions
{
    /**
     * @param array<array{action: string, title: string}> $actions
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body = '',
        public readonly ?string $icon = null,
        public readonly bool $sound = true,
        public readonly string $urgency = 'normal',  // 'low' | 'normal' | 'critical'
        public readonly array $actions = [],
        public readonly ?string $tag = null,          // group/replace tag
    ) {}
}