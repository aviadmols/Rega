<?php

namespace App\Modules\Widget\Tests;

use App\Modules\Widget\Support\GuideRelevance;
use PHPUnit\Framework\TestCase;

final class GuideRelevanceTest extends TestCase
{
    private GuideRelevance $relevance;

    protected function setUp(): void
    {
        parent::setUp();

        // wood > pine > treated, wood > for building, wood > ipe, bamboo, roofs > light roofs
        $this->relevance = new GuideRelevance([
            'wood' => null, 'pine' => 'wood', 'treated' => 'pine', 'building' => 'wood', 'ipe' => 'wood',
            'bamboo' => null, 'roofs' => null, 'light_roofs' => 'roofs',
        ]);
    }

    public function test_the_topic_comes_from_the_category_tree(): void
    {
        $product = ['wood', 'pine', 'treated'];

        $this->assertSame(GuideRelevance::BRANCH, $this->relevance->topic($product, ['pine']));
        $this->assertSame(GuideRelevance::BRANCH, $this->relevance->topic(['treated'], ['pine']), 'a parent branch counts without the product listing it');
        $this->assertSame(GuideRelevance::BRANCH, $this->relevance->topic(['pine'], ['treated']));
        $this->assertSame(GuideRelevance::DEPARTMENT, $this->relevance->topic($product, ['building']));
        $this->assertSame(GuideRelevance::DEPARTMENT, $this->relevance->topic($product, ['wood']));
        $this->assertSame(GuideRelevance::OTHER, $this->relevance->topic($product, ['bamboo']));
        $this->assertSame(GuideRelevance::OTHER, $this->relevance->topic($product, ['light_roofs']));
        $this->assertSame(GuideRelevance::UNKNOWN, $this->relevance->topic($product, []));
    }

    public function test_a_treated_pine_board_for_fences_gets_fence_guides_on_its_own_material_only(): void
    {
        $product = ['wood', 'pine', 'treated'];
        $uses = ['fence', 'outdoor_cladding'];
        $score = fn (array $article): ?int => $this->relevance->score($product, $uses, $article);

        $this->assertNull($score(['kind' => 'material_guide', 'value' => 'high', 'categories' => ['bamboo'], 'uses' => ['fence']]), 'bamboo is another department');
        $this->assertNull($score(['kind' => 'material_guide', 'value' => 'high', 'categories' => ['wood', 'ipe'], 'uses' => ['fence']]), 'an ipe guide is another material in the same department');
        $this->assertNull($score(['kind' => 'buying_guide', 'value' => 'high', 'categories' => ['pine'], 'uses' => ['pergola']]), 'other jobs');
        $this->assertNull($score(['kind' => 'store_page', 'categories' => ['pine'], 'matched' => true]), 'not a guide');
        $this->assertNull($score(['kind' => 'how_to', 'value' => 'none', 'categories' => ['pine'], 'matched' => true]));

        $pineGuide = $score(['kind' => 'material_guide', 'value' => 'medium', 'categories' => ['pine'], 'uses' => [], 'matched' => true]);
        $fenceGuide = $score(['kind' => 'buying_guide', 'value' => 'high', 'categories' => ['building'], 'uses' => ['fence']]);
        $noCategories = $score(['kind' => 'how_to', 'uses' => ['fence']]);

        $this->assertNotNull($pineGuide);
        $this->assertNotNull($fenceGuide);
        $this->assertNotNull($noCategories);
        $this->assertGreaterThan($noCategories, $fenceGuide, 'the same department ranks above an article with no categories');
        $this->assertNull($score(['kind' => 'how_to', 'categories' => ['building']]), 'no shared job, not matched, not the same branch: nothing ties it to the product');
    }
}
