<?php

namespace Tests\Architecture;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A translation key may not contain a dot.
 *
 * `__()` reads a dot as a step down into the array, so a key written as 'catalog.sync' is looked
 * for as catalog → sync, never found, and the raw key is printed on the screen instead of the
 * sentence. It passes every other check: the file parses, both languages have it, and the tests
 * that read it by the same wrong path get the same wrong answer.
 *
 * It reached production once, as nine identifiers where nine step names should have been.
 */
final class TranslationKeysTest extends TestCase
{
    public function test_no_translation_key_contains_a_dot(): void
    {
        $offenders = [];

        foreach (File::glob(base_path('app/Modules/*/lang/*/*.php')) as $file) {
            $lines = File::lines($file);

            foreach ($lines as $number => $line) {
                if (preg_match("/^\s+'([a-z0-9_]+\.[a-z0-9_.]+)'\s*=>/i", $line, $found) === 1) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.($number + 1).' — '.$found[1];
                }
            }
        }

        $this->assertSame([], $offenders, "a key with a dot in it can never be read back:\n".implode("\n", $offenders));
    }
}
