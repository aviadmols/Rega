<?php

namespace Tests\Core;

use App\Core\Features\FeatureDefinition;
use App\Core\Features\FeatureManager;
use App\Core\Features\FeatureRegistry;
use App\Core\Features\UnknownFeature;
use App\Core\Settings\ValueSource;
use App\Core\Tenancy\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class FeatureManagerTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private FeatureManager $features;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new FeatureRegistry;
        $registry->register(new FeatureDefinition('chat', 'enabled', false));
        $registry->register(new FeatureDefinition('widget', 'hesitation', true));

        $this->tenant = new TenantContext;
        $this->features = new FeatureManager($registry, $this->tenant, Cache::store('array'));
    }

    public function test_the_declared_default_applies_when_nothing_is_overridden(): void
    {
        $this->assertFalse($this->features->enabled('chat.enabled'));
        $this->assertTrue($this->features->enabled('widget.hesitation', 'shop-a'));
        $this->assertSame(ValueSource::Default, $this->features->source('chat.enabled', 'shop-a'));
    }

    public function test_a_global_override_applies_to_every_shop(): void
    {
        $this->features->override('chat.enabled', true);

        $this->assertTrue($this->features->enabled('chat.enabled'));
        $this->assertTrue($this->features->enabled('chat.enabled', 'shop-a'));
        $this->assertSame(ValueSource::Global, $this->features->source('chat.enabled', 'shop-a'));
    }

    public function test_a_shop_override_beats_the_global_override(): void
    {
        $this->features->override('chat.enabled', true);
        $this->features->override('chat.enabled', false, 'shop-a');

        $this->assertFalse($this->features->enabled('chat.enabled', 'shop-a'));
        $this->assertTrue($this->features->enabled('chat.enabled', 'shop-b'));
        $this->assertSame(ValueSource::Shop, $this->features->source('chat.enabled', 'shop-a'));
        $this->assertFalse($this->features->overrideFor('chat.enabled', 'shop-a'));
        $this->assertNull($this->features->overrideFor('chat.enabled', 'shop-b'));
    }

    public function test_clearing_an_override_returns_to_inheriting(): void
    {
        $this->features->override('widget.hesitation', false, 'shop-a');
        $this->features->clearOverride('widget.hesitation', 'shop-a');

        $this->assertTrue($this->features->enabled('widget.hesitation', 'shop-a'));
        $this->assertDatabaseCount('feature_overrides', 0);
    }

    public function test_purging_a_shop_removes_only_that_shops_overrides(): void
    {
        $this->features->override('chat.enabled', true);
        $this->features->override('chat.enabled', false, 'shop-a');
        $this->features->override('widget.hesitation', false, 'shop-b');

        $this->features->purgeShop('shop-a');

        $this->assertTrue($this->features->enabled('chat.enabled', 'shop-a'));
        $this->assertFalse($this->features->enabled('widget.hesitation', 'shop-b'));
        $this->assertDatabaseCount('feature_overrides', 2);
    }

    public function test_the_current_shop_comes_from_the_tenant_context(): void
    {
        $this->features->override('chat.enabled', true, 'shop-a');

        $this->assertTrue($this->tenant->run('shop-a', fn () => $this->features->enabledForCurrentShop('chat.enabled')));

        $this->expectException(MissingTenantContext::class);
        $this->features->enabledForCurrentShop('chat.enabled');
    }

    public function test_an_undeclared_feature_is_a_bug_not_a_false(): void
    {
        $this->expectException(UnknownFeature::class);

        $this->features->enabled('chat.typo');
    }

    public function test_writes_are_visible_to_a_new_manager_through_the_shared_cache(): void
    {
        $cache = Cache::store('array');
        $registry = new FeatureRegistry;
        $registry->register(new FeatureDefinition('chat', 'enabled', false));

        $first = new FeatureManager($registry, new TenantContext, $cache);
        $this->assertFalse($first->enabled('chat.enabled'));

        $first->override('chat.enabled', true);

        $second = new FeatureManager($registry, new TenantContext, $cache);
        $this->assertTrue($second->enabled('chat.enabled'));
    }
}
