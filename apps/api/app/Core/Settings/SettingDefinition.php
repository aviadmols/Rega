<?php

namespace App\Core\Settings;

/**
 * A tunable value a module declares in its module.json: a cap, a threshold, a choice.
 *
 * Every value that reaches storage passes through normalize(), so a cap can never be
 * saved outside its declared range, whether it came from the admin panel, a command
 * or code.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string>  $options  allowed values for enum settings
     */
    public function __construct(
        public string $module,
        public string $name,
        public SettingType $type,
        public int|float|bool|string $default,
        public SettingScope $scope,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public array $options = [],
        public ?string $unit = null,
    ) {}

    public function key(): string
    {
        return "{$this->module}.{$this->name}";
    }

    public function labelKey(): string
    {
        return "{$this->module}::settings.{$this->name}.label";
    }

    public function descriptionKey(): string
    {
        return "{$this->module}::settings.{$this->name}.description";
    }

    public function isShopOverridable(): bool
    {
        return $this->scope === SettingScope::Shop;
    }

    /**
     * Coerce a raw value (a form string, a JSON scalar) into the declared type and check
     * it against the declared bounds.
     *
     * @throws InvalidSettingValue
     */
    public function normalize(mixed $value): int|float|bool|string
    {
        return match ($this->type) {
            SettingType::Int => $this->bounded($this->toInt($value)),
            SettingType::Float => $this->bounded($this->toFloat($value)),
            SettingType::Bool => $this->toBool($value),
            SettingType::String => $this->toString($value),
            SettingType::Enum => $this->toOption($value),
        };
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw InvalidSettingValue::for($this->key(), 'not_integer');
    }

    private function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw InvalidSettingValue::for($this->key(), 'not_number');
    }

    private function toBool(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => throw InvalidSettingValue::for($this->key(), 'not_boolean'),
        };
    }

    private function toString(mixed $value): string
    {
        if (! is_string($value)) {
            throw InvalidSettingValue::for($this->key(), 'not_string');
        }

        $length = mb_strlen($value);

        if ($this->max !== null && $length > $this->max) {
            throw InvalidSettingValue::for($this->key(), 'too_long', ['max' => $this->max]);
        }

        if ($this->min !== null && $length < $this->min) {
            throw InvalidSettingValue::for($this->key(), 'too_short', ['min' => $this->min]);
        }

        return $value;
    }

    private function toOption(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, $this->options, true)) {
            throw InvalidSettingValue::for($this->key(), 'not_option', ['options' => implode(', ', $this->options)]);
        }

        return $value;
    }

    private function bounded(int|float $value): int|float
    {
        if ($this->min !== null && $value < $this->min) {
            throw InvalidSettingValue::for($this->key(), 'below_min', ['min' => $this->min]);
        }

        if ($this->max !== null && $value > $this->max) {
            throw InvalidSettingValue::for($this->key(), 'above_max', ['max' => $this->max]);
        }

        return $value;
    }
}
