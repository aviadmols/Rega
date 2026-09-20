<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ShopQuestionsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_gggggggggggggggggggggggggggggggggggggggggggggggg';

    public function test_the_team_sees_unanswered_questions_first_and_its_answer_reaches_the_next_shopper(): void
    {
        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $washer = $this->product('28452', 'מכונת שטיפה בלחץ', 'x', []);
        $teak = $this->product('31538', 'טיק ברומזי', 'x', []);

        $row = fn (CatalogProduct $p, string $q, string $outcome, ?string $answer, int $asked, ?string $source = null) => $this->inShop(fn () => AssistantAnswer::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $p->id, 'question_key' => Question::key($q), 'question' => $q,
            'outcome' => $outcome, 'answer' => $answer, 'source' => $source, 'prompt_version' => 2, 'asked_count' => $asked, 'last_asked_at' => now(),
        ]));
        $open = $row($washer, 'איזה חומר ניקוי מתאים?', AssistantAnswer::NO_INFO, null, 3);
        $row($washer, 'איך מפעילים?', AssistantAnswer::ANSWERED, 'מחברים מים ואז חשמל.', 5, 'general');
        $row($teak, 'מתאים לחוץ?', AssistantAnswer::ANSWERED, 'כן.', 1, 'store');
        $row($teak, 'מה בירת צרפת?', AssistantAnswer::OUT_OF_SCOPE, null, 1);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $page = Livewire::test(ShopQuestions::class, ['shop' => $this->shop->id])
            ->assertSeeInOrder(['שאלות שעוד אין להן תשובה', 'איזה חומר ניקוי מתאים?', 'שאלות שנענו', 'מכונת שטיפה בלחץ', 'איך מפעילים?', 'טיק ברומזי'])
            ->set('drafts.'.$open->id, 'סבון ייעודי למכונות שטיפה, לא אקונומיקה.')
            ->call('answer', $open->id);

        $saved = $this->inShop(fn () => AssistantAnswer::query()->findOrFail($open->id));
        $this->assertSame([AssistantAnswer::ANSWERED, 'team', 'סבון ייעודי למכונות שטיפה, לא אקונומיקה.'], [$saved->outcome, $saved->source, $saved->answer]);

        // The next shopper gets the team's answer from memory, without a model.
        $data = $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['id' => '28452', 'question' => 'איזה חומר ניקוי מתאים', 'vid' => 'anon-visitor1234567890abcd', 'locale' => 'he'], JSON_UNESCAPED_UNICODE))->json('data');
        $this->assertSame(['answered', 'bank', 'team'], [$data['outcome'], $data['from'], $data['source']]);

        $page->call('hide', $open->id);
        $this->assertSame(AssistantAnswer::HIDDEN, $this->inShop(fn () => AssistantAnswer::query()->findOrFail($open->id))->status);
    }
}
