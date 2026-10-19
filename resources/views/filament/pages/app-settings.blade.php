<x-filament-panels::page>
    <form wire:submit.prevent="save" class="max-w-xl space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-check">
            {{ __('Save settings') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
