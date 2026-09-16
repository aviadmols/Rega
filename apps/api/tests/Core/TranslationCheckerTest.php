<?php

namespace Tests\Core;

use App\Core\Features\FeatureDefinition;
use App\Core\Localization\TranslationChecker;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRepository;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class TranslationCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'upsell-i18n-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_real_application_has_every_translation_in_every_admin_language(): void
    {
        $this->artisan('i18n:check')->assertSuccessful();
    }

    public function test_it_reports_keys_missing_or_empty_in_one_language(): void
    {
        $this->lang('app/he/shops.php', ['title' => 'חנויות', 'fields' => ['name' => 'שם', 'domain' => '']]);
        $this->lang('app/en/shops.php', ['title' => 'Shops', 'fields' => ['name' => 'Name', 'domain' => 'Domain', 'status' => 'Status']]);

        $problems = $this->checker(new ModuleRepository([]))->problems();

        $this->assertContains('[app] he: missing "shops.fields.status"', $problems);
        $this->assertContains('[app] he: empty "shops.fields.domain"', $problems);
        $this->assertCount(2, $problems);
    }

    public function test_every_declared_feature_and_module_needs_a_label_in_every_language(): void
    {
        $module = new ModuleManifest(
            name: 'Probe',
            slug: 'probe',
            path: $this->root.DIRECTORY_SEPARATOR.'Probe',
            namespace: 'App\\Modules\\Probe',
            enabled: true,
            requires: [],
            provider: null,
            features: [new FeatureDefinition('probe', 'shiny', true)],
            settings: [],
        );

        $this->lang('Probe/lang/he/module.php', ['name' => 'בדיקה']);
        $this->lang('Probe/lang/en/module.php', ['name' => 'Probe']);
        $this->lang('Probe/lang/en/features.php', ['shiny' => ['label' => 'Shiny']]);

        $problems = $this->checker(new ModuleRepository([$module]))->problems();

        $this->assertContains('[probe] he: missing "features.shiny.label"', $problems);
        $this->assertContains('[probe] he: missing required label "features.shiny.label"', $problems);
    }

    private function checker(ModuleRepository $modules): TranslationChecker
    {
        return new TranslationChecker(
            modules: $modules,
            locales: ['he', 'en'],
            appLangPath: $this->root.DIRECTORY_SEPARATOR.'app',
            coreLangPath: $this->root.DIRECTORY_SEPARATOR.'core',
        );
    }

    /** @param array<string, mixed> $lines */
    private function lang(string $relative, array $lines): void
    {
        $file = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        (new Filesystem)->ensureDirectoryExists(dirname($file));
        file_put_contents($file, '<?php return '.var_export($lines, true).';');
    }
}
