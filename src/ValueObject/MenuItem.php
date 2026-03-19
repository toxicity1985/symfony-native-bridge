<?php

declare(strict_types=1);

namespace SymfonyNativeBridge\ValueObject;

final readonly class MenuItem
{
    /** @param MenuItem[] $submenu */
    public function __construct(
        public string  $label,
        public ?string $id = null,
        public bool    $enabled = true,
        public bool    $visible = true,
        public ?string $type = null,      // 'separator' | 'checkbox' | 'radio' | null
        public bool    $checked = false,     // for checkbox/radio
        public ?string $accelerator = null,
        public ?string $icon = null,
        public array   $submenu = [],
        public ?string $role = null,      // 'quit' | 'hide' | 'about' | etc.
    ) {}

    public static function separator(): self
    {
        return new self(label: '', type: 'separator');
    }

    public static function checkbox(string $label, bool $checked = false, ?string $id = null): self
    {
        return new self(label: $label, id: $id, type: 'checkbox', checked: $checked);
    }

    public static function submenu(string $label, array $items): self
    {
        return new self(label: $label, submenu: $items);
    }

    public function toArray(): array
    {
        return array_filter([
            'label'       => $this->label,
            'id'          => $this->id,
            'enabled'     => $this->enabled,
            'visible'     => $this->visible,
            'type'        => $this->type,
            'checked'     => $this->checked,
            'accelerator' => $this->accelerator,
            'icon'        => $this->icon,
            'submenu'     => array_map(fn(MenuItem $i) => $i->toArray(), $this->submenu),
            'role'        => $this->role,
        ], fn($v) => $v !== null && $v !== [] && $v !== false);
    }
}
