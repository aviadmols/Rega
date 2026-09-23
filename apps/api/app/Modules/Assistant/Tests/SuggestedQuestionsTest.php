<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Actions\ReadContentInCode;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reader is not handed an empty box. They are shown the questions this particular article can
 * answer, found when it was read, and one click asks one.
 */
final class SuggestedQuestionsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'tok_suggested_questions_abcdefghijklmnop';

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
    }

    public function test_the_chips_come_from_the_article_and_differ_between_two_of_them(): void
    {
        // A guide about cladding: the word is a heading and the piece keeps returning to it.
        $this->article('900', 'רעיונות עיצוב לחללי הבית', implode("\n", [
            'חיפוי',
            'חיפוי עץ על קיר משנה את האופי של החדר כולו ומביא חום לחלל שלם.',
            '- כדאי לבדוק את עובי הלוח לפני שמזמינים',
            '- חיפוי בחדר רטוב דורש טיפול אחר לגמרי',
            'המדריך הזה למי שמשפץ בית ורוצה להבין מה אפשרי.',
        ]));
        $this->article('901', 'המדריך המלא לעץ במבוק', "במבוק הוא עשב ולא עץ, והוא גדל מהר מאוד בגינה.\n- במבוק צריך השקיה קבועה בקיץ");

        app(ReadContentInCode::class)->handle($this->shop->id);

        $cladding = $this->suggestions('900');

        $this->assertSame('סכם לי את המאמר', $cladding[0], 'summing up is what a reader wants first');
        $this->assertContains('2 נקודות שחשוב לדעת', $cladding);
        $this->assertContains('מה זה חיפוי?', $cladding, 'the subject the article keeps returning to');
        $this->assertContains('למי זה מתאים?', $cladding);

        // A different article is asked different things.
        $this->assertNotContains('מה זה חיפוי?', $this->suggestions('901'));
    }

    public function test_a_heading_the_article_never_returns_to_is_not_a_subject(): void
    {
        $this->article('902', 'פרקט', implode("\n", [
            'לסיכום',
            'פרקט עץ הוא רצפה חמה, והבחירה בו משנה את אופי החדר לאורך שנים רבות מאוד.',
            'ברזל',
            'הרצפה מגיעה במגוון גוונים והיא מתאימה כמעט לכל סגנון עיצובי בבית מודרני.',
        ]));

        app(ReadContentInCode::class)->handle($this->shop->id);
        $asks = $this->suggestions('902');

        $this->assertNotContains('מה זה ברזל?', $asks, 'said once, in a heading: not what the piece is about');
        $this->assertNotContains('מה זה לסיכום?', $asks, 'and nobody asks what a summary is');
    }

    /** @return list<string> */
    private function suggestions(string $externalId): array
    {
        return $this->getJson("/api/v1/widget/{$this->site}/questions?id={$externalId}&type=content&locale=he")
            ->assertOk()
            ->json('data.suggested');
    }
}
