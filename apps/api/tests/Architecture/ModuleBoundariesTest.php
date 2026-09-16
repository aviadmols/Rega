<?php

namespace Tests\Architecture;

use App\Core\Modules\ModuleRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Keeps modules independent, so any one of them can change without breaking the others.
 *
 * Rules:
 *  1. The kernel (app/Core) never refers to a module.
 *  2. A module refers to another module only if it lists it in "requires".
 *  3. And only to its public surface: Contracts, Models, Enums, Events.
 *
 * Module tests are exempt from rule 3 (they may use factories), not from rule 2.
 */
final class ModuleBoundariesTest extends TestCase
{
    private const PUBLIC_NAMESPACES = ['Contracts', 'Models', 'Enums', 'Events'];

    public function test_the_kernel_never_refers_to_a_module(): void
    {
        $violations = [];

        foreach ($this->phpFiles(app_path('Core')) as $file) {
            foreach ($this->moduleReferences($file) as [$module]) {
                $violations[] = $this->relative($file)." refers to module [{$module}]";
            }
        }

        $this->assertSame([], $violations, "The kernel must stay module-agnostic:\n".implode("\n", $violations));
    }

    public function test_modules_only_use_what_they_require_and_only_its_public_surface(): void
    {
        $modules = app(ModuleRepository::class);
        $violations = [];

        foreach ($modules->all() as $module) {
            foreach ($this->phpFiles($module->path) as $file) {
                $isTest = str_contains($file, DIRECTORY_SEPARATOR.'Tests'.DIRECTORY_SEPARATOR);

                foreach ($this->moduleReferences($file) as [$target, $segment]) {
                    if ($target === $module->name) {
                        continue;
                    }

                    if (! in_array($target, $module->requires, true)) {
                        $violations[] = $this->relative($file)." uses [{$target}] but {$module->name}/module.json does not require it";
                    } elseif (! $isTest && ! in_array($segment, self::PUBLIC_NAMESPACES, true)) {
                        $violations[] = $this->relative($file)." uses {$target}\\{$segment}, which is internal to {$target}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)), "Module boundary violations:\n".implode("\n", $violations));
    }

    public function test_the_rules_detect_a_violation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arch').'.php';
        file_put_contents($file, "<?php\nuse App\\Modules\\Tenancy\\Actions\\IssueApiKey;\n\$x = new \\App\\Modules\\Billing\\Models\\Plan;\n");

        $references = $this->moduleReferences($file);
        unlink($file);

        $this->assertSame([['Tenancy', 'Actions'], ['Billing', 'Models']], $references);
    }

    /** @return list<array{0: string, 1: string}> module name and the namespace segment after it */
    private function moduleReferences(string $file): array
    {
        $code = (string) file_get_contents($file);

        // Drop comments so documentation that mentions a module is not a dependency.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#//[^\n]*#', '', $code);

        preg_match_all('/\\\\?App\\\\Modules\\\\([A-Z][A-Za-z0-9]*)\\\\([A-Z][A-Za-z0-9]*)/', $code, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => [$m[1], $m[2]], $matches);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }
}
