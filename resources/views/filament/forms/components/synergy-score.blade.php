{{-- Inline styles are used because the panel CSS is prebuilt and may not contain arbitrary Tailwind color classes. --}}
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }"
        role="radiogroup"
        class="flex items-center gap-2"
    >
        @foreach ($scoreColors as $score => $color)
            <button
                type="button"
                role="radio"
                aria-label="{{ $score }}"
                x-bind:aria-checked="String(state ?? '0') === '{{ $score }}'"
                x-on:click.prevent="state = '{{ $score }}'"
                class="transition"
                style="width: 3rem; height: 2rem; border-radius: .5rem; background-color: {{ $color }};"
                x-bind:style="String(state ?? '0') === '{{ $score }}'
                    ? 'width: 3rem; height: 2rem; border-radius: .5rem; background-color: {{ $color }}; outline: 2px solid {{ $color }}; outline-offset: 2px;'
                    : 'width: 3rem; height: 2rem; border-radius: .5rem; background-color: {{ $color }}; opacity: .3;'"
            ></button>
        @endforeach
    </div>
</x-dynamic-component>
