<?php

namespace App\Core\Features;

use InvalidArgumentException;

/**
 * Every feature flag the enabled modules declared. Filled once at boot, read-only after.
 */
final class FeatureRegistry
{
    /** @var array<string, FeatureDefinition> */
    private array $definitions = [];

    public function register(FeatureDefinition $definition): void
    {
        $key = $definition->key();

        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Feature [{$key}] is declared twice.");
        }

        $this->definitions[$key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): FeatureDefinition
    {
        return $this->definitions[$key] ?? throw UnknownFeature::named($key);
    }

    /** @return array<string, FeatureDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /** @return array<string, FeatureDefinition> */
    public function forModule(string $module): array
    {
        return array_filter($this->definitions, fn (FeatureDefinition $d) => $d->module === $module);
    }
}
