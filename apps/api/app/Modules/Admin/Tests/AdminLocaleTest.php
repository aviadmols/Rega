<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both panels work in Hebrew (right-to-left) and English (left-to-right).
 */
final class AdminLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hebrew_browser_gets_a_right_to_left_hebrew_panel(): void
    {
        $this->withHeader('Accept-Language', 'he-IL,he;q=0.9')
            ->get('/operator/login')
            ->assertOk()
            ->assertSee('lang="he"', false)
            ->assertSee('dir="rtl"', false);
    }

    public function test_an_english_browser_gets_a_left_to_right_english_panel(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get('/merchant/login')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false);
    }

    public function test_the_operators_default_applies_when_the_browser_asks_for_neither(): void
    {
        Settings::set('admin.default_locale', 'en');

        $this->withHeader('Accept-Language', 'fr-FR')
            ->get('/operator/login')
            ->assertSee('dir="ltr"', false);
    }

    public function test_the_users_choice_beats_the_browser(): void
    {
        $user = User::factory()->operator()->locale('he')->create();

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'en-US')
            ->get('/operator/configuration')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('admin::configuration.title', [], 'he'));
    }

    public function test_switching_language_on_the_login_screen_is_remembered_for_the_session(): void
    {
        $this->withHeader('Accept-Language', 'he')
            ->get('/admin/locale/en')
            ->assertRedirect()
            ->assertSessionHas('admin_locale', 'en');

        $this->withHeader('Accept-Language', 'he')
            ->get('/operator/login')
            ->assertSee('dir="ltr"', false);
    }

    public function test_switching_language_while_signed_in_is_saved_on_the_user(): void
    {
        $user = User::factory()->operator()->create();

        $this->actingAs($user)->get('/admin/locale/en')->assertRedirect();

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_unsupported_languages_are_refused(): void
    {
        $this->get('/admin/locale/fr')->assertNotFound();
    }

    public function test_the_switch_never_redirects_to_another_site(): void
    {
        $this->withHeader('Referer', 'https://evil.example/phish')
            ->get('/admin/locale/en')
            ->assertRedirect(url('/'));
    }

    public function test_the_signed_in_menu_offers_the_other_language(): void
    {
        $user = User::factory()->operator()->locale('he')->create();

        $this->actingAs($user)
            ->get('/operator/configuration')
            ->assertSee(route('admin.locale', ['locale' => 'en']), false);
    }
}
