<?php

namespace App\Core\Localization;

use App\Core\Modules\ModuleRepository;

/**
 * Finds every translation that exists in one admin language and not in another.
 *
 * Checks three kinds of translation roots: the application's lang/ folder, the kernel's,
 * and each module's. It also checks that every feature and setting a module declares has
 * a label in every language, so a new cap cannot reach the admin panel as a raw key.
 */
final class TranslationChecker
{
    /**
     * @param  list<string>  $locales
     */
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly array $locales,
        private readonly string $appLangPath,
        private readonly string $coreLangPath,
    ) {}

    /** @return list<string> human-readable problems, empty when everything matches */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->roots() as $label => $path) {
            $problems = [...$problems, ...$this->compareRoot($label, $path)];
        }

        foreach ($this->modules->enabled() as $module) {
            $keys = [];
            foreach ($this->locales as $locale) {
                $keys[$locale] = $this->flatten($module->path('lang'), $locale);
            }

            $required = ['module.name'];
            foreach ($module->features as $feature) {
                $required[] = "features.{$feature->name}.label";
            }
            foreach ($module->settings as $setting) {
                $required[] = "settings.{$setting->name}.label";
            }

            foreach ($required as $key) {
                foreach ($this->locales as $locale) {
                    if (! array_key_exists($key, $keys[$locale])) {
                        $problems[] = "[{$module->slug}] {$locale}: missing required label \"{$key}\"";
                    }
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /** @return array<string, string> */
    private function roots(): array
    {
        $roots = [
            'app' => $this->appLangPath,
            'core' => $this->coreLangPath,
        ];

        foreach ($this->modules->enabled() as $module) {
            $roots[$module->slug] = $module->path('lang');
        }

        return array_filter($roots, fn (string $path) => is_dir($path));
    }

    /** @return list<string> */
    private function compareRoot(string $label, string $path): array
    {
        $problems = [];
        $keys = [];

        foreach ($this->locales as $locale) {
            $keys[$locale] = $this->flatten($path, $locale);
        }

        $all = array_unique(array_merge(...array_map('array_keys', array_values($keys))));
        sort($all);

        foreach ($all as $key) {
            foreach ($this->locales as $locale) {
                if (! array_key_exists($key, $keys[$locale])) {
                    $problems[] = "[{$label}] {$locale}: missing \"{$key}\"";
                } elseif ($keys[$locale][$key] === '') {
                    $problems[] = "[{$label}] {$locale}: empty \"{$key}\"";
                }
            }
        }

        return $problems;
    }

    /** @return array<string, string> "group.nested.key" => value */
    private function flatten(string $root, string $locale): array
    {
        $flat = [];

        foreach (glob($root.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $lines = require $file;

            if (is_array($lines)) {
                $this->walk($lines, $group, $flat);
            }
        }

        return $flat;
    }

    /**
     * @param  array<mixed>  $lines
     * @param  array<string, string>  $flat
     */
    private function walk(array $lines, string $prefix, array &$flat): void
    {
        foreach ($lines as $key => $value) {
            $path = "{$prefix}.{$key}";

            if (is_array($value)) {
                $this->walk($value, $path, $flat);
            } else {
                $flat[$path] = (string) $value;
            }
        }
    }
}
