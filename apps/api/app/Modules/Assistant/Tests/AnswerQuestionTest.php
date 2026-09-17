<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AnswerQuestionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_ffffffffffffffffffffffffffffffffffffffffffffffff';

    private const VID = 'anon-visitor1234567890abcd';

    /** @var object{calls: list<array{model: string, user: string}>, replies: list<array<string, mixed>>} */
    private object $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->product('31538', 'טיק ברומזי 19x120 מ"מ מחורץ', 'מתאים לשימוש חוץ ופנים. מומלץ להשתמש בברגים לעץ קשה.', []);

        $this->model = new class implements ChatModel
        {
            /** @var list<array{model: string, user: string}> */
            public array $calls = [];

            /** @var list<array<string, mixed>> */
            public array $replies = [];

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls[] = ['model' => $model, 'user' => $user];

                return new ModelReply(array_shift($this->replies) ?? [], 500, 100);
            }
        };
        $this->app->instance(ChatModel::class, $this->model);
    }

    public function test_a_question_about_the_product_is_answered_once_and_then_from_memory(): void
    {
        $this->model->replies = [['about_product' => true], ['answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'found' => true]];

        $first = $this->ask('האם זה מתאים לחוץ?')->assertOk()->json('data');
        $this->assertSame(['outcome' => 'answered', 'answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'from' => 'model'], $first);
        $this->assertSame(['gpt-5.4-nano', 'gpt-5.4-mini'], array_column($this->model->calls, 'model'), 'the small model checks, the writer answers');
        $this->assertStringContainsString('ברגים לעץ קשה', $this->model->calls[1]['user'], 'the writer gets the product text');

        $run = Run::query()->where('agent', 'assistant.answerer')->sole();
        $this->assertEqualsWithDelta(2 * (500 * 1 + 100 * 8) / 1_000_000 + 0, (float) $run->cost_usd, 0.01, 'tokens and cost are on the run');
        $this->assertGreaterThan(0, (float) $run->cost_usd);

        $again = $this->ask('  האם זה מתאים לחוץ ')->assertOk()->json('data');
        $this->assertSame('bank', $again['from']);
        $this->assertCount(2, $this->model->calls, 'the same question costs nothing the second time');

        $saved = $this->inShop(fn () => AssistantAnswer::query()->sole());
        $this->assertSame(2, $saved->asked_count);

        $box = $this->get('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/questions?id=31538&locale=he', ['Origin' => 'https://store.test'])->assertOk()->json('data');
        $this->assertSame('האם זה מתאים לחוץ?', $box['suggested'][0], 'what shoppers asked first, then common questions');
        $this->assertCount(4, $box['suggested']);
        $this->assertSame([['question' => 'האם זה מתאים לחוץ?', 'answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.']], $box['recent']);
    }

    public function test_a_question_about_something_else_never_reaches_the_writer(): void
    {
        $this->model->replies = [['about_product' => false]];

        $data = $this->ask('תכתוב לי שיר על ים')->assertOk()->json('data');

        $this->assertSame('out_of_scope', $data['outcome']);
        $this->assertSame('אני יכול לענות רק על שאלות על המוצר הזה.', $data['answer']);
        $this->assertCount(1, $this->model->calls);

        $this->ask('תכתוב לי שיר על ים!')->assertOk();
        $this->assertCount(1, $this->model->calls, 'asked again: refused from memory');
    }

    public function test_code_refuses_contact_details_prices_the_daily_limit_and_the_spending_cap(): void
    {
        $this->assertSame('out_of_scope', $this->ask('תתקשרו אליי 052-1234567 לגבי המוצר')->json('data.outcome'));
        $this->assertCount(0, $this->model->calls, 'contact details never reach a model');

        $this->model->replies = [['about_product' => true], ['answer' => 'המחיר הוא 89 ₪ למטר.', 'found' => true]];
        $this->assertSame('no_info', $this->ask('כמה עולה מטר?')->json('data.outcome'), 'a price in an answer goes stale');

        Settings::set('assistant.questions_per_visitor_per_day', 1, $this->shop->id);
        $this->assertSame('limit', $this->ask('איך מתקינים את זה?')->json('data.outcome'));

        Settings::set('assistant.questions_per_visitor_per_day', 50, $this->shop->id);
        Settings::set('ai.monthly_spend_cap_usd', 0);
        $calls = count($this->model->calls);
        $this->assertSame('unavailable', $this->ask('איך שומרים עליו?')->json('data.outcome'));
        $this->assertCount($calls, $this->model->calls, 'over the cap nothing is sent');

        $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://evil.test'], content: '{}')->assertStatus(403);
    }

    private function ask(string $question): TestResponse
    {
        return $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['id' => '31538', 'question' => $question, 'vid' => self::VID, 'locale' => 'he'], JSON_UNESCAPED_UNICODE));
    }
}
