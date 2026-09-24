<x-filament-panels::page>
    @php($flow = $this->current())

    @if ($flow === null)
        <x-filament::section>
            <p>{{ __('leads::flow.none_yet') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;font-size:13px">
                <x-filament::badge color="success">{{ __('leads::flow.live', ['n' => $flow->version]) }}</x-filament::badge>
                <span>{{ __('leads::flow.goals.'.$flow->goal->value) }}</span>
                <span style="opacity:.7">{{ $flow->offer }}</span>
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top:14px">
            <x-filament::button type="submit">{{ __('leads::flow.save') }}</x-filament::button>
            <span style="margin-inline-start:10px;font-size:12px;opacity:.7">{{ __('leads::flow.save_note') }}</span>
        </div>
    </form>
</x-filament-panels::page>
