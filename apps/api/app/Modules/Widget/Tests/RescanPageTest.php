<?php

namespace App\Modules\Widget\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Filament\Operator\Pages\ProductPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A person looking at one page asks for it to be read again, right now, and is shown what that
 * changes in the widget — not told to wait for the night.
 */
final class RescanPageTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
    }

    public function test_rescanning_a_guide_reads_it_now_and_shows_what_the_widget_gains(): void
    {
        $this->article('900', 'איך בוחרים מקדחה?', "מקדחה טובה נבחרת לפי העבודה.\n- לבטון צריך פטישון\n- לעץ מספיקה מקדחה רגילה");

        $page = Livewire::test(ProductPage::class, ['shop' => $this->shop->id, 'type' => 'content', 'id' => '900']);

        $this->assertNull($page->instance()->changed);

        $page->call('rescan');
        $result = $page->instance()->changed;

        $this->assertNotEmpty(array_filter($result['added'], fn (string $line): bool => str_ends_with($line, 'לבטון צריך פטישון')), 'the widget gains the point');
        $this->assertSame([0, 2], $result['sections']['highlights'] ?? null, 'the highlights panel appears with both points');

        // Read again: nothing new to say, and the screen says so rather than repeating itself.
        $page->call('rescan');
        $this->assertSame([], $page->instance()->changed['added']);
        $this->assertSame([], $page->instance()->changed['removed']);
    }

    public function test_rescanning_a_product_reads_its_promises_and_leaves_shop_wide_facts_alone(): void
    {
        $product = $this->product('10', 'שמיכת כותנה', 'שמיכה עשויה בעבודת יד מ־100% כותנה, תוצרת פורטוגל.', []);

        // A fact a person decided on is not code's to take back.
        $kept = $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'kind' => FactKind::Promise, 'key' => 'made_in',
            'value_text' => 'איטליה', 'quote' => 'x', 'origin' => 'code', 'status' => FactStatus::Approved,
            'input_hash' => 'person', 'decided_by' => User::factory()->operator()->create()->id, 'decided_at' => now(),
        ]));

        $page = Livewire::test(ProductPage::class, ['shop' => $this->shop->id, 'type' => 'product', 'id' => '10']);
        $page->call('rescan');
        $result = $page->instance()->changed;

        $this->assertNotEmpty(array_filter($result['added'], fn (string $line): bool => str_contains($line, 'כותנה')), 'the promise reaches the page');
        $this->assertSame(FactStatus::Approved, $kept->fresh()->status, 'the person\'s decision stands');
    }
}
