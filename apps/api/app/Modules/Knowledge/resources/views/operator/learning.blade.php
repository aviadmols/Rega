<x-filament-panels::page>
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:7px 10px;border-bottom:1px solid #f1f1f3;vertical-align:top')

    {{-- Every stage, what it does, and what it is waiting for. --}}
    <x-filament::section :heading="__('knowledge::learning.stages_title')" :description="__('knowledge::learning.stages_help')">
        <div style="display:grid;gap:10px">
            @foreach ($this->stages() as $stage)
                <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 12px;border-radius:10px;border:1px solid #e4e4e7;background:{{ $stage['state'] === 'running' ? '#f6fdf8' : '#fafafa' }}">
                    <div style="flex:none;padding-top:1px">
                        <x-filament::badge :color="$stage['state'] === 'running' ? 'success' : ($stage['state'] === 'planned' ? 'gray' : 'warning')">
                            {{ __('knowledge::learning.state.'.$stage['state']) }}
                        </x-filament::badge>
                    </div>
                    <div>
                        <div style="font-weight:600">{{ __('knowledge::learning.stages.'.$stage['key'].'.title') }}</div>
                        <div style="font-size:13px;line-height:1.6;margin-top:2px">{{ __('knowledge::learning.stages.'.$stage['key'].'.body') }}</div>
                        @if ($stage['detail'])
                            <div style="{{ $small }};margin-top:4px">{{ $stage['detail'] }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- What each shop's knowledge did over the week. --}}
    <x-filament::section :heading="__('knowledge::learning.week_title')" :description="__('knowledge::learning.week_help')">
        @php($weeks = $this->thisWeek())
        @if ($weeks === [])
            <p>{{ __('knowledge::learning.week_none') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <tr style="{{ $small }}">
                    <td style="{{ $cell }}">{{ __('knowledge::learning.columns.shop') }}</td>
                    <td style="{{ $cell }}">{{ __('knowledge::learning.columns.known') }}</td>
                    <td style="{{ $cell }}">{{ __('knowledge::learning.columns.gained') }}</td>
                    <td style="{{ $cell }}">{{ __('knowledge::learning.columns.following') }}</td>
                    <td style="{{ $cell }}">{{ __('knowledge::learning.columns.gaps') }}</td>
                </tr>
                @foreach ($weeks as $week)
                    <tr>
                        <td style="{{ $cell }}">{{ $week['shop']->name }}</td>
                        <td style="{{ $cell }};font-variant-numeric:tabular-nums">
                            {{ $week['known_share'] }}%
                            @if ($week['moved'] !== null && $week['moved'] !== 0)
                                <span style="color:{{ $week['moved'] > 0 ? '#15803d' : '#b91c1c' }}">{{ $week['moved'] > 0 ? '+' : '' }}{{ $week['moved'] }}</span>
                            @endif
                        </td>
                        <td style="{{ $cell }};{{ $small }}">
                            @if ($week['gained'] === null)
                                {{ __('knowledge::learning.first_week') }}
                            @else
                                {{ __('knowledge::learning.gained', [
                                    'code' => $week['gained']['read_in_code'],
                                    'model' => $week['gained']['read_by_model'],
                                    'articles' => $week['gained']['articles'],
                                ]) }}
                            @endif
                        </td>
                        <td style="{{ $cell }};{{ $small }}">
                            @if ($week['optimising_for'] === 'none')
                                {{ __('knowledge::learning.following_nothing') }}
                            @else
                                {{ __('knowledge::knowledge.signals.'.$week['optimising_for']) }}
                            @endif
                        </td>
                        <td style="{{ $cell }};font-variant-numeric:tabular-nums">{{ $week['gaps'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    {{-- Whether any of it helped. --}}
    <x-filament::section :heading="__('knowledge::learning.measured_title')" :description="__('knowledge::learning.measured_help')">
        @php($measurements = $this->measurements())
        @if ($measurements->isEmpty())
            <p>{{ __('knowledge::learning.measured_none') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                @foreach ($measurements as $measurement)
                    <tr>
                        <td style="{{ $cell }};{{ $small }}">{{ $measurement->taken_on->toDateString() }}</td>
                        <td style="{{ $cell }}">{{ __('knowledge::knowledge.verdict_lines.'.$measurement->verdict) }}</td>
                        <td style="{{ $cell }};{{ $small }}">
                            @foreach ($measurement->effect as $step => $row)
                                @if ($row['difference'] !== null)
                                    <div>{{ __('knowledge::knowledge.signals.'.$step) }}:
                                        {{ round(100 * $row['learned'], 1) }}% {{ __('knowledge::learning.versus') }} {{ round(100 * $row['control'], 1) }}%
                                        @unless ($row['enough']) <span style="opacity:.6">({{ __('knowledge::learning.thin') }})</span> @endunless
                                    </div>
                                @endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>

    {{-- What crosses between shops, and what a new shop of each trade inherits. --}}
    <x-filament::section :heading="__('knowledge::learning.trades_title')" :description="__('knowledge::learning.trades_help')">
        @foreach ($this->trades() as $trade)
            <div style="padding:10px 12px;border-radius:10px;border:1px solid #e4e4e7;margin-bottom:8px">
                <div style="font-weight:600">{{ __('knowledge::ui.verticals.'.$trade['vertical']->value) }}</div>
                <div style="{{ $small }}">{{ __('knowledge::learning.trade_shops', ['n' => $trade['shops']]) }}</div>
                <div style="font-size:13px;margin-top:4px">
                    @if ($trade['version'])
                        {{ __('knowledge::learning.trade_words', ['n' => $trade['words'], 'version' => $trade['version']]) }}
                    @else
                        {{ __('knowledge::learning.trade_nothing') }}
                    @endif
                </div>
                @if ($trade['priors'] !== [])
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
                        @foreach ($trade['priors'] as $candidate => $score)
                            <x-filament::badge color="gray">{{ __('widget::bank.chips.'.$candidate) }}</x-filament::badge>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </x-filament::section>

    <x-filament::section :heading="__('knowledge::learning.privacy_title')">
        <p style="font-size:13px;line-height:1.7">{{ __('knowledge::learning.privacy') }}</p>
    </x-filament::section>
</x-filament-panels::page>
