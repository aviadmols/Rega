<?php

namespace App\Modules\Enrichment\Vocabulary;

use App\Modules\Enrichment\Scanning\Units;

/**
 * What the agents may say about one branch of a store's catalog: the product types, the specs
 * with their units and sane ranges, the fixed choices (corded or cordless), and the tags.
 * Everything a model returns is checked against this. It is data, edited per store, not code.
 *
 * Patterns are regular expressions matched in code first. A model then confirms or rejects
 * what code found, instead of reading everything from scratch.
 */
final class VocabularyDefinition
{
    public const SCHEMA_VERSION = 1;

    public const RANK_DIRECTIONS = ['min', 'max'];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?self, 1: list<string>} the definition, or null and the problems found
     */
    public static function parse(array $data): array
    {
        $problems = [];

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $problems[] = 'schema_version must be '.self::SCHEMA_VERSION;
        }

        foreach (['key', 'root_category_external_id'] as $required) {
            if (! is_string($data[$required] ?? null) || trim($data[$required]) === '') {
                $problems[] = "{$required} is required";
            }
        }

        if (! is_string($data['name']['he'] ?? null) || ! is_string($data['name']['en'] ?? null)) {
            $problems[] = 'name needs he and en';
        }

        $keys = [];
        foreach ((array) ($data['product_types'] ?? []) as $i => $type) {
            $problems = [...$problems, ...self::checkEntry("product_types[{$i}]", $type, $keys)];
        }

        if (($data['product_types'] ?? []) === []) {
            $problems[] = 'product_types must not be empty';
        }

        foreach ((array) ($data['attributes'] ?? []) as $i => $attribute) {
            $at = "attributes[{$i}]";
            $problems = [...$problems, ...self::checkEntry($at, $attribute, $keys, patternsRequired: false)];
            $type = $attribute['type'] ?? 'number';

            if (! in_array($type, ['number', 'enum', 'boolean'], true)) {
                $problems[] = "{$at}.type must be number, enum or boolean";
            }

            if ($type === 'number') {
                if (! array_key_exists((string) ($attribute['dimension'] ?? ''), Units::CANONICAL)) {
                    $problems[] = "{$at}.dimension must be one of: ".implode(', ', array_keys(Units::CANONICAL));
                }
                if (! is_numeric($attribute['min'] ?? null) || ! is_numeric($attribute['max'] ?? null) || (float) $attribute['min'] > (float) $attribute['max']) {
                    $problems[] = "{$at} needs numeric min <= max";
                }
            }

            if ($type === 'enum') {
                $valueKeys = [];
                foreach ((array) ($attribute['values'] ?? []) as $j => $value) {
                    $problems = [...$problems, ...self::checkEntry("{$at}.values[{$j}]", $value, $valueKeys)];
                }
                if (count($valueKeys) < 2) {
                    $problems[] = "{$at}.values needs at least two values";
                }
            }

            if ($type === 'boolean') {
                $problems = [...$problems, ...self::checkPatterns("{$at}.patterns", $attribute['patterns'] ?? null, required: true)];
            }

            if (isset($attribute['rank']) && ! in_array($attribute['rank'], self::RANK_DIRECTIONS, true)) {
                $problems[] = "{$at}.rank must be min, max or absent";
            }
        }

        $tagKeys = [];
        foreach ((array) ($data['tags'] ?? []) as $i => $tag) {
            $problems = [...$problems, ...self::checkEntry("tags[{$i}]", $tag, $tagKeys)];
        }

        $attributeKeys = array_map(fn ($a): string => (string) ($a['key'] ?? ''), (array) ($data['attributes'] ?? []));
        foreach (['set_by', 'price_set_by'] as $facet) {
            foreach ((array) ($data['comparison'][$facet] ?? []) as $key) {
                if (! in_array($key, $attributeKeys, true)) {
                    $problems[] = "comparison.{$facet}: unknown attribute {$key}";
                }
            }
        }

        return $problems === [] ? [new self($data), []] : [null, $problems];
    }

    /** @param array<string, mixed> $data */
    public static function fromTrusted(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function key(): string
    {
        return (string) $this->data['key'];
    }

    public function name(string $locale): string
    {
        return (string) ($this->data['name'][$locale] ?? $this->data['name']['en'] ?? $this->key());
    }

    public function rootCategoryExternalId(): string
    {
        return (string) $this->data['root_category_external_id'];
    }

    /** @return list<string> */
    public function excludedCategoryExternalIds(): array
    {
        return array_values(array_map('strval', (array) ($this->data['excluded_category_external_ids'] ?? [])));
    }

    /** @return list<array{key: string, label: array<string, string>, patterns?: list<string>, hint?: string}> */
    public function productTypes(): array
    {
        return array_values((array) $this->data['product_types']);
    }

    /** @return list<array<string, mixed>> */
    public function attributes(): array
    {
        return array_values((array) ($this->data['attributes'] ?? []));
    }

    /** @return array<string, mixed>|null */
    public function attribute(string $key): ?array
    {
        foreach ($this->attributes() as $attribute) {
            if ($attribute['key'] === $key) {
                return $attribute + ['type' => 'number'];
            }
        }

        return null;
    }

    /** @return list<array{key: string, label: array<string, string>, patterns?: list<string>, hint?: string}> */
    public function tags(): array
    {
        return array_values((array) ($this->data['tags'] ?? []));
    }

    public function hasProductType(string $key): bool
    {
        return in_array($key, array_column($this->productTypes(), 'key'), true);
    }

    public function hasTag(string $key): bool
    {
        return in_array($key, array_column($this->tags(), 'key'), true);
    }

    /** Whether an attribute applies to a product type; no list means every type. */
    public function appliesTo(string $attributeKey, ?string $productType): bool
    {
        $applies = (array) ($this->attribute($attributeKey)['applies_to'] ?? []);

        return $applies === [] || $productType === null || in_array($productType, $applies, true);
    }

    /** @return list<string> attributes whose values split products into comparable sets */
    public function setBy(): array
    {
        return array_values((array) ($this->data['comparison']['set_by'] ?? []));
    }

    /** @return list<string> extra split for price only: a tool with batteries is not cheaper than its body alone */
    public function priceSetBy(): array
    {
        return array_values((array) ($this->data['comparison']['price_set_by'] ?? []));
    }

    public function label(string $kind, string $key, string $locale, ?string $valueKey = null): string
    {
        $entries = match ($kind) {
            'type' => $this->productTypes(),
            'tag' => $this->tags(),
            default => $this->attributes(),
        };

        foreach ($entries as $entry) {
            if ($entry['key'] !== $key) {
                continue;
            }

            if ($valueKey !== null) {
                foreach ((array) ($entry['values'] ?? []) as $value) {
                    if ($value['key'] === $valueKey) {
                        return (string) ($value['label'][$locale] ?? $valueKey);
                    }
                }

                return $valueKey;
            }

            return (string) ($entry['label'][$locale] ?? $key);
        }

        return $valueKey ?? $key;
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, true>  $keys  keys already used in the same list
     * @return list<string>
     */
    private static function checkEntry(string $at, mixed $entry, array &$keys, bool $patternsRequired = false): array
    {
        if (! is_array($entry)) {
            return ["{$at} must be an object"];
        }

        $problems = [];
        $key = $entry['key'] ?? null;

        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key)) {
            $problems[] = "{$at}.key must be snake_case";
        } elseif (isset($keys[$key])) {
            $problems[] = "{$at}.key {$key} is used twice";
        } else {
            $keys[$key] = true;
        }

        if (! is_string($entry['label']['he'] ?? null) || ! is_string($entry['label']['en'] ?? null)) {
            $problems[] = "{$at}.label needs he and en";
        }

        return [...$problems, ...self::checkPatterns("{$at}.patterns", $entry['patterns'] ?? null, $patternsRequired)];
    }

    /** @return list<string> */
    private static function checkPatterns(string $at, mixed $patterns, bool $required): array
    {
        if ($patterns === null) {
            return $required ? ["{$at} is required"] : [];
        }

        if (! is_array($patterns)) {
            return ["{$at} must be a list"];
        }

        $problems = [];
        foreach ($patterns as $i => $pattern) {
            if (! is_string($pattern) || $pattern === '' || @preg_match('~'.$pattern.'~iu', '') === false) {
                $problems[] = "{$at}[{$i}] is not a valid pattern";
            }
        }

        return $problems;
    }
}
