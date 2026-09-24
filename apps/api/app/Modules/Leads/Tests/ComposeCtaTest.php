<?php

namespace App\Modules\Leads\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\ReadContentInCode;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Leads\Actions\ComposeCallsToAction;
use App\Modules\Leads\Contracts\OffersCallsToAction;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\LeadCta;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\CtaComposer;
use App\Modules\Leads\Support\LeadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * A banner that knows which page it is on, written from what the scan already found there.
 */
final class ComposeCtaTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        Features::override('leads.enabled', true, $this->shop->id);

        $this->inShop(fn () => LeadFlow::query()->create([
            'shop_id' => $this->shop->id, 'version' => 1, 'goal' => LeadGoal::Advice,
            'offer' => 'שיחת ייעוץ קצרה, בלי עלות', 'promise' => 'נחזור תוך יום', 'active' => true,
            'consent' => 'אני מאשר/ת.',
            'fields' => [['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true]],
        ]));
    }

    public function test_two_articles_get_two_different_offers_from_what_they_are_about(): void
    {
        $this->article('900', 'בחירת דלת פנים', implode("\n", [
            'משקוף',
            'משקוף הדלת הוא המסגרת שמחזיקה אותה, והוא נקבע לפי עובי הקיר בבית.',
            '- כדאי למדוד את המשקוף לפני שמזמינים דלת',
            '- חשוב לציין שדלת פנים נמדדת אחרת מדלת חוץ',
            'המדריך הזה למי שמשפץ.',
        ]));
        $this->article('901', 'פרקט או למינציה', "למינציה היא חיקוי של עץ, ופרקט הוא עץ אמיתי.\n- כדאי לזכור שלמינציה עמידה יותר במים");

        app(ReadContentInCode::class)->handle($this->shop->id);
        $run = app(ComposeCallsToAction::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertGreaterThan(0, $run->output['pages']);

        $door = $this->ctasFor('900');
        $floor = $this->ctasFor('901');

        $this->assertNotEmpty($door);
        $this->assertNotSame(
            $door->pluck('headline')->all(),
            $floor->pluck('headline')->all(),
            'a banner that knows which page it is on',
        );

        // The shop's own offer is in every one of them, unchanged.
        foreach ($door as $cta) {
            $this->assertSame('שיחת ייעוץ קצרה, בלי עלות', $cta->body);
        }

        // Several versions, because which one works is not knowable by reading them.
        $this->assertGreaterThan(1, $door->count());
        $this->assertLessThanOrEqual(CtaComposer::VERSIONS, $door->count());
    }

    public function test_a_page_the_scan_found_nothing_in_still_gets_an_honest_offer(): void
    {
        $this->article('902', 'עמוד ריק', 'שורה.');

        app(ReadContentInCode::class)->handle($this->shop->id);
        app(ComposeCallsToAction::class)->handle($this->shop->id);

        $this->assertNotEmpty($this->ctasFor('902'), 'the offer on its own is still an offer');
    }

    public function test_a_shop_that_has_not_said_what_it_wants_offers_nothing(): void
    {
        $this->inShop(fn () => LeadFlow::query()->update(['active' => false]));
        $this->article('903', 'כתבה', 'טקסט ארוך דיו כדי להיחשב מאמר.');

        $run = app(ComposeCallsToAction::class)->handle($this->shop->id);

        $this->assertSame(0, $run->output['pages']);
        $this->assertEmpty($this->ctasFor('903'));
    }

    public function test_the_gate_refuses_a_promise_however_it_got_there(): void
    {
        $rules = LeadRules::defaults();

        $this->assertNull(CtaComposer::reject(['headline' => 'מתלבטים לגבי פרקט?', 'body' => 'נשמח לעזור'], $rules));
        $this->assertSame('promises_a_result', CtaComposer::reject(['headline' => 'תשואה מובטחת', 'body' => 'x'], $rules));
        $this->assertSame('invented_urgency', CtaComposer::reject(['headline' => 'רק היום', 'body' => 'x'], $rules));
        $this->assertSame('headline_too_long', CtaComposer::reject(['headline' => str_repeat('א', 200), 'body' => 'x'], $rules));
        $this->assertSame('empty', CtaComposer::reject(['headline' => '', 'body' => 'x'], $rules));
    }

    public function test_the_page_is_offered_one_version_and_nothing_when_the_shop_is_not_collecting(): void
    {
        $this->article('904', 'בחירת פרקט', "פרקט עץ מביא חום לחלל ומשנה את אופי החדר.\n- כדאי לבדוק את עובי השכבה");

        app(ReadContentInCode::class)->handle($this->shop->id);
        app(ComposeCallsToAction::class)->handle($this->shop->id);

        $offer = app(OffersCallsToAction::class)->forPage($this->shop->id, 'content', '904', 'he');

        $this->assertNotNull($offer);
        $this->assertNotSame('', $offer['headline']);
        $this->assertSame('שיחת ייעוץ קצרה, בלי עלות', $offer['body']);

        Features::override('leads.enabled', false, $this->shop->id);
        $this->assertNull(app(OffersCallsToAction::class)->forPage($this->shop->id, 'content', '904', 'he'));
    }

    /** @return Collection<int, LeadCta> */
    private function ctasFor(string $externalId): Collection
    {
        return app(TenantContext::class)->run($this->shop->id, fn (): Collection => LeadCta::forPage('content', $externalId)->get());
    }
}
