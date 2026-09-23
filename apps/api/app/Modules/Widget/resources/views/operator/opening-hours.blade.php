<x-filament-panels::page>
    @php($shop = $this->shopId())
    @php($open = $this->openNow())

    @if ($shop === null)
        <x-filament::section>
            <p style="font-size:14px">{{ __('widget::ui.hours.pick_a_shop') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div style="display:flex;align-items:center;gap:10px">
                <span @style([
                    'display:inline-block;width:10px;height:10px;border-radius:50%;flex:none',
                    'background:#16a34a' => $open === true,
                    'background:#d4d4d8' => $open !== true,
                ])></span>
                <span style="font-size:14px;font-weight:600">
                    @if ($open === true)
                        {{ __('widget::ui.hours.open_now') }}
                    @elseif ($open === false)
                        {{ __('widget::ui.hours.closed_now') }}
                    @else
                        {{ __('widget::ui.hours.never_open') }}
                    @endif
                </span>
                <span style="font-size:12px;opacity:.7">{{ __('widget::ui.hours.measured_in') }}</span>
            </div>
        </x-filament::section>

        <form wire:submit="save">
            {{ $this->form }}

            <div style="margin-top:14px">
                <x-filament::button type="submit">{{ __('widget::ui.hours.save') }}</x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
