<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }"
        x-init="state = Number(state ?? 0)"
        class="flex items-center gap-3"
    >
        <button
            type="button"
            aria-label="{{ __('Decrease synergy') }}"
            class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600"
            x-on:click.prevent="state = Number(state ?? 0) - 1"
        >−</button>
        <input
            type="range"
            min="-1"
            max="1"
            step="1"
            aria-label="{{ __('Synergy level') }}"
            class="min-w-24 flex-1"
            x-bind:min="Math.min(-1, Number(state ?? 0))"
            x-bind:max="Math.max(1, Number(state ?? 0))"
            x-bind:style="{ accentColor: `hsl(${Math.max(0, Math.min(120, (Number(state ?? 0) + 1) * 60))}, 75%, 45%)` }"
            x-model.number="state"
        />
        <input
            type="number"
            step="1"
            aria-label="{{ __('Numeric synergy value') }}"
            class="w-20 rounded-lg border-gray-300 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900"
            x-model.number="state"
        />
        <button
            type="button"
            aria-label="{{ __('Increase synergy') }}"
            class="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-600"
            x-on:click.prevent="state = Number(state ?? 0) + 1"
        >+</button>
    </div>
</x-dynamic-component>
