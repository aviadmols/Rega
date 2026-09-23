<x-filament-panels::page>
    @php($now = $this->now())
    @php($shop = $this->shop())
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:6px 10px;border-bottom:1px solid #f1f1f3')
    @php($was = $this->weekAgo())

    @if ($now === [] || $shop === null)
        <p style="opacity:.7">{{ __('knowledge::ui.pick_a_shop') }}</p>
    @else
        @php($coverage = $now['coverage'])
        @php($catalog = $coverage['catalog'])

        {{-- What the shop is, and how much of it is known at all. --}}
        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
                <div>
                    <div style="font-size:34px;font-weight:700;line-height:1">{{ $coverage['known_share'] }}%</div>
                    <div style="{{ $small }}">{{ __('knowledge::ui.known_share') }}</div>
                    @if ($was !== null)
                        @php($delta = $coverage['known_share'] - ($was['known_share'] ?? 0))
                        <div style="{{ $small }};color:{{ $delta > 0 ? '#15803d' : ($delta < 0 ? '#b91c1c' : 'inherit') }}">
                            {{ __('knowledge::ui.week_ago', ['n' => $was['known_share'] ?? 0]) }}
                        </div>
                    @endif
                </div>
                <div style="flex:1;min-width:260px">
                    <div>{{ __('knowledge::ui.scanned', [
                        'products' => number_format($catalog['products']),
                        'articles' => number_format($catalog['articles']),
                        'pages' => number_format($catalog['pages']),
                    ]) }}</div>
                    <div style="{{ $small }};margin-top:4px">
                        {{ __('knowledge::ui.vertical') }}:
                        @if ($shop->vertical)
                            {{ __('knowledge::ui.verticals.'.$shop->vertical->value) }}
                            <span style="opacity:.7">({{ __('knowledge::ui.confidence', ['n' => $shop->vertical_confidence]) }})</span>
                        @else
                            {{ __('knowledge::ui.vertical_unknown') }}
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- What the learning is being judged on. Nothing else on the screen matters as much. --}}
        <x-filament::section :heading="__('knowledge::ui.signals_title')" :description="__('knowledge::ui.signals_help', ['days' => $now['signals']['window_days']])">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                @foreach ($now['signals']['counts'] as $name => $count)
                    @php($verdict = $now['signals']['verdicts'][$name])
                    <tr>
                        <td style="{{ $cell }}">{{ __('knowledge::knowledge.signals.'.$name) }}</td>
                        <td style="{{ $cell }};text-align:end;font-variant-numeric:tabular-nums">{{ number_format($count) }}</td>
                        <td style="{{ $cell }};width:1%">
                            <x-filament::badge :color="$verdict === 'alive' ? 'success' : ($verdict === 'weak' ? 'warning' : 'danger')">
                                {{ __('knowledge::knowledge.verdicts.'.$verdict) }}
                            </x-filament::badge>
                        </td>
                    </tr>
                @endforeach
            </table>
            <p style="margin-top:10px">
                @if ($now['signals']['optimising_for'] === 'none')
                    <strong>{{ __('knowledge::ui.optimising_none') }}</strong>
                @else
                    <strong>{{ __('knowledge::ui.optimising_for', ['signal' => __('knowledge::knowledge.signals.'.$now['signals']['optimising_for'])]) }}</strong>
                @endif
            </p>
            <p style="{{ $small }}">{{ __('knowledge::ui.signals_note') }}</p>
        </x-filament::section>

        {{-- What is missing: the only part of the screen that says what to do next. --}}
        <x-filament::section :heading="__('knowledge::ui.gaps_title')" :description="__('knowledge::ui.gaps_help')">
            @if ($now['gaps'] === [])
                <p>{{ __('knowledge::ui.no_gaps') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    @foreach ($now['gaps'] as $gap)
                        <tr>
                            <td style="{{ $cell }};width:1%;text-align:end;font-variant-numeric:tabular-nums">
                                @if ($gap['count'] > 0){{ number_format($gap['count']) }}@endif
                            </td>
                            <td style="{{ $cell }}">{{ __('knowledge::knowledge.gaps.'.$gap['key']) }}</td>
                            <td style="{{ $cell }};{{ $small }}">
                                @if ($gap['fix'])
                                    {{ __('knowledge::ui.closed_by', ['step' => __('knowledge::knowledge.steps.'.$gap['fix'])]) }}
                                @else
                                    {{ __('knowledge::ui.nothing_closes_this') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </x-filament::section>

        {{-- The seven layers, in the order they happen. --}}
        <x-filament::section :heading="__('knowledge::ui.layers_title')" :description="__('knowledge::ui.layers_help')">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.scanned') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.scanned_short', ['products' => number_format($catalog['products']), 'articles' => number_format($catalog['articles']), 'pages' => number_format($catalog['pages'])]) }}</td>
                </tr>
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.read_in_code') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.of_products', ['n' => number_format($coverage['read_in_code']['products']), 'share' => $coverage['read_in_code']['share']]) }}</td>
                </tr>
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.read_by_model') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.of_products', ['n' => number_format($coverage['read_by_model']['products']), 'share' => $coverage['read_by_model']['share']]) }}</td>
                </tr>
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.decided') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.facts', ['n' => number_format($coverage['decided_by_people']['facts'])]) }}</td>
                </tr>
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.computed') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.computed', [
                        'rankings' => number_format($coverage['computed']['rankings']),
                        'relations' => number_format($coverage['computed']['relations']),
                        'popular' => number_format($coverage['computed']['popular']),
                    ]) }}</td>
                </tr>
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.articles') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.of_articles', ['n' => number_format($coverage['articles_with_points']['articles']), 'share' => $coverage['articles_with_points']['share']]) }}</td>
                </tr>
                <tr>
                    <td style="{{ $cell }}">{{ __('knowledge::ui.layers.asked') }}</td>
                    <td style="{{ $cell }};text-align:end">{{ __('knowledge::ui.questions', [
                        'answered' => number_format($coverage['questions']['answered']),
                        'no_info' => number_format($coverage['questions']['no_info']),
                        'refused' => number_format($coverage['questions']['refused']),
                    ]) }}</td>
                </tr>
            </table>

            @if ($coverage['by_kind'] !== [])
                <p style="margin-top:12px;{{ $small }}">{{ __('knowledge::ui.by_kind') }}</p>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                    @foreach ($coverage['by_kind'] as $kind => $count)
                        <x-filament::badge color="gray">{{ __('enrichment::enrichment.fact_kinds.'.$kind) }}: {{ number_format($count) }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- When each step last ran, so an old reading can be told from a fresh one. --}}
        <x-filament::section :heading="__('knowledge::ui.freshness_title')" :description="__('knowledge::ui.freshness_help')">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                @foreach ($this->steps() as $step)
                    @php($at = $now['freshness'][$step] ?? null)
                    @php($stale = $at !== null && \Illuminate\Support\Carbon::parse($at)->lt(now()->subDays($this->staleAfterDays())))
                    <tr>
                        <td style="{{ $cell }}">{{ __('knowledge::knowledge.steps.'.$step) }}</td>
                        <td style="{{ $cell }};text-align:end;{{ $small }}">
                            @if ($at === null)
                                <x-filament::badge color="danger">{{ __('knowledge::ui.never') }}</x-filament::badge>
                            @else
                                {{ \Illuminate\Support\Carbon::parse($at)->diffForHumans() }}
                                @if ($stale)
                                    <x-filament::badge color="warning">{{ __('knowledge::ui.stale') }}</x-filament::badge>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>

        {{-- What this adds up to on the page a shopper sees. --}}
        <x-filament::section :heading="__('knowledge::ui.arrangement_title')" :description="__('knowledge::ui.arrangement_help')">
            @if (($now['arrangement']['learned_order'] ?? []) === [])
                <p>{{ __('knowledge::ui.arrangement_default') }}</p>
            @else
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                    @foreach ($now['arrangement']['learned_order'] as $i => $candidate)
                        <x-filament::badge :color="$i === 0 ? 'success' : 'gray'">{{ $i + 1 }}. {{ __('widget::bank.chips.'.$candidate) }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <p style="{{ $small }}">{{ __('knowledge::ui.privacy') }}</p>
    @endif
</x-filament-panels::page>
