<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Filament\Operator\Pages\OpeningHours as Screen;
use App\Modules\Widget\Support\OpeningHours;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * When the team answers, a day at a time. A day that is off is closed, and nothing has to be
 * inferred from an empty field that might have meant "same as the others".
 */
final class OpeningHoursTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_day_is_open_only_inside_its_own_range(): void
    {
        $week = OpeningHours::fromDays([
            'sunday' => '09:00-18:00', 'monday' => '', 'tuesday' => '', 'wednesday' => '',
            'thursday' => '', 'friday' => '09:00-13:00', 'saturday' => '',
        ]);

        // A Sunday in Jerusalem.
        $this->assertTrue($week->openAt(new DateTimeImmutable('2026-09-20 09:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
        $this->assertTrue($week->openAt(new DateTimeImmutable('2026-09-20 17:59:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
        $this->assertFalse($week->openAt(new DateTimeImmutable('2026-09-20 18:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'), 'the end is not inside');
        $this->assertFalse($week->openAt(new DateTimeImmutable('2026-09-20 08:59:00 Asia/Jerusalem'), 'Asia/Jerusalem'));

        // Monday is off, whatever the hour.
        $this->assertFalse($week->openAt(new DateTimeImmutable('2026-09-21 12:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'));

        // Friday has its own hours.
        $this->assertTrue($week->openAt(new DateTimeImmutable('2026-09-25 12:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
        $this->assertFalse($week->openAt(new DateTimeImmutable('2026-09-25 14:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
    }

    public function test_the_shops_own_clock_decides_not_the_servers(): void
    {
        $week = OpeningHours::fromDays(['sunday' => '09:00-18:00']);

        // 07:00 UTC on a Sunday is 10:00 in Jerusalem: open there, closed in London.
        $moment = new DateTimeImmutable('2026-09-20 07:00:00 UTC');

        $this->assertTrue($week->openAt($moment, 'Asia/Jerusalem'));
        $this->assertFalse($week->openAt($moment, 'Pacific/Auckland'), 'where it is already Sunday evening');
    }

    public function test_a_night_shift_ends_on_the_day_it_started(): void
    {
        $week = OpeningHours::fromDays(['sunday' => '22:00-02:00']);

        $this->assertTrue($week->openAt(new DateTimeImmutable('2026-09-20 23:30:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
        $this->assertTrue($week->openAt(new DateTimeImmutable('2026-09-20 01:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
        $this->assertFalse($week->openAt(new DateTimeImmutable('2026-09-20 03:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'));
    }

    public function test_nonsense_in_a_day_is_a_closed_day_not_an_error(): void
    {
        foreach (['', 'כל היום', '9-18', '25:00-26:00', '09:00', '12:00-12:00'] as $nonsense) {
            $this->assertFalse(
                OpeningHours::fromDays(['sunday' => $nonsense])->openAt(new DateTimeImmutable('2026-09-20 12:00:00 Asia/Jerusalem'), 'Asia/Jerusalem'),
                $nonsense,
            );
        }

        $this->assertTrue(OpeningHours::fromDays([])->alwaysClosed());
    }

    public function test_the_screen_saves_a_day_and_turns_one_off(): void
    {
        $this->buildShop();
        $this->actingAs(User::factory()->operator()->create());
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $screen = Livewire::test(Screen::class);

            // The defaults arrive split into a switch and two times.
            $screen->assertSet('data.open_sunday', true)
                ->assertSet('data.from_sunday', '09:00')
                ->assertSet('data.open_saturday', false);

            $screen->set('data.from_sunday', '08:30')
                ->set('data.until_sunday', '16:00')
                ->set('data.open_monday', false)
                ->set('data.open_saturday', true)
                ->set('data.from_saturday', '20:00')
                ->set('data.until_saturday', '23:00')
                ->call('save')
                ->assertHasNoErrors();

            $this->assertSame('08:30-16:00', Settings::get('widget.hours_sunday', $this->shop->id));
            $this->assertSame('', Settings::get('widget.hours_monday', $this->shop->id), 'a day turned off is closed');
            $this->assertSame('20:00-23:00', Settings::get('widget.hours_saturday', $this->shop->id));
        });
    }

    public function test_the_screen_asks_for_a_shop_before_it_saves_anything(): void
    {
        $this->buildShop();
        $this->actingAs(User::factory()->operator()->create());
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));

        // Across every shop there is no week to edit, and nothing is written.
        Livewire::test(Screen::class)->assertSee(__('widget::ui.hours.pick_a_shop'))->call('save');

        $this->assertSame('09:00-18:00', Settings::get('widget.hours_sunday', $this->shop->id), 'untouched');
    }
}
