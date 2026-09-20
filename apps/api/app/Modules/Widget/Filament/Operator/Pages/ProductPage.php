<?php

namespace App\Modules\Widget\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Widget\Actions\BuildPageBank;
use App\Modules\Widget\Models\WidgetCuration;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;

/**
 * One page of the store as the widget builds it, with why each circle and each product is there,
 * and the store team's say: pin a product first, hide one, hide a circle, or add a product code
 * did not pick. What the team decides overrides code and learning (BuildPageBank::curated()).
 */
final class ProductPage extends Page
{
    private const SEARCH_RESULTS = 12;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'widget/page';

    protected string $view = 'widget::operator.page';

    #[Url]
    public ?string $shop = null;

    #[Url]
    public string $type = 'product';

    #[Url]
    public ?string $id = null;

    public string $search = '';

    public string $addSearch = '';

    public ?string $addTo = null;

    public static function getNavigationLabel(): string
    {
        return __('widget::ui.page.title');
    }

    public function getTitle(): string
    {
        return __('widget::ui.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('widget::ui.page.subheading');
    }

    public function pick(string $shopId, string $type, string $externalId): void
    {
        $this->shop = $shopId;
        $this->type = $type;
        $this->id = $externalId;
        $this->search = '';
        $this->addTo = null;
    }

    /** @return Collection<int, array{shop_id: string, type: string, external_id: string, title: string, shop: string}> */
    public function matches(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return app(TenantContext::class)->runUnscoped(function () use ($term): Collection {
            $products = CatalogProduct::query()->with('shop:id,name')->whereNull('removed_at')
                ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term))
                ->orderBy('title')->limit(self::SEARCH_RESULTS)->get(['id', 'shop_id', 'external_id', 'title'])
                ->map(fn (CatalogProduct $p): array => ['shop_id' => $p->shop_id, 'type' => 'product', 'external_id' => $p->external_id, 'title' => $p->title, 'shop' => (string) $p->shop?->name]);
            $articles = CatalogContent::query()->with('shop:id,name')->whereNull('removed_at')
                ->where('title', 'like', "%{$term}%")
                ->orderBy('title')->limit(4)->get(['id', 'shop_id', 'external_id', 'title'])
                ->map(fn (CatalogContent $c): array => ['shop_id' => $c->shop_id, 'type' => 'content', 'external_id' => $c->external_id, 'title' => $c->title, 'shop' => (string) $c->shop?->name]);

            return $products->concat($articles)->values();
        });
    }

    /** Products to add into the section being edited. @return Collection<int, CatalogProduct> */
    public function addMatches(): Collection
    {
        $term = trim($this->addSearch);

        if ($this->shop === null || $this->addTo === null || mb_strlen($term) < 2) {
            return collect();
        }

        return app(TenantContext::class)->run($this->shop, fn () => CatalogProduct::query()->active()->where('in_stock', true)
            ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term))
            ->where('external_id', '!=', (string) $this->id)
            ->orderBy('title')->limit(self::SEARCH_RESULTS)->get(['id', 'external_id', 'title']));
    }

    /** @return array<string, mixed>|null */
    public function page(): ?array
    {
        if ($this->shop === null || $this->id === null) {
            return null;
        }

        $bank = app(BuildPageBank::class)->handle($this->shop, $this->type, $this->id, 'he', explain: true);
        $curations = app(TenantContext::class)->run($this->shop, fn () => WidgetCuration::query()->where('page_type', $this->type)->where('page_external_id', $this->id)->get());
        $subject = app(TenantContext::class)->run($this->shop, fn () => $this->type === 'product'
            ? CatalogProduct::query()->where('external_id', $this->id)->first()
            : CatalogContent::query()->where('external_id', $this->id)->first());
        $connection = app(TenantContext::class)->run($this->shop, fn () => StoreConnection::query()->latest()->first());

        return [
            'bank' => $bank,
            'subject' => $subject,
            'curations' => $curations,
            'hidden_sections' => $curations->where('action', WidgetCuration::HIDE)->where('item_external_id', '')->pluck('candidate')->all(),
            'pinned_sections' => $curations->where('action', WidgetCuration::PIN)->where('item_external_id', '')->pluck('candidate')->all(),
            'hidden_items' => $curations->where('action', WidgetCuration::HIDE)->where('item_external_id', '!=', '')->groupBy('candidate')->map(fn (Collection $c) => $c->pluck('item_external_id')->all())->all(),
            'pinned_items' => $curations->where('action', WidgetCuration::PIN)->where('item_external_id', '!=', '')->groupBy('candidate')->map(fn (Collection $c) => $c->pluck('item_external_id')->all())->all(),
            'preview_url' => $subject?->url && $connection ? $subject->url.(str_contains($subject->url, '?') ? '&' : '?').'rega_preview='.$connection->previewKey() : $subject?->url,
            'titles' => $this->titles($curations->pluck('item_external_id')->filter()->all()),
        ];
    }

    public function pin(string $candidate, string $item = ''): void
    {
        $this->decide($candidate, $item, WidgetCuration::PIN);
    }

    public function hide(string $candidate, string $item = ''): void
    {
        $this->decide($candidate, $item, WidgetCuration::HIDE);
    }

    /** Back to what code and learning decide. */
    public function clear(string $candidate, string $item = ''): void
    {
        $this->decide($candidate, $item, null);
    }

    public function startAdding(string $candidate): void
    {
        $this->addTo = $candidate;
        $this->addSearch = '';
    }

    public function add(string $externalId): void
    {
        if ($this->addTo !== null) {
            $this->decide($this->addTo, $externalId, WidgetCuration::PIN);
        }

        $this->addTo = null;
        $this->addSearch = '';
    }

    private function decide(string $candidate, string $item, ?string $action): void
    {
        if ($this->shop === null || $this->id === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, function () use ($candidate, $item, $action): void {
            $query = WidgetCuration::query()->where('page_type', $this->type)->where('page_external_id', $this->id)->where('candidate', $candidate)->where('item_external_id', $item);

            if ($action === null) {
                $query->delete();
            } else {
                WidgetCuration::query()->updateOrCreate(
                    ['shop_id' => $this->shop, 'page_type' => $this->type, 'page_external_id' => $this->id, 'candidate' => $candidate, 'item_external_id' => $item],
                    ['action' => $action, 'user_id' => Auth::id()],
                );
            }
        });

        // The storefront reads a cached page; the team's decision shows on the next load.
        foreach (BuildPageBank::LOCALES as $locale) {
            Cache::forget("widget:page:{$this->shop}:{$this->type}:{$this->id}:{$locale}");
        }

        Notification::make()->success()->title(__('widget::ui.page.saved'))->send();
    }

    /** @param list<string> $externalIds @return array<string, string> */
    private function titles(array $externalIds): array
    {
        if ($externalIds === [] || $this->shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($this->shop, fn () => CatalogProduct::query()->whereIn('external_id', $externalIds)->pluck('title', 'external_id')->all());
    }

    public function maxProducts(): int
    {
        return $this->shop === null ? 0 : (int) Settings::get('widget.max_products', $this->shop);
    }
}
