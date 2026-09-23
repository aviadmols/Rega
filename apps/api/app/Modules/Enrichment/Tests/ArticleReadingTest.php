<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Actions\ReadContentInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\ArticleReader;
use App\Modules\Enrichment\Support\ContentRules;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An article read on its own terms, not as a route to a product: what it is made of, what it
 * concludes, what it answers and who it is for. In code, from marker words that live in a
 * ruleset rather than in the reader.
 */
final class ArticleReadingTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const ARTICLE = <<<'TXT'
    למי זה מתאים: נגרים חובבים
    עץ אלון הוא מהעצים הקשים והעמידים ביותר, ולכן הוא מועדף לרהיטים שאמורים להחזיק שנים רבות.

    היתרונות של אלון
    האלום עמיד בפני שריטות ומתאים לשימוש יומיומי בסלון ובמטבח, גם בבתים עם ילדים.
    - עמידות גבוהה לאורך שנים רבות
    - מראה חם שמשתלב כמעט בכל סגנון
    1. מתאים לרהיטים שנמצאים בשימוש כבד
    חשוב לדעת שאלון סופג לחות ולכן לא מומלץ לחדרי אמבטיה.

    האם אלון מתאים למטבח?
    כן, בתנאי שמשתמשים בגימור מתאים ומקפידים על ניקוי יבש לאחר כל שימוש במים.
    TXT;

    public function test_it_reads_the_shape_of_an_article(): void
    {
        $reading = ArticleReader::read('עץ אלון יתרונות וחסרונות', self::ARTICLE, ContentRules::defaults());

        // Headings are short lines with nothing to end them, that something longer follows.
        $this->assertContains('היתרונות של אלון', $reading['sections']);
        $this->assertNotContains('- עמידות גבוהה לאורך שנים רבות', $reading['sections'], 'a listed line is an item, not a heading');

        // Takeaways: the listed lines and the line a marker word opens.
        $texts = array_column($reading['takeaways'], 'text');
        $this->assertContains('עמידות גבוהה לאורך שנים רבות', $texts);
        $this->assertContains('מתאים לרהיטים שנמצאים בשימוש כבד', $texts, 'numbered counts too');
        $this->assertContains('חשוב לדעת שאלון סופג לחות ולכן לא מומלץ לחדרי אמבטיה.', $texts);

        // Every one keeps the line it came from.
        foreach ($reading['takeaways'] as $takeaway) {
            $this->assertStringContainsString($takeaway['text'], $takeaway['quote']);
        }

        $this->assertSame('האם אלון מתאים למטבח?', $reading['question']);
        $this->assertSame('נגרים חובבים', $reading['audience']);
        $this->assertGreaterThan(0, $reading['words']);
        $this->assertGreaterThanOrEqual(1, $reading['minutes']);
    }

    public function test_a_title_that_asks_is_the_question_the_article_answers(): void
    {
        $reading = ArticleReader::read('איך בוחרים מקדחה?', 'טקסט קצר על מקדחות.', ContentRules::defaults());

        $this->assertSame('איך בוחרים מקדחה?', $reading['question']);
    }

    public function test_an_article_with_nothing_to_take_says_so_rather_than_inventing(): void
    {
        $reading = ArticleReader::read('הודעה', 'החנות סגורה מחר.', ContentRules::defaults());

        $this->assertSame([], $reading['takeaways']);
        $this->assertSame([], $reading['sections']);
        $this->assertNull($reading['question']);
        $this->assertNull($reading['audience']);
    }

    public function test_a_marker_the_rules_do_not_know_finds_nothing_until_it_is_added(): void
    {
        $line = 'המסקנה: אלון עדיף על אורן לרהיטים.';

        $this->assertSame([], ArticleReader::read('כותרת', $line, ContentRules::defaults())['takeaways']);

        // That is the whole point of the markers being data: the reading improves without the
        // reader changing.
        $rules = ContentRules::defaults();
        $rules['takeaway_markers'][] = 'המסקנה';

        $this->assertSame($line, ArticleReader::read('כותרת', $line, $rules)['takeaways'][0]['text']);
    }

    public function test_reading_the_shop_writes_a_fact_per_takeaway_with_its_line(): void
    {
        $this->buildShop();
        $article = $this->article('900', 'עץ אלון יתרונות וחסרונות', self::ARTICLE);

        app(ReadContentInCode::class)->handle($this->shop->id);

        $facts = $this->inShop(fn () => EnrichmentFact::query()
            ->where('content_id', $article->id)
            ->where('status', FactStatus::Approved)
            ->get());

        $takeaways = $facts->where('kind', FactKind::Highlight);
        $this->assertGreaterThanOrEqual(3, $takeaways->count());
        $this->assertSame([1, 2, 3], $takeaways->take(3)->pluck('value_number')->map(fn ($n): int => (int) $n)->all(), 'in the order the article makes them');

        $this->assertSame('האם אלון מתאים למטבח?', $facts->firstWhere('key', 'answers')?->value_text);
        $this->assertSame('נגרים חובבים', $facts->firstWhere('key', 'audience')?->value_text);
        $this->assertSame('min', $facts->firstWhere('key', 'reading_minutes')?->unit);
    }

    public function test_an_article_rewritten_loses_what_it_no_longer_says(): void
    {
        $this->buildShop();
        $article = $this->article('900', 'כותרת', self::ARTICLE);
        app(ReadContentInCode::class)->handle($this->shop->id);

        $this->inShop(fn () => $article->update(['body' => 'עכשיו זה סתם משפט אחד.', 'hash' => md5('rewritten')]));
        app(ReadContentInCode::class)->handle($this->shop->id);

        $left = $this->inShop(fn () => EnrichmentFact::query()
            ->where('content_id', $article->id)
            ->where('kind', FactKind::Highlight)
            ->where('status', FactStatus::Approved)
            ->count());

        $this->assertSame(0, $left, 'a takeaway the article stopped making is gone');
    }
}
