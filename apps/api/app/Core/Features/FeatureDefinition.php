<?php

namespace App\Core\Features;

/**
 * A switch a module declares in its module.json.
 *
 * The full key is "{module}.{name}", e.g. "tenancy.api_access". Its label lives in the
 * module's translations under "{module}::features.{name}.label", in every admin locale.
 */
final readonly class FeatureDefinition
{
    public function __construct(
        public string $module,
        public string $name,
        public bool $default,
    ) {}

    public function key(): string
    {
        return "{$this->module}.{$this->name}";
    }

    public function labelKey(): string
    {
        return "{$this->module}::features.{$this->name}.label";
    }

    public function descriptionKey(): string
    {
        return "{$this->module}::features.{$this->name}.description";
    }
}
