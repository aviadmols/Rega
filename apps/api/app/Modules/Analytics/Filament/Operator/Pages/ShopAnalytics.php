<?php

namespace App\Modules\Analytics\Filament\Operator\Pages;

use App\Modules\Analytics\Actions\BuildShopReport;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** The same report a store sees in its plugin, for any shop. */
final class ShopAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'analytics';

    protected string $view = 'analytics::operator.report';

    #[Url]
    public ?string $shop = null;

    #[Url]
    public int $days = 30;

    public static function getNavigationLabel(): string
    {
        return __('analytics::analytics.title');
    }

    public function getTitle(): string
    {
        return __('analytics::analytics.title');
    }

    public function mount(): void
    {
        $this->shop ??= Shop::query()->orderBy('name')->value('id');
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        return $this->shop === null ? null : app(BuildShopReport::class)->handle($this->shop, $this->days);
    }
}
