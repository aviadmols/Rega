<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Enrichment\Scanning\ContentDigest;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class ContentDigestTest extends TestCase
{
    public function test_plural_category_names_match_singular_words_with_hebrew_final_letters(): void
    {
        $matches = ContentDigest::matchCategories(
            'כיצד לבחור דק',
            'דק מעץ מתאים לגינה. כדאי לבחור עץ שמתאים לפרגולה ולדק. פרגולה מעץ.',
            $this->categories([['1', 'דקים', ['דקים']], ['2', 'עצים', ['עצים']], ['3', 'פרגולות', ['פרגולות']], ['4', 'ברגים', ['ברגים']]]),
        );

        $this->assertSame(['דקים', 'עצים', 'פרגולות'], array_map(fn (CatalogCategory $c): string => $c->name, $matches));
    }

    public function test_equal_scores_come_out_in_the_same_order_whatever_order_the_database_returns(): void
    {
        $rows = [
            ['10', 'עצים', ['עצים']],
            ['11', 'עצים לבניין', ['עצים', 'עצים לבניין']],
            ['12', 'אלון', ['עצים', 'אלון']],
            ['13', 'אורן', ['עצים', 'אורן']],
        ];
        $text = 'עצים לבניין, אלון ואורן. עצים ועצים ועצים.';

        $forward = ContentDigest::matchCategories('עצים', $text, $this->categories($rows));
        $backward = ContentDigest::matchCategories('עצים', $text, $this->categories(array_reverse($rows)));

        $this->assertSame(
            array_map(fn (CatalogCategory $c): string => $c->external_id, $forward),
            array_map(fn (CatalogCategory $c): string => $c->external_id, $backward),
        );
    }

    /**
     * @param  list<array{string, string, list<string>}>  $rows
     * @return Collection<int, CatalogCategory>
     */
    private function categories(array $rows): Collection
    {
        return collect($rows)->map(fn (array $row): CatalogCategory => (new CatalogCategory)->forceFill([
            'id' => 'cat-'.$row[0],
            'external_id' => $row[0],
            'name' => $row[1],
            'path' => $row[2],
        ]));
    }
}
