<?php

namespace App\Modules\Shoppers\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Shoppers\Contracts\VisitHistory;
use App\Modules\Shoppers\Models\ShopperVisitor;
use Illuminate\Support\Facades\DB;

/**
 * The products a shopper looked at, counted from the page views the widget already reports. No
 * separate tracking, and the team's preview visits never count.
 */
final class Visits implements VisitHistory
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function topProducts(string $shopId, string $visitorHash, int $limit, ?string $except = null): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $visitorHash, $limit, $except): array {
            $link = ShopperVisitor::query()->where('visitor_hash', $visitorHash)->first();

            $days = (int) Settings::get($link === null ? 'shoppers.recent_days' : 'shoppers.recent_days_identified', $shopId);

            // Browsing follows a shopper between browsers only where they proved the contact is
            // theirs; otherwise typing someone else's phone would show that person's browsing.
            $hashes = $link !== null && $link->verified
                ? ShopperVisitor::query()->where('identity_id', $link->identity_id)->where('verified', true)->pluck('visitor_hash')->all()
                : [$visitorHash];

            return $this->count($hashes, $days, $limit, $except);
        });
    }

    public function topProductsForIdentity(string $shopId, int $identityId, int $limit): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $identityId, $limit): array {
            $hashes = ShopperVisitor::query()->where('identity_id', $identityId)->pluck('visitor_hash')->all();

            return $hashes === []
                ? []
                : $this->count($hashes, (int) Settings::get('shoppers.recent_days_identified', $shopId), $limit, null);
        });
    }

    public function state(string $shopId, string $visitorHash): ?array
    {
        return $this->tenant->run($shopId, function () use ($visitorHash): ?array {
            $link = ShopperVisitor::query()->with('identity')->where('visitor_hash', $visitorHash)->first();

            if ($link === null || $link->identity === null) {
                return null;
            }

            return [
                'channel' => $link->identity->channel,
                'masked' => $link->identity->contact_masked,
                'verified' => $link->verified,
            ];
        });
    }

    /**
     * @param  list<string>  $hashes
     * @return list<array{id: string, views: int, last_at: string}>
     */
    private function count(array $hashes, int $days, int $limit, ?string $except): array
    {
        return AnalyticsEvent::query()
            ->where('type', 'page_view')
            ->where('page_type', 'product')
            ->where('preview', false)
            ->whereIn('visitor_hash', $hashes)
            ->whereNotNull('product_external_id')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->when($except !== null, fn ($query) => $query->where('product_external_id', '!=', $except))
            ->select('product_external_id', DB::raw('count(*) as views'), DB::raw('max(occurred_at) as last_at'))
            ->groupBy('product_external_id')
            ->orderByDesc('views')
            ->orderByDesc(DB::raw('max(occurred_at)'))
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'id' => (string) $row->product_external_id,
                'views' => (int) $row->views,
                'last_at' => (string) $row->last_at,
            ])
            ->all();
    }
}
