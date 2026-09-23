<?php

namespace App\Modules\Analytics\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\BuildShopReport;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** The same report a store sees in its plugin, for any shop. */
class ShopAnalytics extends Page
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
        // The shop the panel is inside, so every screen agrees; the first by name when it is
        // looking across every shop.
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
    {
        return true;
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
