<?php

namespace App\Modules\Enrichment\Relations;

/**
 * A shop's rules for what goes with what, across its vocabularies. Data, not code: edited per
 * store and saved in versions, like vocabularies.
 *
 *   {"schema_version": 1, "rules": [{
 *     "key": "battery_for_cordless_tool",
 *     "label": {"he": "סוללה ומטען מתאימים", "en": "Matching battery and charger"},
 *     "kind": "complement",
 *     "from": {"vocabulary": "power_tools", "choices": {"power_source": "cordless"}},
 *     "to": {"vocabulary": "power_tools", "types": ["battery", "charger"]},
 *     "match": {"brand": true, "specs": ["voltage_v"]},
 *     "boost": {"from_choices": {"kit": "body_only"}},
 *     "limit": 3
 *   }]}
 *
 * A side matches a product when every condition it names holds: vocabulary, types (any of),
 * types_not (none of), choices (each key equals one of the values), uses_any (shares a job),
 * categories (in any of these store categories, for branches with no vocabulary yet). `match` adds conditions
 * between the two products: the same brand, equal specs (within 2%), specs_if_known (equal when
 * both products state them), a shared job.
 */
final class RelationRuleSet
{
    public const SCHEMA_VERSION = 1;

    public const KINDS = ['complement', 'alternative'];

    private const SIDE_KEYS = ['vocabulary', 'types', 'types_not', 'choices', 'uses_any', 'categories'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?self, 1: list<string>}
     */
    public static function parse(array $data): array
    {
        $problems = [];

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $problems[] = 'schema_version must be '.self::SCHEMA_VERSION;
        }

        $rules = $data['rules'] ?? null;

        if (! is_array($rules) || $rules === []) {
            $problems[] = 'rules must be a non-empty list';
            $rules = [];
        }

        $keys = [];

        foreach ($rules as $i => $rule) {
            $at = "rules[{$i}]";

            if (! is_array($rule)) {
                $problems[] = "{$at} must be an object";

                continue;
            }

            $key = $rule['key'] ?? null;

            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,60}$/', $key)) {
                $problems[] = "{$at}.key must be snake_case";
            } elseif (isset($keys[$key])) {
                $problems[] = "{$at}.key {$key} is used twice";
            } else {
                $keys[$key] = true;
            }

            if (! is_string($rule['label']['he'] ?? null) || ! is_string($rule['label']['en'] ?? null)) {
                $problems[] = "{$at}.label needs he and en";
            }

            if (! in_array($rule['kind'] ?? null, self::KINDS, true)) {
                $problems[] = "{$at}.kind must be one of: ".implode(', ', self::KINDS);
            }

            foreach (['from', 'to'] as $side) {
                $conditions = $rule[$side] ?? null;

                if (! is_array($conditions) || $conditions === []) {
                    $problems[] = "{$at}.{$side} needs at least one condition";

                    continue;
                }

                foreach (array_keys($conditions) as $condition) {
                    if (! in_array($condition, self::SIDE_KEYS, true)) {
                        $problems[] = "{$at}.{$side}.{$condition} is not a known condition";
                    }
                }
            }

            foreach (array_keys((array) ($rule['match'] ?? [])) as $condition) {
                if (! in_array($condition, ['brand', 'specs', 'specs_if_known', 'uses'], true)) {
                    $problems[] = "{$at}.match.{$condition} is not a known condition";
                }
            }

            if (isset($rule['limit']) && (! is_int($rule['limit']) || $rule['limit'] < 1 || $rule['limit'] > 12)) {
                $problems[] = "{$at}.limit must be 1 to 12";
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

    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return array_values((array) ($this->data['rules'] ?? []));
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Whether a product profile meets one side of a rule.
     *
     * @param  array<string, mixed>  $side
     * @param  array<string, mixed>  $profile  see ComputeProductRelations::profiles()
     */
    public static function meets(array $side, array $profile): bool
    {
        if (isset($side['vocabulary']) && $side['vocabulary'] !== ($profile['vocabulary'] ?? null)) {
            return false;
        }

        if (isset($side['types']) && ! in_array($profile['type'] ?? null, (array) $side['types'], true)) {
            return false;
        }

        if (isset($side['types_not']) && in_array($profile['type'] ?? null, (array) $side['types_not'], true)) {
            return false;
        }

        foreach ((array) ($side['choices'] ?? []) as $key => $values) {
            if (! in_array($profile['choices'][$key] ?? null, (array) $values, true)) {
                return false;
            }
        }

        if (isset($side['uses_any']) && array_intersect((array) $side['uses_any'], (array) ($profile['uses'] ?? [])) === []) {
            return false;
        }

        if (isset($side['categories']) && array_intersect(array_map('strval', (array) $side['categories']), (array) ($profile['categories'] ?? [])) === []) {
            return false;
        }

        return true;
    }
}
