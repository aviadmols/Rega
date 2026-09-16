<?php

namespace App\Core\Console;

use App\Core\Localization\Locales;
use App\Core\Localization\TranslationChecker;
use App\Core\Modules\ModuleRepository;
use Illuminate\Console\Command;

final class CheckTranslationsCommand extends Command
{
    protected $signature = 'i18n:check';

    protected $description = 'Fail when a translation exists in one admin language and not in another';

    public function handle(ModuleRepository $modules): int
    {
        $checker = new TranslationChecker(
            modules: $modules,
            locales: Locales::supported(),
            appLangPath: lang_path(),
            coreLangPath: app_path('Core/lang'),
        );

        $problems = $checker->problems();

        if ($problems === []) {
            $this->components->info('All translations match in: '.implode(', ', Locales::supported()));

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        $this->newLine();
        $this->components->error(count($problems).' translation problem(s).');

        return self::FAILURE;
    }
}
