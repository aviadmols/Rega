<?php

namespace App\Modules\Leads\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Leads\Actions\ComposeCallsToAction;
use App\Modules\Leads\Actions\WriteCallsToAction;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\LeadCta;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Models\LeadReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One model writes, a second scores it, and code refuses what neither should have allowed.
 */
final class TwoModelsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var object{replies: list<array<string, mixed>>, prompts: list<string>, models: list<string>} */
    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->product('10', 'מסור אנכי', 'x', []);

        $this->inShop(fn () => LeadFlow::query()->create([
            'shop_id' => $this->shop->id, 'version' => 1, 'goal' => LeadGoal::Advice,
            'offer' => 'שיחת ייעוץ קצרה', 'active' => true, 'consent' => 'אני מאשר/ת.',
            'fields' => [['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true]],
        ]));
        app(ComposeCallsToAction::class)->handle($this->shop->id);

        $this->ai = new class implements ChatModel
        {
            public array $replies = [];

            public array $prompts = [];

            public array $models = [];

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->prompts[] = $system;
                $this->models[] = $model;

                return new ModelReply(array_shift($this->replies) ?? [], 300, 90);
            }
        };
        $this->app->instance(ChatModel::class, $this->ai);
    }

    public function test_a_line_the_reviewer_likes_is_kept_with_its_score_and_its_reasoning(): void
    {
        $this->ai->replies = [
            ['lines' => [['headline' => 'מתלבטים איזה מסור מתאים לעבודה שלכם?', 'why' => 'למי שמשפץ']]],
            ['scores' => [['headline' => 'מתלבטים איזה מסור מתאים לעבודה שלכם?', 'score' => 88, 'reasons' => ['על העמוד הזה', 'לא מבטיח כלום'], 'better' => '']]],
        ];

        $run = app(WriteCallsToAction::class)->handle($this->shop->id, 1);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(1, $run->output['kept']);

        $cta = $this->inShop(fn () => LeadCta::query()->where('source', 'model')->sole());
        $this->assertSame('מתלבטים איזה מסור מתאים לעבודה שלכם?', $cta->headline);
        $this->assertSame(88, $cta->score);
        $this->assertSame('שיחת ייעוץ קצרה', $cta->body, 'the shop\'s own offer, unchanged');

        // Kept apart, so the reviewer can be judged against what readers did later.
        $review = $this->inShop(fn () => LeadReview::query()->sole());
        $this->assertSame(88, $review->score);
        $this->assertNotSame($review->writer, $review->reviewer, 'two different models');
        $this->assertContains('על העמוד הזה', $review->reasons);
    }

    public function test_code_refuses_a_promise_before_the_reviewer_is_even_asked(): void
    {
        $this->ai->replies = [
            ['lines' => [['headline' => 'תשואה מובטחת על כל רכישה', 'why' => 'מוכר']]],
        ];

        $run = app(WriteCallsToAction::class)->handle($this->shop->id, 1);

        $this->assertSame(1, $run->output['refused_by_code']);
        $this->assertSame(0, $run->output['kept']);
        $this->assertCount(1, $this->ai->models, 'the reviewer was never asked: there was nothing to review');
        $this->assertSame(0, $this->inShop(fn (): int => LeadCta::query()->where('source', 'model')->count()));
    }

    public function test_a_line_the_reviewer_scores_low_is_refused_and_a_near_miss_gets_one_more_try(): void
    {
        $this->ai->replies = [
            ['lines' => [
                ['headline' => 'צרו קשר', 'why' => 'כללי'],
                ['headline' => 'מסור אנכי — נעזור לבחור', 'why' => 'ספציפי'],
            ]],
            ['scores' => [
                ['headline' => 'צרו קשר', 'score' => 20, 'reasons' => ['מתאים לכל עמוד'], 'better' => ''],
                ['headline' => 'מסור אנכי — נעזור לבחור', 'score' => 62, 'reasons' => ['קרוב, אבל יבש'], 'better' => 'לא בטוחים איזה מסור אנכי מתאים?'],
            ]],
            // The second round, on the reviewer's own rewrite.
            ['scores' => [['headline' => 'לא בטוחים איזה מסור אנכי מתאים?', 'score' => 84, 'reasons' => ['הרבה יותר טוב'], 'better' => '']]],
        ];

        $run = app(WriteCallsToAction::class)->handle($this->shop->id, 1);

        $this->assertSame(2, $run->output['refused_by_reviewer'], 'both were under the bar first time');
        $this->assertSame(1, $run->output['second_tries']);
        $this->assertSame(1, $run->output['kept'], 'only the one the reviewer rescued');

        $kept = $this->inShop(fn () => LeadCta::query()->where('source', 'model')->sole());
        $this->assertSame('לא בטוחים איזה מסור אנכי מתאים?', $kept->headline);
    }

    public function test_the_reviewer_cannot_rescue_a_line_by_rewriting_it_into_a_promise(): void
    {
        $this->ai->replies = [
            ['lines' => [['headline' => 'מסור אנכי — נעזור לבחור', 'why' => 'x']]],
            ['scores' => [['headline' => 'מסור אנכי — נעזור לבחור', 'score' => 55, 'reasons' => ['יבש'], 'better' => 'תוצאה מובטחת בכל עבודה']]],
        ];

        $run = app(WriteCallsToAction::class)->handle($this->shop->id, 1);

        $this->assertSame(0, $run->output['kept'], 'the gate runs on the reviewer\'s words too');
        $this->assertGreaterThanOrEqual(1, $run->output['refused_by_code']);
    }

    public function test_the_two_models_are_not_the_same_model(): void
    {
        $this->ai->replies = [
            ['lines' => [['headline' => 'מתלבטים לגבי מסור?', 'why' => 'x']]],
            ['scores' => [['headline' => 'מתלבטים לגבי מסור?', 'score' => 90, 'reasons' => ['טוב'], 'better' => '']]],
        ];

        app(WriteCallsToAction::class)->handle($this->shop->id, 1);

        $this->assertCount(2, $this->ai->models);
        $this->assertSame((string) Settings::get('leads.writer_model'), $this->ai->models[0]);
        $this->assertSame((string) Settings::get('leads.reviewer_model'), $this->ai->models[1]);
        $this->assertNotSame($this->ai->models[0], $this->ai->models[1], 'a model reviewing itself agrees with itself');
    }

    public function test_a_line_the_reviewer_never_returned_is_never_kept(): void
    {
        $this->ai->replies = [
            ['lines' => [['headline' => 'מתלבטים לגבי מסור?', 'why' => 'x']]],
            // The reviewer answers about something else entirely.
            ['scores' => [['headline' => 'שורה אחרת לגמרי', 'score' => 99, 'reasons' => [], 'better' => '']]],
        ];

        app(WriteCallsToAction::class)->handle($this->shop->id, 1);

        $this->assertSame(0, $this->inShop(fn (): int => LeadCta::query()->where('source', 'model')->count()));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
