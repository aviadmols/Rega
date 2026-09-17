<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ComputeRankings;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatch;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Support\TaskFile;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Products go through code first, then a model, then code again. These tests play the model:
 * they answer requests the way a model would, correctly and incorrectly.
 */
final class ProductPipelineTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const SAW = "מסור אנכי נטען 18V גוף בלבד\nעומק חיתוך בעץ: 135 מ\"מ\nעומק חיתוך במתכת: 10 מ\"מ\nמשקל: 2.1 ק\"ג\nניתן לרכוש סוללת 4.0Ah בנפרד\nמנוע ברשלס קומפקטי";

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $root = $this->category('1751', 'כלי עבודה חשמליים');
        $saws = $this->category('2360', 'מסורים', '1751', ['כלי עבודה חשמליים', 'מסורים']);
        $rental = $this->category('2954', 'להשכרה', '1751', ['כלי עבודה חשמליים', 'להשכרה']);
        $other = $this->category('1749', 'הדבקה ואטימה');

        $this->product('101', 'מסור אנכי נטען 18V גוף בלבד', self::SAW, [$root, $saws]);
        $this->product('102', 'מסור אנכי להשכרה', 'מסור אנכי 710W להשכרה', [$root, $rental]);
        $this->product('103', 'סיליקון שקוף', 'סיליקון 280 מ"ל', [$other]);

        $this->powerToolsVocabulary();
    }

    public function test_the_task_file_holds_only_the_branch_packed_small_with_what_code_found(): void
    {
        $batch = $this->createBatch();

        $this->assertSame(1, $batch->request_count, 'the rental category and other branches are left out');

        $request = $this->inShop(fn () => $batch->items()->sole())->request;
        $this->assertSame('101', $request['id']);
        $this->assertSame(['jigsaw'], $request['type_hints']);
        $this->assertContains(['m2', 135, 'mm', '135 מ"מ'], $request['measurements']);
        $this->assertContains('kit', array_column($request['candidates'], 1));

        $lines = $this->inShop(fn () => iterator_to_array(TaskFile::lines($batch->fresh()), false));
        $header = json_decode($lines[0], true);
        $this->assertSame('product_extraction', $header['task']);
        $this->assertStringContainsString('`jigsaw`', $header['system']);
        $this->assertSame(1 + 1, count($lines));
    }

    public function test_answers_become_facts_approved_only_where_code_and_the_model_agree(): void
    {
        $batch = $this->createBatch();
        $item = $this->inShop(fn () => $batch->items()->sole());
        $ids = $this->candidateIds($item->request);

        $run = $this->import($batch, $item->custom_id, [
            'id' => '101',
            'type' => 'jigsaw',
            'specs' => ['voltage_v' => 'm1', 'cutting_depth_mm' => 'm2', 'weight_kg' => 'm4', 'battery_ah' => 'm5', 'torque_nm' => 'm2'],
            'yes' => [$ids['power_source=cordless'], $ids['kit=body_only'], $ids['brushless=1'], $ids['tag=compact'], 'c99'],
            'add' => [
                ['key' => 'tag', 'value' => 'lightweight', 'quote' => 'לא מופיע בטקסט'],
                ['key' => 'weight_kg', 'value' => 3, 'quote' => 'משקל: 2.1 ק"ג'],
            ],
        ]);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $facts = $this->facts();

        $this->assertSame(FactStatus::Approved, $facts['type:type']->status, 'the only type hint, chosen by the model');
        $this->assertSame(FactStatus::Approved, $facts['spec:voltage_v']->status, 'one voltage in the text, one spec it can be');
        $this->assertEquals(2.1, (float) $facts['spec:weight_kg']->value_number, 'the value comes from code, not from the model');
        $this->assertSame(FactStatus::Approved, $facts['spec:weight_kg']->status);
        $this->assertSame(FactStatus::AwaitingReview, $facts['spec:cutting_depth_mm']->status, 'millimeters could be several specs');
        $this->assertSame(FactStatus::Approved, $facts['choice:kit']->status);
        $this->assertSame(FactStatus::Approved, $facts['flag:brushless']->status);
        $this->assertSame(FactStatus::AwaitingReview, $facts['tag:tag']->status, 'tags are always checked');

        // A battery sold separately passes unit and range checks, but the wording sends it to review.
        $this->assertSame(FactStatus::AwaitingReview, $facts['spec:battery_ah']->status);
        $this->assertSame('optional_mention', $facts['spec:battery_ah']->status_reason);

        $problems = $this->inShop(fn () => $item->fresh()->problems);
        $this->assertContains('wrong_unit:torque_nm', $problems);
        $this->assertContains('unknown_candidate:c99', $problems);
        $this->assertContains('quote_not_found:tag', $problems);
        $this->assertContains('number_in_addition:weight_kg', $problems);

        $this->assertSame(BatchStatus::Completed, $this->inShop(fn () => $batch->fresh()->status));
        $this->assertSame('external', $run->provider);
        $this->assertSame('claude-haiku-4-5', $run->model);
    }

    public function test_a_wrong_answer_is_rejected_whole_and_a_second_upload_changes_nothing(): void
    {
        $batch = $this->createBatch();
        $item = $this->inShop(fn () => $batch->items()->sole());

        $this->import($batch, $item->custom_id, ['id' => '999', 'type' => 'jigsaw']);
        $this->assertSame(ItemStatus::Rejected, $this->inShop(fn () => $item->fresh()->status));
        $this->assertSame([], $this->facts());

        $second = $this->import($batch, $item->custom_id, ['id' => '101', 'type' => 'jigsaw']);
        $this->assertSame(1, $second->output['already_done']);
    }

    public function test_an_answer_for_text_that_changed_since_is_not_applied(): void
    {
        $batch = $this->createBatch();
        $item = $this->inShop(fn () => $batch->items()->sole());

        $this->inShop(function (): void {
            $product = CatalogProduct::query()->where('external_id', '101')->sole();
            $product->forceFill(['payload' => ['description' => 'טקסט חדש לגמרי 20V'] + $product->payload])->save();
        });

        $this->import($batch, $item->custom_id, ['id' => '101', 'type' => 'jigsaw']);

        $this->assertSame(ItemStatus::Stale, $this->inShop(fn () => $item->fresh()->status));
        $this->assertSame([], $this->facts());
    }

    public function test_answers_made_for_another_batch_with_the_same_requests_are_accepted(): void
    {
        $first = $this->createBatch();
        $item = $this->inShop(fn () => $first->items()->sole());
        $this->inShop(fn () => EnrichmentBatch::query()->whereKey($first->id)->delete());

        $second = $this->createBatch();
        $this->assertSame($item->custom_id, $this->inShop(fn () => $second->items()->sole())->custom_id, 'the same request has the same ID');

        $contents = json_encode(['type' => 'header', 'batch_id' => $first->id, 'model' => 'claude-haiku-4-5'])."\n"
            .json_encode(['custom_id' => $item->custom_id, 'output' => ['id' => '101', 'type' => 'jigsaw']]);

        $run = app(ImportTaskResults::class)->handle($this->inShop(fn () => $second->fresh()), $contents);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertTrue($run->output['from_other_batch']);
    }

    public function test_with_auto_approve_off_everything_waits_for_a_person(): void
    {
        Features::override('enrichment.auto_approve', false, $this->shop->id);

        $batch = $this->createBatch();
        $item = $this->inShop(fn () => $batch->items()->sole());
        $this->import($batch, $item->custom_id, ['id' => '101', 'type' => 'jigsaw', 'specs' => ['voltage_v' => 'm1']]);

        $this->assertSame(FactStatus::NeedsPerson, $this->facts()['spec:voltage_v']->status);
    }

    public function test_a_second_model_checks_what_waits_and_a_stronger_one_what_it_is_unsure_about(): void
    {
        $batch = $this->createBatch();
        $item = $this->inShop(fn () => $batch->items()->sole());
        $ids = $this->candidateIds($item->request);
        $this->import($batch, $item->custom_id, [
            'id' => '101', 'type' => 'jigsaw',
            'specs' => ['cutting_depth_mm' => 'm2'],
            'yes' => [$ids['tag=compact']],
        ]);

        $review = $this->createBatch(TaskType::FactReview, tier: 1);
        $reviewItem = $this->inShop(fn () => $review->items()->sole());
        $claims = array_column($reviewItem->request['claims'], 2, 0);
        $claimFor = array_flip($claims);

        $this->import($review, $reviewItem->custom_id, ['id' => '101', 'verdicts' => [
            $claimFor['cutting_depth_mm'] => 'ok',
            $claimFor['tag'] => 'unsure',
        ]]);

        $facts = $this->facts();
        $this->assertSame(FactStatus::Approved, $facts['spec:cutting_depth_mm']->status);
        $this->assertSame(FactStatus::AwaitingEscalation, $facts['tag:tag']->status);
        $this->assertSame('claude-haiku-4-5', $facts['tag:tag']->review_model);

        $escalation = $this->createBatch(TaskType::FactReview, tier: 2);
        $escalationItem = $this->inShop(fn () => $escalation->items()->sole());
        $this->assertCount(1, $escalationItem->request['claims'], 'only what tier 1 was unsure about');

        $this->import($escalation, $escalationItem->custom_id, ['id' => '101', 'verdicts' => ['f1' => 'unsure']], 'claude-sonnet-5');

        $this->assertSame(FactStatus::NeedsPerson, $this->facts()['tag:tag']->status);
    }

    public function test_superlatives_compare_only_like_with_like_in_stock_and_in_large_enough_sets(): void
    {
        $saws = $this->inShop(fn () => CatalogCategory::query()->where('external_id', '2360')->sole());
        $root = $this->inShop(fn () => CatalogCategory::query()->where('external_id', '1751')->sole());
        $vocabulary = $this->inShop(fn () => EnrichmentVocabulary::query()->sole());

        // Five cordless jigsaws (one out of stock), two corded: only the cordless set is big enough.
        $weights = ['201' => 1.9, '202' => 2.1, '203' => 1.9, '204' => 2.6, '205' => 1.2];
        foreach ($weights as $id => $weight) {
            $product = $this->product((string) $id, "מסור אנכי {$id}", 'x', [$root, $saws], ['in_stock' => (string) $id !== '205', 'price' => (string) (300 + (int) $id)]);
            $this->approved($product, $vocabulary->id, ['type' => 'jigsaw', 'power_source' => 'cordless', 'kit' => 'body_only'], ['weight_kg' => $weight]);
        }
        foreach (['301' => 2.0, '302' => 2.2] as $id => $weight) {
            $product = $this->product((string) $id, "מסור אנכי חשמלי {$id}", 'x', [$root, $saws]);
            $this->approved($product, $vocabulary->id, ['type' => 'jigsaw', 'power_source' => 'corded', 'kit' => 'body_only'], ['weight_kg' => $weight]);
        }

        $run = app(ComputeRankings::class)->handle($this->shop->id);
        $this->assertSame(RunStatus::Succeeded, $run->status);

        $rankings = $this->inShop(fn () => EnrichmentRanking::query()->with('product')->get());
        $lightest = $rankings->where('metric', 'weight_kg')->where('rank', 1);

        $this->assertEqualsCanonicalizing(['201', '203'], $lightest->pluck('product.external_id')->all(), 'two share first place; the lighter one is out of stock');
        $this->assertTrue($lightest->every(fn (EnrichmentRanking $r): bool => $r->tied && $r->set_size === 4));
        $this->assertSame(['type' => 'jigsaw', 'power_source' => 'cordless'], $lightest->first()->set_facets);
        $this->assertFalse($rankings->contains(fn (EnrichmentRanking $r): bool => in_array($r->product->external_id, ['301', '302'], true)), 'two corded saws are too few to compare');

        $cheapest = $rankings->where('metric', 'price')->firstWhere('rank', 1);
        $this->assertSame('201', $cheapest->product->external_id);
        $this->assertSame('body_only', $cheapest->set_facets['kit']);
    }

    private function createBatch(TaskType $type = TaskType::ProductExtraction, int $tier = 1): EnrichmentBatch
    {
        $vocabulary = $this->inShop(fn () => EnrichmentVocabulary::query()->where('active', true)->sole());

        $result = app(CreateTaskFile::class)->handle($this->shop->id, $type, $vocabulary->id, $tier, $type === TaskType::FactReview ? ['subject' => 'product'] : []);
        $this->assertNotNull($result['batch'], (string) $result['run']->summary());

        return $result['batch'];
    }

    /** @param array<string, mixed> $output */
    private function import(EnrichmentBatch $batch, string $customId, array $output, string $model = 'claude-haiku-4-5'): Run
    {
        $contents = json_encode(['type' => 'header', 'batch_id' => $batch->id, 'model' => $model])."\n"
            .json_encode(['custom_id' => $customId, 'output' => $output], JSON_UNESCAPED_UNICODE);

        return app(ImportTaskResults::class)->handle($this->inShop(fn () => $batch->fresh()), $contents);
    }

    /** @return array<string, EnrichmentFact> keyed "kind:key", current facts only */
    private function facts(): array
    {
        return $this->inShop(fn () => EnrichmentFact::query()->where('status', '!=', FactStatus::Superseded)->get()
            ->keyBy(fn (EnrichmentFact $f): string => $f->kind->value.':'.$f->key)
            ->all());
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, string> "key=value" => candidate id
     */
    private function candidateIds(array $request): array
    {
        $ids = [];
        foreach ($request['candidates'] as [$id, $key, $value]) {
            $ids[$key.'='.(is_bool($value) ? (int) $value : $value)] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $choices  type and choice values
     * @param  array<string, float>  $specs
     */
    private function approved(CatalogProduct $product, string $vocabularyId, array $choices, array $specs): void
    {
        $this->inShop(function () use ($product, $vocabularyId, $choices, $specs): void {
            $base = ['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabularyId, 'origin' => 'code+model', 'status' => 'approved', 'input_hash' => 'x'];

            foreach ($choices as $key => $value) {
                EnrichmentFact::query()->create($base + ($key === 'type'
                    ? ['kind' => 'type', 'key' => 'type', 'value_text' => $value]
                    : ['kind' => 'choice', 'key' => $key, 'value_text' => $value]));
            }

            foreach ($specs as $key => $value) {
                EnrichmentFact::query()->create($base + ['kind' => 'spec', 'key' => $key, 'value_number' => $value, 'unit' => 'kg']);
            }
        });
    }
}
