<?php

namespace App\Modules\Enrichment\Prompts;

use App\Modules\Enrichment\Scanning\Units;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * The vocabulary as a model reads it: one short line per entry, Hebrew label and hint, no
 * patterns (those are for code). Stable for a given vocabulary, so it caches as a prompt prefix.
 */
final class VocabularyText
{
    public static function render(VocabularyDefinition $vocabulary): string
    {
        $lines = ['## Product types (`type`)'];

        foreach ($vocabulary->productTypes() as $type) {
            $lines[] = '- `'.$type['key'].'`: '.$type['label']['he'].self::hint($type);
        }

        $lines[] = '';
        $lines[] = '## Specs (`specs`, map to a measurement)';

        foreach ($vocabulary->attributes() as $attribute) {
            if (($attribute['type'] ?? 'number') !== 'number') {
                continue;
            }

            $unit = Units::CANONICAL[$attribute['dimension']] ?? '';
            $applies = (array) ($attribute['applies_to'] ?? []);

            $lines[] = '- `'.$attribute['key'].'` ('.$unit.'): '.$attribute['label']['he'].self::hint($attribute)
                .($applies === [] ? '' : '. Only for: '.implode(', ', $applies));
        }

        $lines[] = '';
        $lines[] = '## Choices and yes/no specs (`yes`, from candidates)';

        foreach ($vocabulary->attributes() as $attribute) {
            $type = $attribute['type'] ?? 'number';

            if ($type === 'enum') {
                $values = array_map(fn (array $v): string => '`'.$v['key'].'` '.$v['label']['he'], (array) $attribute['values']);
                $lines[] = '- `'.$attribute['key'].'` ('.$attribute['label']['he'].'), one of: '.implode(' | ', $values).self::hint($attribute);
            }

            if ($type === 'boolean') {
                $lines[] = '- `'.$attribute['key'].'`: '.$attribute['label']['he'].' (value true)'.self::hint($attribute);
            }
        }

        $lines[] = '';
        $lines[] = '## Tags (`yes`, from candidates with key `tag`)';
        $lines[] = implode(', ', array_map(fn (array $t): string => '`'.$t['key'].'` '.$t['label']['he'], $vocabulary->tags()));

        if ($vocabulary->uses() !== []) {
            $lines[] = '';
            $lines[] = '## Jobs and projects (`uses`)';

            foreach ($vocabulary->uses() as $use) {
                $types = (array) ($use['types'] ?? []);
                $lines[] = '- `'.$use['key'].'`: '.$use['label']['he'].self::hint($use)
                    .($types === [] ? '' : '. Usually: '.implode(', ', $types));
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $entry */
    private static function hint(array $entry): string
    {
        return isset($entry['hint']) && $entry['hint'] !== '' ? ' — '.$entry['hint'] : '';
    }
}
