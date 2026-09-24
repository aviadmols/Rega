<?php

namespace App\Modules\Leads\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Leads\Actions\LearnFromReaders;
use App\Modules\Leads\Contracts\OffersCallsToAction;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\LeadCta;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Models\LeadReview;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Readers settle which version is shown, and the reviewer is marked against them.
 */
final class LearnFromReadersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        Features::override('leads.enabled', true, $this->shop->id);

        $this->inShop(fn () => LeadFlow::query()->create([
            'shop_id' => $this->shop->id, 'version' => 1, 'goal' => LeadGoal::Advice,
            'offer' => 'שיחת ייעוץ', 'active' => true, 'consent' => 'x',
            'fields' => [['type' => 'phone', 'key' => 'phone', 'label' => 'טלפון', 'required' => true]],
        ]));
    }

    public function test_the_version_readers_click_is_the_one_they_are_shown(): void
    {
        // The reviewer preferred the first; readers prefer the second.
        $this->cta('subject', 'מתלבטים לגבי מסור?', score: 92);
        $this->cta('question', 'איזה מסור מתאים לעבודה שלכם?', score: 74);

        $this->saw('subject', 200, clicks: 4);
        $this->saw('question', 200, clicks: 30);

        $offer = app(OffersCallsToAction::class)->forPage($this->shop->id, 'content', '900', 'he');

        $this->assertSame('question', $offer['variant'], 'what happened beats what the reviewer thought');
    }

    public function test_a_version_nobody_has_seen_enough_of_still_gets_its_turn(): void
    {
        $this->cta('subject', 'מתלבטים לגבי מסור?', score: 80);
        $this->cta('question', 'איזה מסור מתאים?', score: 70);

        // One is well measured and does badly; the other has barely been seen.
        $this->saw('subject', 500, clicks: 5);
        $this->saw('question', 5, clicks: 0);

        $offer = app(OffersCallsToAction::class)->forPage($this->shop->id, 'content', '900', 'he');

        $this->assertSame('question', $offer['variant'], 'a page that only shows its best guess never finds out');
    }

    public function test_a_reviewer_whose_scores_predict_nothing_is_said_to_predict_nothing(): void
    {
        $liked = $this->cta('a', 'שורה שהמבקר אהב', score: 90);
        $allowed = $this->cta('b', 'שורה שהמבקר סבל', score: 72);
        $this->review($liked, 90);
        $this->review($allowed, 72);

        // Both clicked at the same rate: the score told nobody anything.
        $this->saw('a', 300, clicks: 30);
        $this->saw('b', 300, clicks: 30);

        $run = app(LearnFromReaders::class)->handle($this->shop->id);

        $this->assertSame('no_better_than_chance', $run->output['calibration']['verdict']);
        $this->assertSame(0.1, $run->output['calibration']['liked']['rate']);
    }

    public function test_a_reviewer_that_does_predict_is_said_to(): void
    {
        $liked = $this->cta('a', 'טובה', score: 90);
        $allowed = $this->cta('b', 'פחות', score: 72);
        $this->review($liked, 90);
        $this->review($allowed, 72);

        $this->saw('a', 300, clicks: 60);
        $this->saw('b', 300, clicks: 15);

        $run = app(LearnFromReaders::class)->handle($this->shop->id);

        $this->assertSame('predicts', $run->output['calibration']['verdict']);
    }

    public function test_with_too_little_traffic_no_verdict_is_reached(): void
    {
        $cta = $this->cta('a', 'שורה', score: 90);
        $this->review($cta, 90);
        $this->saw('a', 5, clicks: 1);

        $run = app(LearnFromReaders::class)->handle($this->shop->id);

        $this->assertSame('too_early', $run->output['calibration']['verdict']);
    }

    private function cta(string $variant, string $headline, int $score): LeadCta
    {
        return $this->inShop(fn (): LeadCta => LeadCta::query()->create([
            'shop_id' => $this->shop->id, 'page_type' => 'content', 'page_external_id' => '900',
            'variant' => $variant, 'headline' => $headline, 'body' => 'שיחת ייעוץ',
            'source' => 'model', 'model' => 'writer', 'score' => $score,
            'active' => true, 'composed_at' => now(),
        ]));
    }

    private function review(LeadCta $cta, int $score): void
    {
        $this->inShop(fn () => LeadReview::query()->create([
            'shop_id' => $this->shop->id, 'cta_id' => $cta->id,
            'writer' => 'writer', 'reviewer' => 'reviewer',
            'score' => $score, 'reasons' => [], 'reviewed_at' => now(),
        ]));
    }

    private function saw(string $variant, int $exposures, int $clicks): void
    {
        $this->inShop(function () use ($variant, $exposures, $clicks): void {
            foreach ([['exposure', $exposures], ['click', $clicks]] as [$type, $times]) {
                foreach (range(1, max(0, $times)) as $i) {
                    AnalyticsEvent::query()->create([
                        'shop_id' => $this->shop->id,
                        'event_id' => bin2hex(random_bytes(11)),
                        'type' => $type,
                        'page_type' => 'content',
                        'page_path' => '/a/900',
                        'content_external_id' => '900',
                        'candidate_id' => 'cta',
                        'model' => $variant,
                        'slot' => 'inline',
                        'visitor_hash' => hash('sha256', $variant.$i),
                        'session_id' => 'sess',
                        'preview' => false,
                        'holdout' => false,
                        'occurred_at' => now()->subHour(),
                    ]);
                }
            }
        });
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
