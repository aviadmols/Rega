<x-filament-panels::page>
    @php($report = $this->report())
    @php($pct = fn ($v) => $v === null ? '–' : number_format($v * 100, 1).'%')

    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        @if ($this->picksShop())
        <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;min-width:220px">
            @foreach ($this->shops() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        @endif
        <select wire:model.live="days" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px">
            @foreach (\App\Modules\Analytics\Actions\BuildShopReport::PERIODS as $period)
                <option value="{{ $period }}">{{ __('analytics::analytics.last_days', ['days' => $period]) }}</option>
            @endforeach
        </select>
    </div>

    @if ($report === null)
        <x-filament::section>{{ __('analytics::analytics.no_shop') }}</x-filament::section>
    @else
        @php($t = $report['totals'])
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px">
            @foreach ([
                'page_views' => number_format($t['page_views']),
                'visitors' => number_format($t['visitors']),
                'impressions' => number_format($t['impressions']),
                'opens' => number_format($t['opens']).' · '.$pct($t['open_rate']),
                'widget_add_to_cart' => number_format($t['widget_add_to_cart']),
                'orders' => number_format($t['orders']),
                'assisted_orders' => number_format($t['assisted_orders']),
                'attributed_revenue' => '₪'.number_format($t['attributed_revenue'], 2),
            ] as $key => $value)
                <x-filament::section compact>
                    <div style="font-size:12px;opacity:.7">{{ __("analytics::analytics.totals.{$key}") }}</div>
                    <div style="font-size:22px;font-weight:600;margin-top:4px" dir="ltr">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>

        @if ($t['preview_events'] > 0)
            <p style="font-size:13px;opacity:.75">{{ __('analytics::analytics.preview_note', ['count' => number_format($t['preview_events'])]) }}</p>
        @endif

        <x-filament::section :heading="__('analytics::analytics.sections.hot_pages')">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <thead><tr style="text-align:start;opacity:.7">
                    <th style="text-align:start;padding:6px">{{ __('analytics::analytics.columns.page') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.views') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.impressions') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.opens') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.add_to_cart') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($report['hot_pages'] as $page)
                    <tr style="border-top:1px solid #e4e4e7">
                        <td style="padding:6px">{{ $page['title'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $page['views'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $page['impressions'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $page['opens'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $page['add_to_cart'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:6px;opacity:.7">{{ __('analytics::analytics.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section :heading="__('analytics::analytics.sections.hot_models')">
            <table style="width:100%;font-size:14px;border-collapse:collapse">
                <thead><tr style="opacity:.7">
                    <th style="text-align:start;padding:6px">{{ __('analytics::analytics.columns.model') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.impressions') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.opens') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.clicks') }}</th>
                    <th style="padding:6px">{{ __('analytics::analytics.columns.add_to_cart') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($report['hot_models'] as $model)
                    <tr style="border-top:1px solid #e4e4e7">
                        <td style="padding:6px">{{ \Illuminate\Support\Facades\Lang::has("analytics::analytics.models.{$model['model']}") ? __("analytics::analytics.models.{$model['model']}") : $model['model'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $model['impressions'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $model['opens'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $model['clicks'] }}</td>
                        <td style="padding:6px;text-align:center">{{ $model['add_to_cart'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:6px;opacity:.7">{{ __('analytics::analytics.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
