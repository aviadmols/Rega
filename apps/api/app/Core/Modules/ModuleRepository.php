<?php

namespace App\Core\Modules;

use App\Core\Modules\Exceptions\ModuleDependencyException;

/**
 * The modules found on disk, in dependency order.
 */
final class ModuleRepository
{
    /** @var array<string, ModuleManifest> keyed by module name, dependencies first */
    private array $modules;

    /** @param list<ModuleManifest> $manifests */
    public function __construct(array $manifests)
    {
        $this->modules = self::sort($manifests);
    }

    public static function discover(string $path, string $namespace): self
    {
        $files = glob(rtrim($path, '/\\').'/*/module.json') ?: [];
        sort($files);

        return new self(array_map(
            fn (string $file): ModuleManifest => ModuleManifest::fromFile($file, $namespace),
            $files,
        ));
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->modules;
    }

    /** @return array<string, ModuleManifest> */
    public function enabled(): array
    {
        return array_filter($this->modules, fn (ModuleManifest $m): bool => $m->enabled);
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): ?ModuleManifest
    {
        return $this->modules[$name] ?? null;
    }

    public function findBySlug(string $slug): ?ModuleManifest
    {
        foreach ($this->modules as $module) {
            if ($module->slug === $slug) {
                return $module;
            }
        }

        return null;
    }

    /**
     * Topological order: every module comes after the modules it requires. An enabled
     * module that requires a missing or disabled one stops the boot, loudly.
     *
     * @param  list<ModuleManifest>  $manifests
     * @return array<string, ModuleManifest>
     */
    private static function sort(array $manifests): array
    {
        $byName = [];
        foreach ($manifests as $manifest) {
            $byName[$manifest->name] = $manifest;
        }

        $sorted = [];
        $visiting = [];

        $visit = function (ModuleManifest $module, array $path) use (&$visit, &$sorted, &$visiting, $byName): void {
            if (isset($sorted[$module->name])) {
                return;
            }

            if (isset($visiting[$module->name])) {
                throw ModuleDependencyException::cycle([...$path, $module->name]);
            }

            $visiting[$module->name] = true;

            foreach ($module->requires as $dependency) {
                $required = $byName[$dependency] ?? null;

                if ($module->enabled && ($required === null || ! $required->enabled)) {
                    throw ModuleDependencyException::missing($module->name, $dependency);
                }

                if ($required !== null) {
                    $visit($required, [...$path, $module->name]);
                }
            }

            unset($visiting[$module->name]);
            $sorted[$module->name] = $module;
        };

        foreach ($byName as $module) {
            $visit($module, []);
        }

        return $sorted;
    }
}
