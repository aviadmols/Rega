<?php

namespace App\Core\Console;

use App\Core\Modules\ModuleRepository;
use Illuminate\Console\Command;

final class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List modules in load order with what each one declares';

    public function handle(ModuleRepository $modules): int
    {
        $rows = [];

        foreach ($modules->all() as $module) {
            $rows[] = [
                $module->name,
                $module->enabled ? 'yes' : 'no',
                implode(', ', $module->requires) ?: '-',
                implode(', ', array_map(fn ($f) => $f->name, $module->features)) ?: '-',
                implode(', ', array_map(fn ($s) => $s->name, $module->settings)) ?: '-',
            ];
        }

        $this->table(['Module', 'Enabled', 'Requires', 'Features', 'Settings'], $rows);

        return self::SUCCESS;
    }
}
