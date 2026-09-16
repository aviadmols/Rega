<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\ValueSource;
use App\Modules\Admin\Filament\Operator\Pages\Configuration;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ConfigurationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_the_scope_selector_shows_which_scope_is_being_edited(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(Configuration::class)->assertSet('data.scope', 'global');
        Livewire::withQueryParams(['shop' => $shop->id])->test(Configuration::class)->assertSet('data.scope', $shop->id);

        // An unknown shop id falls back to the global scope instead of editing nothing.
        Livewire::withQueryParams(['shop' => 'missing'])->test(Configuration::class)
            ->assertSet('shop', null)
            ->assertSet('data.scope', 'global');
    }

    public function test_every_declared_feature_and_setting_is_on_the_screen(): void
    {
        Livewire::test(Configuration::class)
            ->assertFormFieldExists('f__tenancy__api_access')
            ->assertFormFieldExists('f__admin__merchant_panel')
            ->assertFormFieldExists('s__tenancy__max_active_api_keys')
            ->assertFormFieldExists('s__tenancy__api_requests_per_minute')
            ->assertFormFieldExists('s__admin__default_locale');
    }

    public function test_global_values_are_saved_for_the_whole_system(): void
    {
        Livewire::test(Configuration::class)
            ->fillForm([
                'f__tenancy__api_access' => 'off',
                's__tenancy__max_active_api_keys' => '5',
                's__admin__default_locale' => 'en',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(Features::enabled('tenancy.api_access'));
        $this->assertSame(5, Settings::get('tenancy.max_active_api_keys'));
        $this->assertSame('en', Settings::get('admin.default_locale'));
    }

    public function test_shop_values_override_only_that_shop_and_hide_global_only_settings(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();

        Livewire::withQueryParams(['shop' => $shop->id])
            ->test(Configuration::class)
            ->assertFormFieldDoesNotExist('s__admin__default_locale')
            ->fillForm(['s__tenancy__api_requests_per_minute' => '120'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(120, Settings::get('tenancy.api_requests_per_minute', $shop->id));
        $this->assertSame(ValueSource::Shop, Settings::source('tenancy.api_requests_per_minute', $shop->id));
        $this->assertSame(600, Settings::get('tenancy.api_requests_per_minute', $other->id));
    }

    public function test_an_out_of_range_value_saves_nothing(): void
    {
        Livewire::test(Configuration::class)
            ->fillForm([
                'f__tenancy__api_access' => 'off',
                's__tenancy__max_active_api_keys' => '500',
            ])
            ->call('save')
            ->assertHasErrors(['data.s__tenancy__max_active_api_keys']);

        $this->assertTrue(Features::enabled('tenancy.api_access'));
        $this->assertDatabaseCount('setting_overrides', 0);
    }

    public function test_emptying_a_field_returns_it_to_inheriting(): void
    {
        $shop = Shop::factory()->create();
        Settings::set('tenancy.max_active_api_keys', 7, $shop->id);
        Features::override('tenancy.api_access', false, $shop->id);

        Livewire::withQueryParams(['shop' => $shop->id])
            ->test(Configuration::class)
            ->assertSet('data.s__tenancy__max_active_api_keys', '7')
            ->assertSet('data.f__tenancy__api_access', 'off')
            ->fillForm([
                's__tenancy__max_active_api_keys' => null,
                'f__tenancy__api_access' => 'inherit',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ValueSource::Default, Settings::source('tenancy.max_active_api_keys', $shop->id));
        $this->assertSame(ValueSource::Default, Features::source('tenancy.api_access', $shop->id));
    }

    public function test_the_page_renders_in_both_languages(): void
    {
        $shop = Shop::factory()->create();

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get('/operator/configuration?shop='.$shop->id)
                ->assertOk()
                ->assertSee(__('tenancy::settings.max_active_api_keys.label', [], $locale));
        }
    }
}
