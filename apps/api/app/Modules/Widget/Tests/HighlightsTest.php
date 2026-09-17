<?php

namespace App\Modules\Widget\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Actions\CreateTaskFile;
use App\Modules\Enrichment\Actions\ImportTaskResults;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Prompts\PromptLibrary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Highlights: written from the product's text, checked by code, shown once a checker approves. */
final class HighlightsTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    // Installation advice comes after more than the general text budget (1000 characters) of praise.
    private const TEXT = 'טיק ברומזי הוא עץ טרופי בעל מראה חם וטבעי שמתאים לפרויקטים מעוצבים ואדריכליים. '
        .'הטקסטורה הייחודית של העץ והגוון העשיר מעניקים לכל פרויקט מראה יוקרתי ומרשים לאורך זמן. '
        .'העץ נבחר בקפידה ועובר ייבוש מבוקר כדי לשמור על יציבות ואיכות לאורך שנים רבות של שימוש. '
        .'בזכות החריצה המדויקת מתקבל גימור נקי ומדויק שמשתלב בסגנונות עיצוב מודרניים וכפריים כאחד. '
        .'הלוחות מגיעים במגוון אורכים כדי להתאים לכל פרויקט ולחסוך בעודפי חומר ובזמן עבודה. '
        .'צוות החנות ישמח לעזור בבחירת העץ המתאים ובתכנון הכמויות הנדרשות לפרויקט שלכם. '
        .'עץ טיק נחשב לאחד מסוגי העץ האהובים על מעצבים ואדריכלים בזכות המראה והעמידות שלו. '
        .'הלוחות מתאימים לשילוב עם סוגי עץ נוספים וחומרים שונים כמו אבן, בטון ומתכת. '
        .'לאורך השנים העץ מקבל גוון עמוק ועשיר יותר ומוסיף אופי ייחודי לכל מקום שבו הוא מותקן. '
        .'הלוחות עוברים בדיקת איכות לפני המשלוח כדי לוודא שכל לוח עומד בדרישות ובמידות. '
        .'המוצר מתאים לפרויקטים פרטיים ומסחריים כאחד ומשמש אנשי מקצוע רבים בכל רחבי הארץ. '
        ."\n".'שימושים ויישומים: טיק ברומזי 19x120 מ"מ מחורץ מתאים לבניית דקים מעץ וחיפויי קיר ותקרה. '
        .'מומלץ להשתמש בברגים איכותיים המתאימים לעץ קשה. חשוב לשמור על מרווחי התפשטות בעת התקנת דקים וחיפויים. '
        .'האם מתאים לשימוש חיצוני? כן, מתאים לשימוש חוץ ופנים. המחיר מוצג לפי מטר רץ ומתעדכן לפי האורך שנבחר.';

    public function test_highlights_rest_on_quotes_from_the_text_and_show_after_review(): void
    {
        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $wood = $this->category('2212', 'עצים');
        $teak = $this->product('31538', 'טיק ברומזי 19x120 מ"מ מחורץ במגוון אורכים', self::TEXT, [$wood], [
            'payload' => ['description' => self::TEXT, 'categories' => [['id' => '2212', 'path' => ['עצים']]]],
        ]);
        $short = $this->product('31539', 'טיק ברומזי 19x95 מ"מ', 'טיק', [$wood]);

        $vocabulary = app(ImportVocabulary::class)->handle($this->shop->id, ImportVocabulary::template('wood'), 'test')['vocabulary'];
        app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $teak->id, 'vocabulary_id' => $vocabulary->id, 'kind' => 'choice',
            'key' => 'species', 'value_text' => 'teak', 'origin' => 'code+model', 'status' => 'approved', 'input_hash' => 'x',
        ]));

        $batch = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::ProductHighlights, $vocabulary->id)['batch'];
        $items = $this->inShop(fn () => $batch->items()->get());

        $this->assertSame([$teak->id], $items->pluck('subject_id')->all(), 'a product with nothing beyond its title gets no request');
        $item = $items->first();
        $this->assertStringStartsWith('hl-31538-', $item->custom_id);
        $this->assertContains('סוג העץ: טיק', $item->request['known'], 'the facts already shown, so the writer does not repeat them');
        $this->assertStringContainsString('מרווחי התפשטות', $item->request['text']);
        $this->assertSame(PromptLibrary::version(TaskType::ProductHighlights), $batch->prompt_version);

        $answer = ['id' => '31538', 'highlights' => [
            ['key' => 'לחוץ ולפנים', 'text' => 'מתאים לשימוש חוץ ופנים.', 'quote' => 'מתאים לשימוש חוץ ופנים'],
            ['key' => 'להתקנה', 'text' => 'ברגים לעץ קשה ומרווחי התפשטות בין הלוחות.', 'quote' => 'מומלץ להשתמש בברגים איכותיים המתאימים לעץ קשה. חשוב לשמור על מרווחי התפשטות'],
            ['key' => 'עמיד 25 שנה', 'text' => 'לא נסדק גם אחרי שנים.', 'quote' => 'מתאים לשימוש חוץ ופנים'],
            ['key' => 'העץ הטוב ביותר', 'text' => 'הטיק הכי עמיד לדק.', 'quote' => 'מתאים לבניית דקים מעץ'],
            ['key' => 'במחיר מבצע', 'text' => 'רק 89 ₪ למטר.', 'quote' => 'המחיר מוצג לפי מטר רץ'],
        ]];
        $contents = json_encode(['type' => 'header', 'batch_id' => $batch->id])."\n".json_encode(['custom_id' => $item->custom_id, 'output' => $answer], JSON_UNESCAPED_UNICODE);
        app(ImportTaskResults::class)->handle($this->inShop(fn () => $batch->fresh()), $contents, 'claude-haiku-4-5');

        $item = $this->inShop(fn () => $item->fresh());
        $this->assertContains('number_not_in_quote:3', $item->problems);
        $this->assertContains('too_many:5', $item->problems, 'four at most');
        $this->assertContains('superlative_not_in_quote:4', $item->problems);

        $highlights = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $teak->id)->where('kind', FactKind::Highlight)->orderBy('value_number')->get());
        $this->assertSame(['לחוץ ולפנים', 'להתקנה'], $highlights->pluck('key')->all());
        $this->assertTrue($highlights->every(fn (EnrichmentFact $f): bool => $f->status === FactStatus::AwaitingReview), 'shoppers read these: a checker approves first');

        // A checker's prompt says how to judge a highlight, and the checker reads the text the writer read.
        $review = app(CreateTaskFile::class)->handle($this->shop->id, TaskType::FactReview, $vocabulary->id, 1, ['subject' => 'product'])['batch'];
        $this->assertStringContainsString('`highlight` claims', $review->system_prompt);
        $reviewItem = $this->inShop(fn () => $review->items()->where('subject_id', $teak->id)->sole());
        $this->assertStringContainsString('מרווחי התפשטות', $reviewItem->request['text'], 'past the general text budget, where the highlight came from');

        $this->inShop(fn () => EnrichmentFact::query()->whereKey($highlights->pluck('id'))->update(['status' => FactStatus::Approved]));

        $bank = $this->get('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/page?type=product&id=31538&locale=he', ['Origin' => 'https://store.test'])->assertOk()->json();
        $section = collect($bank['sections'])->firstWhere('candidate', 'highlights');
        $this->assertSame('explainer', $section['model']);
        $this->assertSame([['key' => 'לחוץ ולפנים', 'text' => 'מתאים לשימוש חוץ ופנים.'], ['key' => 'להתקנה', 'text' => 'ברגים לעץ קשה ומרווחי התפשטות בין הלוחות.']], $section['items']);

        // The same sentence pasted on many products says little about this one: last, and never the key sentence.
        foreach (range(1, 4) as $n) {
            $other = $this->product('4000'.$n, 'לוח עץ '.$n, 'x', [$wood]);
            $this->inShop(fn () => EnrichmentFact::query()->create([
                'shop_id' => $this->shop->id, 'product_id' => $other->id, 'kind' => 'highlight', 'key' => 'לחוץ ולפנים',
                'value_number' => 1, 'value_text' => 'מתאים לשימוש חוץ ופנים.', 'quote' => 'מתאים לשימוש חוץ ופנים',
                'origin' => 'model', 'status' => 'approved', 'input_hash' => 'x',
            ]));
        }
        Cache::flush();

        $bank = $this->get('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/page?type=product&id=31538&locale=he', ['Origin' => 'https://store.test'])->assertOk()->json();
        $items = collect($bank['sections'])->firstWhere('candidate', 'highlights')['items'];
        $this->assertSame(['להתקנה', 'לחוץ ולפנים'], array_column($items, 'key'));
        $this->assertTrue($items[1]['common']);
        $this->assertArrayNotHasKey('common', $items[0]);
        $this->assertSame('31539', $short->external_id);
    }
}
