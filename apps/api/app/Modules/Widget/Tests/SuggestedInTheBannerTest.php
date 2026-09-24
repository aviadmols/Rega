<?php

namespace App\Modules\Widget\Tests;

use App\Modules\Enrichment\Actions\ReadContentInCode;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The closed widget has a question to offer before anybody has asked one.
 *
 * An invitation to ask something is a worse offer than a question, and on almost every page
 * nobody has asked anything yet — so the questions the scan found the page can answer stand in
 * for the ones that have not been asked.
 */
final class SuggestedInTheBannerTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
    }

    public function test_an_article_offers_its_own_questions_when_none_have_been_asked(): void
    {
        $this->article('910', 'בחירת דלת פנים', implode("\n", [
            'משקוף',
            'משקוף הדלת הוא המסגרת שמחזיקה אותה, והוא נקבע לפי עובי הקיר בבית.',
            '- כדאי למדוד את המשקוף לפני שמזמינים דלת',
            'המדריך הזה למי שמשפץ.',
        ]));

        app(ReadContentInCode::class)->handle($this->shop->id);

        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'content', '910', 'he');

        $this->assertSame([], $bank['questions'], 'nobody has asked anything here');
        $this->assertNotEmpty($bank['suggested'], 'so the closed widget still has something to offer');
        $this->assertSame('סכם לי את המאמר', $bank['suggested'][0]);
        $this->assertContains('מה זה משקוף?', $bank['suggested'], 'and it is about this page, not any page');
    }

    public function test_a_product_falls_back_to_what_any_product_can_be_asked(): void
    {
        $this->product('10', 'מסור אנכי', 'x', []);

        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he');

        $this->assertNotEmpty($bank['suggested']);
        $this->assertLessThanOrEqual(4, count($bank['suggested']), 'an offer, not a menu');
    }
}
