<x-filament-panels::page>
    @php($areas = $this->areas())
    @php($store = $this->storeAreas())
    @php($current = $areas[$this->area] ?? null)

    <div style="display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;align-items:start">
        <nav style="display:flex;flex-direction:column;gap:2px;position:sticky;top:16px">
            @foreach ($areas as $key => $area)
                @if ($loop->index === count($store) && count($store) > 0)
                    <div style="margin:10px 0 4px;padding:0 10px;font-size:11px;font-weight:600;color:#9ca3af">{{ __('admin::configuration.tabs.advanced') }}</div>
                @elseif ($loop->first && count($store) > 0)
                    <div style="margin:0 0 4px;padding:0 10px;font-size:11px;font-weight:600;color:#9ca3af">{{ __('admin::configuration.tabs.shop') }}</div>
                @endif

                <button type="button" wire:click="openArea('{{ $key }}')"
                        @style([
                            'text-align:start;padding:7px 10px;border-radius:8px;font-size:13px;line-height:1.3;border:1px solid transparent',
                            'background:#f4f4f5;font-weight:600' => $this->area === $key,
                        ])>
                    {{ $area['title'] }}
                    <span style="float:inline-end;font-size:11px;color:#9ca3af">{{ count($area['keys']) }}</span>
                </button>
            @endforeach
        </nav>

        <div>
            @if ($current)
                <x-filament::section :heading="$current['title']" :description="$current['help']">
                    <form wire:submit="save">
                        {{ $this->form }}

                        <div style="margin-top:14px">
                            <x-filament::button type="submit">{{ __('admin::configuration.save') }}</x-filament::button>
                        </div>
                    </form>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
