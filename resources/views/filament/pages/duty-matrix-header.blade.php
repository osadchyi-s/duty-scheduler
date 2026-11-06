{{-- Compact toolbar of the duty matrix (DutyMatrix::getHeader()): it replaces the default
     page header so that tabs, month, highlight and legend dropdowns and the actions share
     one row (wrapping on narrow screens). Styles are defined in duty-matrix.blade.php. --}}
<div class="dm-toolbar">
    {{-- Tabs: the approved schedule and the drafts of the month (Admin / Manager only) --}}
    @if($this->canManageDrafts())
        <x-filament::tabs class="dm-tabs">
            <x-filament::tabs.item
                :active="$this->draft === null"
                icon="heroicon-o-check-badge"
                wire:click="selectDraft(null)"
            >
                {{ __('Approved schedule') }}
            </x-filament::tabs.item>
            @foreach($this->drafts as $tabDraft)
                <x-filament::tabs.item
                    :active="$this->draft?->id === $tabDraft->id"
                    icon="heroicon-o-pencil-square"
                    wire:click="selectDraft({{ $tabDraft->id }})"
                    :badge="$tabDraft->isStale() ? __('Stale') : ($tabDraft->approved_at ? __('Approved') : null)"
                    :badge-color="$tabDraft->isStale() ? 'warning' : 'success'"
                    :badge-tooltip="$tabDraft->isStale()
                        ? __('Another draft was approved on :date', ['date' => $tabDraft->stale_at->format('d.m.Y H:i')])
                        : ($tabDraft->approved_at ? __('Approved on :date', ['date' => $tabDraft->approved_at->format('d.m.Y H:i')]) : null)"
                >
                    {{ $tabDraft->name }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
        <x-filament::icon-button
            icon="heroicon-m-plus"
            color="gray"
            :label="__('New draft')"
            :tooltip="__('New draft')"
            wire:click="createDraft"
        />
    @endif

    {{-- Native <select> controls instead of <input type="month">, which Firefox does not support. --}}
    <div class="dm-toolbar-group">
        <select wire:model.live="selectedMonth" aria-label="{{ __('Month:') }}"
                class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm dm-select">
            @foreach($this->monthOptions as $value => $name)
                <option value="{{ $value }}">{{ $name }}</option>
            @endforeach
        </select>
        <select wire:model.live="selectedYear" aria-label="{{ __('Year') }}"
                class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm dm-select">
            @foreach($this->yearOptions as $year)
                <option value="{{ $year }}">{{ $year }}</option>
            @endforeach
        </select>
    </div>

    {{-- Highlight toggles (spec 5.2.4) --}}
    <x-filament::dropdown placement="bottom-start">
        <x-slot name="trigger">
            <x-filament::button size="sm" color="gray" icon="heroicon-m-adjustments-horizontal">
                {{ __('Highlights') }}
            </x-filament::button>
        </x-slot>
        <div class="dm-dropdown-panel text-sm">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="showHighlights.rest_zones" class="rounded">
                {{ __('Rest periods') }}
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="showHighlights.warnings" class="rounded">
                {{ __('Conflicts / exceptions ⚠️') }}
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="showHighlights.capacity" class="rounded">
                {{ __('Dates with staffing deficits') }}
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="showHighlights.weekends" class="rounded">
                {{ __('Weekends / holidays') }}
            </label>
        </div>
    </x-filament::dropdown>

    {{-- Event type legend --}}
    <x-filament::dropdown placement="bottom-start">
        <x-slot name="trigger">
            <x-filament::button size="sm" color="gray" icon="heroicon-m-swatch">
                {{ __('Legend') }}
            </x-filament::button>
        </x-slot>
        <div class="dm-dropdown-panel text-xs">
            @foreach($this->eventTypes as $type)
                <span class="flex items-center gap-2">
                    <span class="dm-swatch" style="background-color: {{ $type->color_code }}"></span>
                    {{ $type->name }}
                </span>
            @endforeach
            <span class="flex items-center gap-2">
                <span class="dm-swatch" style="background-color: rgb(107 114 128); opacity: .4;"></span>
                {{ __('Past dates') }}
            </span>
        </div>
    </x-filament::dropdown>

    <div class="dm-toolbar-actions">
        <x-filament-actions::actions :actions="$this->getCachedHeaderActions()" />
    </div>
</div>
