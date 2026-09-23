<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Enrichment\Actions\AuditContentReading;
use App\Modules\Enrichment\Actions\PublishContentRules;
use App\Modules\Enrichment\Actions\ReadContentInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentRules;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRuleProposal;
use App\Modules\Enrichment\Support\ContentRules;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loop that keeps the reading honest: sample a few articles, ask what code missed, propose
 * markers, check the proposal in code, have a second model review it, and wait for a person.
 *
 * The rules are data. Nothing in this loop can change the program, and nothing changes the
 * reading until someone publishes it.
 */
final class ContentAuditTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** A line the reader misses today, because "המסקנה" is not a marker it knows. */
    private const MISSED = 'המסקנה: אלון עדיף על אורן לרהיטים שנמצאים בשימוש כבד.';

    /** @var object{replies: list<array<string, mixed>>, prompts: list<string>} */
    private object $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->article('900', 'עץ אלון', "עץ אלון הוא עץ קשה ועמיד שמתאים לרהיטים.\n".self::MISSED);

        $this->model = new class implements ChatModel
        {
            /** @var list<array<string, mixed>> */
            public array $replies = [];

            /** @var list<string> */
            public array $prompts = [];

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->prompts[] = $system;

                return new ModelReply(array_shift($this->replies) ?? [], 400, 80);
            }
        };
        $this->app->instance(ChatModel::class, $this->model);
    }

    public function test_a_miss_both_models_agree_on_publishes_itself_and_the_reading_improves(): void
    {
        $this->model->replies = [
            ['missed' => [['quote' => self::MISSED, 'why' => 'מסקנה של הכתבה']], 'wrong' => []],
            ['takeaway_markers' => ['המסקנה'], 'audience_markers' => [], 'summary' => 'הכתבות כאן פותחות מסקנה במילה הזו'],
            ['good' => true, 'reasons' => ['נתמך בראיות', 'מספיק ספציפי']],
        ];

        app(AuditContentReading::class)->handle($this->shop->id);

        $proposal = $this->inShop(fn () => EnrichmentRuleProposal::query()->sole());

        $this->assertSame(EnrichmentRuleProposal::PUBLISHED, $proposal->status, 'both models agreed, so nobody had to be asked');
        $this->assertSame(['המסקנה'], $proposal->proposed['takeaway_markers']);
        $this->assertSame(self::MISSED, $proposal->findings[0]['missed'][0]['quote'], 'the line that prompted it is kept');
        $this->assertNull($proposal->decided_by, 'and no person is credited with a decision they did not make');

        $inForce = $this->inShop(fn (): array => EnrichmentContentRules::inForce($this->shop->id));

        $this->assertSame(ContentRules::VERSION + 1, $inForce['version'], 'a version is added, never edited');
        $this->assertContains('המסקנה', $inForce['takeaway_markers']);

        // And now the reading really is better.
        app(ReadContentInCode::class)->handle($this->shop->id);

        $takeaways = $this->inShop(fn () => EnrichmentFact::query()
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->pluck('value_text')
            ->all());

        $this->assertContains(self::MISSED, $takeaways);
    }

    public function test_a_line_the_article_does_not_contain_is_dropped_before_anyone_reads_it(): void
    {
        $this->model->replies = [
            ['missed' => [['quote' => 'משפט שאף אחד לא כתב', 'why' => 'המצאה']], 'wrong' => []],
        ];

        app(AuditContentReading::class)->handle($this->shop->id);

        $proposal = $this->inShop(fn () => EnrichmentRuleProposal::query()->sole());

        $this->assertSame([], $proposal->findings[0]['missed'], 'a quote that is not in the text is not evidence');
        $this->assertNull($proposal->proposed, 'and nothing is proposed from nothing');
        $this->assertSame(EnrichmentRuleProposal::REJECTED, $proposal->status);
    }

    public function test_a_marker_that_would_swallow_ordinary_prose_never_reaches_the_reviewer(): void
    {
        $this->model->replies = [
            ['missed' => [['quote' => self::MISSED, 'why' => 'מסקנה']], 'wrong' => []],
            // "עץ" opens ordinary sentences here, and is not the opening of the missed line.
            ['takeaway_markers' => ['עץ', 'this'], 'audience_markers' => [], 'summary' => 'נסיון'],
        ];

        app(AuditContentReading::class)->handle($this->shop->id);

        $proposal = $this->inShop(fn () => EnrichmentRuleProposal::query()->sole());

        $this->assertNull($proposal->proposed);
        $this->assertSame(EnrichmentRuleProposal::REJECTED, $proposal->status);
        $this->assertCount(2, $this->model->prompts, 'the reviewer was never troubled with it');
    }

    public function test_a_proposal_the_reviewer_refused_cannot_be_published(): void
    {
        $this->model->replies = [
            ['missed' => [['quote' => self::MISSED, 'why' => 'מסקנה']], 'wrong' => []],
            ['takeaway_markers' => ['המסקנה'], 'audience_markers' => [], 'summary' => 'הצעה'],
            ['good' => false, 'reasons' => ['ראיה אחת בלבד']],
        ];

        app(AuditContentReading::class)->handle($this->shop->id);

        $proposal = $this->inShop(fn () => EnrichmentRuleProposal::query()->sole());
        $this->assertSame(EnrichmentRuleProposal::REJECTED, $proposal->status);

        $this->assertNull(app(PublishContentRules::class)->handle($proposal, null));
        $this->assertSame(
            ContentRules::defaults()['takeaway_markers'],
            $this->inShop(fn (): array => EnrichmentContentRules::inForce($this->shop->id))['takeaway_markers'],
        );
    }

    public function test_a_person_can_say_no_and_it_is_written_down(): void
    {
        $this->model->replies = [
            ['missed' => [['quote' => self::MISSED, 'why' => 'מסקנה']], 'wrong' => []],
            ['takeaway_markers' => ['המסקנה'], 'audience_markers' => [], 'summary' => 'הצעה'],
            ['good' => true, 'reasons' => ['בסדר']],
        ];

        app(AuditContentReading::class)->handle($this->shop->id);
        $proposal = $this->inShop(fn () => EnrichmentRuleProposal::query()->sole());

        $operator = User::factory()->operator()->create();
        app(PublishContentRules::class)->discard($proposal, $operator->id);

        $this->assertSame(EnrichmentRuleProposal::DISCARDED, $proposal->fresh()->status);
        $this->assertSame($operator->id, $proposal->fresh()->decided_by);
        $this->assertNotNull($proposal->fresh()->decided_at);
    }

    public function test_it_looks_at_different_articles_each_time(): void
    {
        foreach (range(1, 3) as $i) {
            $this->article('90'.$i, 'כותרת '.$i, 'טקסט על עץ ועל רהיטים, מספיק ארוך כדי להיחשב.');
        }

        $this->model->replies = array_fill(0, 20, ['missed' => [], 'wrong' => []]);

        app(AuditContentReading::class)->handle($this->shop->id, sample: 2);
        $first = $this->inShop(fn () => EnrichmentRuleProposal::query()->latest('id')->first())->sampled;

        app(AuditContentReading::class)->handle($this->shop->id, sample: 2);
        $second = $this->inShop(fn () => EnrichmentRuleProposal::query()->latest('id')->first())->sampled;

        $this->assertSame([], array_intersect(array_column($first, 'article'), array_column($second, 'article')), 'the next audit reads what the last one did not');
    }

    public function test_the_weekly_walk_audits_every_shop_with_articles_and_skips_one_that_opted_out(): void
    {
        // A second shop with an article that said no, and a third with no articles at all.
        $optedOut = Shop::factory()->create();
        app(TenantContext::class)->run($optedOut->id, fn () => CatalogContent::query()->create([
            'shop_id' => $optedOut->id, 'type' => 'post', 'external_id' => '1', 'title' => 'x', 'excerpt' => 'y', 'body' => 'y', 'hash' => 'h',
        ]));
        Features::override('enrichment.weekly_audit', false, $optedOut->id);
        $empty = Shop::factory()->create();

        $this->model->replies = [['missed' => [], 'wrong' => []]];

        $this->artisan('enrichment', ['step' => 'audit', '--scheduled' => true])
            ->expectsOutputToContain($this->shop->slug.': ')
            ->expectsOutputToContain($optedOut->slug.': off')
            ->assertSuccessful();

        $audited = app(TenantContext::class)->runUnscoped(fn () => Run::query()
            ->where('action', AuditContentReading::ACTION)
            ->pluck('shop_id')
            ->all());

        $this->assertSame([$this->shop->id], $audited, 'only the shop with articles that did not opt out');
        $this->assertNotContains($empty->id, $audited);
    }

    public function test_a_shop_that_turns_publishing_off_keeps_the_decision_for_a_person(): void
    {
        Features::override('enrichment.auto_publish_rules', false, $this->shop->id);

        $this->model->replies = [
            ['missed' => [['quote' => self::MISSED, 'why' => 'מסקנה של הכתבה']], 'wrong' => []],
            ['takeaway_markers' => ['המסקנה'], 'audience_markers' => [], 'summary' => 'x'],
            ['good' => true, 'reasons' => ['נתמך בראיות']],
        ];

        app(AuditContentReading::class)->handle($this->shop->id);
        $proposal = $this->inShop(fn () => EnrichmentRuleProposal::query()->sole());

        $this->assertSame(EnrichmentRuleProposal::APPROVED, $proposal->status, 'agreed, but waiting');
        $this->assertSame(
            ContentRules::defaults()['takeaway_markers'],
            $this->inShop(fn (): array => EnrichmentContentRules::inForce($this->shop->id))['takeaway_markers'],
            'and the reader is untouched until someone says so',
        );

        $operator = User::factory()->operator()->create();
        app(PublishContentRules::class)->handle($proposal->fresh(), $operator->id);

        $this->assertSame($operator->id, $proposal->fresh()->decided_by);
    }
}
