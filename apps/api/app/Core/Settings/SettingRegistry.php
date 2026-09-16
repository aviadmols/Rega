<?php

namespace App\Core\Settings;

use InvalidArgumentException;

/**
 * Every setting the enabled modules declared. Filled once at boot, read-only after.
 */
final class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function register(SettingDefinition $definition): void
    {
        $key = $definition->key();

        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Setting [{$key}] is declared twice.");
        }

        $this->definitions[$key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw UnknownSetting::named($key);
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return array<string, SettingDefinition> */
    public function forModule(string $module): array
    {
        return array_filter($this->definitions, fn (SettingDefinition $d) => $d->module === $module);
    }
}
