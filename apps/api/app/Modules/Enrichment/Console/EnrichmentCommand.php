<?php

namespace App\Modules\Enrichment\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\ComputeProductRelations;
use App\Modules\Enrichment\Actions\ComputeRankings;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportRelationRules;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Actions\ReadPromisesInCode;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Support\TaskFile;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Console\Command;

/**
 * The same actions the operator panel runs, for scripts and local work:
 *
 *   enrichment vocabulary gueta-avigdor --template=power-tools
 *   enrichment code gueta-avigdor
 *   enrichment tasks gueta-avigdor product_extraction --vocabulary=wood --limit=20 --out=tasks.jsonl
 *   enrichment results <batch id> answers.jsonl --model=claude-haiku-4-5
 *   enrichment rankings gueta-avigdor
 *   enrichment rules gueta-avigdor --template=hardware-store
 *   enrichment relations gueta-avigdor
 */
final class EnrichmentCommand extends Command
{
    protected $signature = 'enrichment
        {step : vocabulary, code, promises, tasks, results, rankings, rules or relations}
        {target : shop slug, or batch ID for results}
        {argument? : task type for tasks, results file for results}
        {--template= : vocabulary template name}
        {--file= : vocabulary JSON file}
        {--author= : who wrote the vocabulary}
        {--vocabulary= : vocabulary key, for tasks}
        {--tier=1 : review tier}
        {--subject=product : product or content, for reviews}
        {--limit= : maximum requests}
        {--out= : where to write the task file}
        {--model= : the model that produced the results}
        {--include-done : ask again about products already read with the same input}';

    protected $description = 'Run enrichment steps from the command line.';

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runUnscoped(fn (): int => match ($this->argument('step')) {
            'vocabulary' => $this->vocabulary(),
            'tasks' => $this->tasks(),
            'results' => $this->results(),
            'rankings' => $this->rankings(),
            'code' => $this->shopStep(fn (Shop $shop): Run => app(ReadProductsInCode::class)->handle($shop->id)),
            'promises' => $this->shopStep(fn (Shop $shop): Run => app(ReadPromisesInCode::class)->handle($shop->id)),
            'relations' => $this->shopStep(fn (Shop $shop): Run => app(ComputeProductRelations::class)->handle($shop->id)),
            'rules' => $this->rules(),
            default => $this->failWith('Unknown step.'),
        });
    }

    private function vocabulary(): int
    {
        $shop = $this->shop();
        $data = $this->option('template')
            ? ImportVocabulary::template((string) $this->option('template'))
            : json_decode((string) @file_get_contents((string) $this->option('file')), true);

        if (! $shop || ! is_array($data)) {
            return $this->failWith('Shop or vocabulary not found.');
        }

        $result = app(ImportVocabulary::class)->handle($shop->id, $data, $this->option('author'));
        $this->line((string) $result['run']->summary());

        return $result['vocabulary'] ? self::SUCCESS : self::FAILURE;
    }

    private function tasks(): int
    {
        $shop = $this->shop();
        $type = TaskType::tryFrom((string) $this->argument('argument'));

        if (! $shop || ! $type) {
            return $this->failWith('Shop or task type not found.');
        }

        $vocabulary = EnrichmentVocabulary::query()->where('shop_id', $shop->id)->where('active', true)
            ->when($this->option('vocabulary'), fn ($q, $key) => $q->where('key', $key))
            ->latest('version')->first();

        $result = app(CreateTaskFile::class)->handle(
            $shop->id,
            $type,
            $vocabulary?->id,
            (int) $this->option('tier'),
            ($type === TaskType::FactReview ? ['subject' => (string) $this->option('subject')] : []) + ($this->option('include-done') ? ['include_done' => true] : []),
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
        );

        $this->line((string) $result['run']->summary());

        if ($result['batch'] && $this->option('out')) {
            $handle = fopen((string) $this->option('out'), 'wb');
            foreach (TaskFile::lines($result['batch']) as $line) {
                fwrite($handle, $line);
            }
            fclose($handle);
            $this->info("Batch {$result['batch']->id} written to {$this->option('out')}");
        }

        return self::SUCCESS;
    }

    private function results(): int
    {
        $batch = EnrichmentBatch::query()->find((string) $this->argument('target'));
        $contents = @file_get_contents((string) $this->argument('argument'));

        if (! $batch || $contents === false) {
            return $this->failWith('Batch or results file not found.');
        }

        $run = app(ImportTaskResults::class)->handle($batch, $contents, $this->option('model'));
        $this->line((string) $run->summary());

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function rankings(): int
    {
        $shop = $this->shop();

        if (! $shop) {
            return $this->failWith('Shop not found.');
        }

        $this->line((string) app(ComputeRankings::class)->handle($shop->id)->summary());

        return self::SUCCESS;
    }

    /** @param callable(Shop): Run $step */
    private function shopStep(callable $step): int
    {
        $shop = $this->shop();

        if (! $shop) {
            return $this->failWith('Shop not found.');
        }

        $run = $step($shop);
        $this->line((string) $run->summary());

        return $run->status->value === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    private function rules(): int
    {
        $shop = $this->shop();
        $data = $this->option('template')
            ? ImportRelationRules::template((string) $this->option('template'))
            : json_decode((string) @file_get_contents((string) $this->option('file')), true);

        if (! $shop || ! is_array($data)) {
            return $this->failWith('Shop or rules not found.');
        }

        $result = app(ImportRelationRules::class)->handle($shop->id, $data, $this->option('author'));
        $this->line((string) $result['run']->summary());

        return $result['rules'] ? self::SUCCESS : self::FAILURE;
    }

    private function shop(): ?Shop
    {
        $key = (string) $this->argument('target');

        return Shop::query()->where('slug', $key)->orWhere('id', $key)->first();
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
