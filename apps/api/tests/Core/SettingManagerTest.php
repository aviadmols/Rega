<?php

namespace Tests\Core;

use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\Models\SettingOverride;
use App\Core\Settings\OverrideScope;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingManager;
use App\Core\Settings\SettingRegistry;
use App\Core\Settings\SettingScope;
use App\Core\Settings\SettingType;
use App\Core\Settings\UnknownSetting;
use App\Core\Settings\ValueSource;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SettingManagerTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private SettingManager $settings;

    private SettingRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = $registry = new SettingRegistry;
        $registry->register(new SettingDefinition('bank', 'retire_after_exposures', SettingType::Int, 150, SettingScope::Shop, 20, 5000));
        $registry->register(new SettingDefinition('budget', 'monthly_cap_usd', SettingType::Float, 50.0, SettingScope::Global, 0.0, 10000.0));
        $registry->register(new SettingDefinition('widget', 'tone', SettingType::Enum, 'friendly', SettingScope::Shop, options: ['friendly', 'neutral']));

        $this->tenant = new TenantContext;
        $this->settings = new SettingManager($registry, $this->tenant, Cache::store('array'));
    }

    public function test_resolution_goes_shop_then_global_then_default(): void
    {
        $this->assertSame(150, $this->settings->get('bank.retire_after_exposures', 'shop-a'));
        $this->assertSame(ValueSource::Default, $this->settings->source('bank.retire_after_exposures', 'shop-a'));

        $this->settings->set('bank.retire_after_exposures', 300);
        $this->assertSame(300, $this->settings->get('bank.retire_after_exposures', 'shop-a'));
        $this->assertSame(ValueSource::Global, $this->settings->source('bank.retire_after_exposures', 'shop-a'));

        $this->settings->set('bank.retire_after_exposures', '60', 'shop-a');
        $this->assertSame(60, $this->settings->get('bank.retire_after_exposures', 'shop-a'));
        $this->assertSame(300, $this->settings->get('bank.retire_after_exposures', 'shop-b'));
        $this->assertSame(ValueSource::Shop, $this->settings->source('bank.retire_after_exposures', 'shop-a'));
    }

    public function test_values_keep_their_type_through_storage(): void
    {
        $this->settings->set('budget.monthly_cap_usd', '12.5');
        $this->settings->set('widget.tone', 'neutral', 'shop-a');

        // A manager with an empty cache has to read the rows back from the database.
        $fresh = new SettingManager($this->registry, new TenantContext, new Repository(new ArrayStore));

        $this->assertSame(12.5, $fresh->get('budget.monthly_cap_usd'));
        $this->assertSame('neutral', $fresh->get('widget.tone', 'shop-a'));
    }

    public function test_out_of_range_values_are_never_stored(): void
    {
        try {
            $this->settings->set('bank.retire_after_exposures', 5, 'shop-a');
            $this->fail('Expected InvalidSettingValue.');
        } catch (InvalidSettingValue $e) {
            $this->assertSame('below_min', $e->reason);
        }

        $this->assertDatabaseCount('setting_overrides', 0);
    }

    public function test_a_global_setting_cannot_be_overridden_per_shop(): void
    {
        $this->expectException(UnknownSetting::class);

        $this->settings->set('budget.monthly_cap_usd', 10, 'shop-a');
    }

    public function test_reading_a_global_setting_for_a_shop_returns_the_global_value(): void
    {
        $this->settings->set('budget.monthly_cap_usd', 80);

        $this->assertSame(80.0, $this->settings->get('budget.monthly_cap_usd', 'shop-a'));
    }

    public function test_a_stored_value_that_no_longer_validates_is_ignored(): void
    {
        // As if the module later tightened the range and an old override is still stored.
        SettingOverride::query()->create(['key' => 'bank.retire_after_exposures', 'scope' => 'shop-a', 'value' => 9]);
        SettingOverride::query()->create(['key' => 'bank.retire_after_exposures', 'scope' => OverrideScope::GLOBAL, 'value' => 400]);

        $this->assertSame(400, $this->settings->get('bank.retire_after_exposures', 'shop-a'));
        $this->assertSame(ValueSource::Global, $this->settings->source('bank.retire_after_exposures', 'shop-a'));
        $this->assertNull($this->settings->overrideFor('bank.retire_after_exposures', 'shop-a'));
    }

    public function test_clear_and_purge(): void
    {
        $this->settings->set('bank.retire_after_exposures', 60, 'shop-a');
        $this->settings->set('widget.tone', 'neutral', 'shop-a');
        $this->settings->set('widget.tone', 'neutral', 'shop-b');

        $this->settings->clear('widget.tone', 'shop-b');
        $this->assertSame('friendly', $this->settings->get('widget.tone', 'shop-b'));

        $this->settings->purgeShop('shop-a');
        $this->assertSame(150, $this->settings->get('bank.retire_after_exposures', 'shop-a'));
        $this->assertDatabaseCount('setting_overrides', 0);
    }

    public function test_the_current_shop_comes_from_the_tenant_context(): void
    {
        $this->settings->set('widget.tone', 'neutral', 'shop-a');

        $this->assertSame('neutral', $this->tenant->run('shop-a', fn () => $this->settings->getForCurrentShop('widget.tone')));
    }
}
