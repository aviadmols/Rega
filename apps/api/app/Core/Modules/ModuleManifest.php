<?php

namespace App\Core\Modules;

use App\Core\Features\FeatureDefinition;
use App\Core\Modules\Exceptions\InvalidModuleManifest;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingScope;
use App\Core\Settings\SettingType;
use Illuminate\Support\Str;
use JsonException;

/**
 * What a module says about itself in app/Modules/{Name}/module.json.
 *
 * The file is the single place a module declares what it adds to the system, so nobody
 * has to remember to update a central list when a module is added or removed.
 */
final readonly class ModuleManifest
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  list<string>  $requires
     * @param  list<FeatureDefinition>  $features
     * @param  list<SettingDefinition>  $settings
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $path,
        public string $namespace,
        public bool $enabled,
        public array $requires,
        public ?string $provider,
        public array $features,
        public array $settings,
    ) {}

    public static function fromFile(string $file, string $baseNamespace): self
    {
        $directory = dirname($file);
        $name = basename($directory);

        try {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidModuleManifest::because($file, 'not valid JSON ('.$e->getMessage().')');
        }

        if (! is_array($data)) {
            throw InvalidModuleManifest::because($file, 'must be a JSON object');
        }

        if (($data['name'] ?? null) !== $name) {
            throw InvalidModuleManifest::because($file, "\"name\" must equal the directory name [{$name}]");
        }

        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw InvalidModuleManifest::because($file, 'module name must be StudlyCase');
        }

        $slug = Str::snake($name);
        $namespace = trim($baseNamespace, '\\').'\\'.$name;

        $requires = $data['requires'] ?? [];
        if (! is_array($requires) || ! array_is_list($requires) || array_filter($requires, fn ($r) => ! is_string($r)) !== []) {
            throw InvalidModuleManifest::because($file, '"requires" must be a list of module names');
        }

        $provider = $data['provider'] ?? null;
        if ($provider !== null && (! is_string($provider) || ! preg_match('/^[A-Z][A-Za-z0-9\\\\]*$/', $provider))) {
            throw InvalidModuleManifest::because($file, '"provider" must be a class name relative to the module namespace');
        }

        return new self(
            name: $name,
            slug: $slug,
            path: $directory,
            namespace: $namespace,
            enabled: (bool) ($data['enabled'] ?? true),
            requires: array_values(array_unique($requires)),
            provider: $provider === null ? null : $namespace.'\\'.$provider,
            features: self::parseFeatures($file, $slug, $data['features'] ?? []),
            settings: self::parseSettings($file, $slug, $data['settings'] ?? []),
        );
    }

    public function path(string $relative = ''): string
    {
        if ($relative === '') {
            return $this->path;
        }

        return $this->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function nameKey(): string
    {
        return "{$this->slug}::module.name";
    }

    /** @return list<FeatureDefinition> */
    private static function parseFeatures(string $file, string $slug, mixed $features): array
    {
        if (! is_array($features) || ! array_is_list($features)) {
            throw InvalidModuleManifest::because($file, '"features" must be a list');
        }

        return array_map(function (mixed $feature) use ($file, $slug): FeatureDefinition {
            if (! is_array($feature) || ! is_string($feature['name'] ?? null) || ! preg_match(self::NAME_PATTERN, $feature['name'])) {
                throw InvalidModuleManifest::because($file, 'every feature needs a snake_case "name"');
            }

            if (! is_bool($feature['default'] ?? null)) {
                throw InvalidModuleManifest::because($file, "feature [{$feature['name']}] needs a boolean \"default\"");
            }

            return new FeatureDefinition($slug, $feature['name'], $feature['default']);
        }, $features);
    }

    /** @return list<SettingDefinition> */
    private static function parseSettings(string $file, string $slug, mixed $settings): array
    {
        if (! is_array($settings) || ! array_is_list($settings)) {
            throw InvalidModuleManifest::because($file, '"settings" must be a list');
        }

        return array_map(fn (mixed $setting): SettingDefinition => self::parseSetting($file, $slug, $setting), $settings);
    }

    private static function parseSetting(string $file, string $slug, mixed $setting): SettingDefinition
    {
        if (! is_array($setting) || ! is_string($setting['name'] ?? null) || ! preg_match(self::NAME_PATTERN, $setting['name'])) {
            throw InvalidModuleManifest::because($file, 'every setting needs a snake_case "name"');
        }

        $name = $setting['name'];

        $type = SettingType::tryFrom((string) ($setting['type'] ?? ''))
            ?? throw InvalidModuleManifest::because($file, "setting [{$name}] has an unknown \"type\"");

        $scope = SettingScope::tryFrom((string) ($setting['scope'] ?? SettingScope::Shop->value))
            ?? throw InvalidModuleManifest::because($file, "setting [{$name}] has an unknown \"scope\"");

        foreach (['min', 'max'] as $bound) {
            if (isset($setting[$bound]) && ! is_int($setting[$bound]) && ! is_float($setting[$bound])) {
                throw InvalidModuleManifest::because($file, "setting [{$name}] \"{$bound}\" must be a number");
            }
        }

        if (isset($setting['min'], $setting['max']) && $setting['min'] > $setting['max']) {
            throw InvalidModuleManifest::because($file, "setting [{$name}] has min greater than max");
        }

        $options = $setting['options'] ?? [];
        $validOptions = is_array($options) && array_filter($options, fn ($o) => ! is_string($o)) === [];
        if (! $validOptions || ($type === SettingType::Enum && $options === [])) {
            throw InvalidModuleManifest::because($file, "setting [{$name}] \"options\" must be a non-empty list of strings for enum settings");
        }

        if (! array_key_exists('default', $setting) || ! is_scalar($setting['default'])) {
            throw InvalidModuleManifest::because($file, "setting [{$name}] needs a scalar \"default\"");
        }

        $draft = new SettingDefinition(
            module: $slug,
            name: $name,
            type: $type,
            default: $setting['default'],
            scope: $scope,
            min: $setting['min'] ?? null,
            max: $setting['max'] ?? null,
            options: array_values($options),
            unit: isset($setting['unit']) ? (string) $setting['unit'] : null,
        );

        try {
            // The default must satisfy its own rules, or the system would boot into a state
            // the admin panel refuses to save.
            $default = $draft->normalize($setting['default']);
        } catch (InvalidSettingValue $e) {
            throw InvalidModuleManifest::because($file, "setting [{$name}] default is invalid ({$e->reason})");
        }

        return new SettingDefinition(
            module: $slug,
            name: $name,
            type: $type,
            default: $default,
            scope: $scope,
            min: $draft->min,
            max: $draft->max,
            options: $draft->options,
            unit: $draft->unit,
        );
    }
}
