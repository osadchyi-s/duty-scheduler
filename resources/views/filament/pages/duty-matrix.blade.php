<x-filament-panels::page>
    {{-- Filament's precompiled CSS contains only the utilities Filament itself uses (the project
         has no Tailwind build step): no border-r and no red/yellow colors. Column separators and
         the highlight colors are therefore defined here, for light and dark themes. --}}
    <style>
        .dm-vline      { border-right: 1px solid rgb(243 244 246); } /* gray-100 */
        .dark .dm-vline{ border-right-color: rgb(31 41 55); }        /* gray-800 */
        .dm-vline-head      { border-right: 1px solid rgb(229 231 235); } /* gray-200 */
        .dark .dm-vline-head{ border-right-color: rgb(55 65 81); }        /* gray-700 */
        .dark td.dm-vline   { border-bottom-color: rgb(31 41 55); }       /* dark:border-gray-800 */
        /* Weekends (Saturday, Sunday): a neutral, lighter tone than weekdays and a lighter date. */
        .dm-weekend-head       { background-color: rgb(249 250 251); color: rgb(156 163 175); } /* gray-50 / gray-400 */
        .dark .dm-weekend-head { background-color: rgb(255 255 255 / .04); color: rgb(107 114 128); }
        .dm-weekend            { background-color: rgb(249 250 251); }                          /* gray-50 */
        .dark .dm-weekend      { background-color: rgb(255 255 255 / .03); }
        /* Rest zones on hover; defined after weekends so they win on weekend cells. */
        .dm-rest-min            { background-color: rgb(254 202 202); }                         /* red-200 */
        .dark .dm-rest-min      { background-color: rgb(153 27 27 / .55); }
        .dm-rest-preferred      { background-color: rgb(254 240 138); }                         /* yellow-200 */
        .dark .dm-rest-preferred{ background-color: rgb(133 77 14 / .45); }
        /* Staffing deficit in the "Unassigned" row. */
        .dm-deficit            { background-color: rgb(254 202 202); color: rgb(185 28 28); }
        .dark .dm-deficit      { background-color: rgb(127 29 29); color: rgb(252 165 165); }
        /* Header cell of an understaffed day (visible without scrolling to the bottom row);
           defined after weekends so it wins on weekend days. */
        .dm-deficit-head       { background-color: rgb(220 38 38); color: #fff; font-weight: 700; } /* red-600 */
        .dm-deficit-head span  { color: rgb(254 226 226); }
        .dark .dm-deficit-head { background-color: rgb(185 28 28); }
        /* "+" button of an empty cell. */
        .dm-add                { color: rgb(209 213 219); }
        .dm-add:hover          { background-color: rgb(var(--primary-100)); color: rgb(var(--primary-600)); }
        .dark .dm-add:hover    { background-color: rgb(var(--primary-900) / .3); color: rgb(var(--primary-400)); }
        /* Past dates: dimmed like inactive employees; the cell background (weekends, rest zones) stays visible. */
        .dm-past-head       { opacity: .45; }
        .dm-past > *        { opacity: .5; }
    </style>

    <div x-data="{
            {{-- Rest after the hovered event: [from, minUntil) is required rest, [minUntil, preferredUntil)
                 is preferred rest. Dates are Y-m-d strings, ends exclusive (see DutyMatrix::restZone()). --}}
            restZone: { userId: null, from: null, minUntil: null, preferredUntil: null },
            setRest(userId, from, minUntil, preferredUntil) {
                this.restZone = { userId: userId, from: from, minUntil: minUntil, preferredUntil: preferredUntil };
            },
            clearRest() { this.restZone = { userId: null, from: null, minUntil: null, preferredUntil: null }; },
            inMinZone(userId, dateIso) {
                const zone = this.restZone;
                return zone.userId === userId && dateIso >= zone.from && dateIso < zone.minUntil;
            },
            inPreferredZone(userId, dateIso) {
                const zone = this.restZone;
                return zone.userId === userId && dateIso >= zone.minUntil && dateIso < zone.preferredUntil;
            }
         }"
         class="space-y-4"
    >
        {{-- Tabs: the approved schedule and the drafts of the month (Admin / Manager only) --}}
        @if($this->canManageDrafts())
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::tabs>
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
                <x-filament::button size="sm" color="gray" icon="heroicon-m-plus" wire:click="createDraft">
                    {{ __('New draft') }}
                </x-filament::button>
            </div>
        @endif

        {{-- Control panel: month + highlight toggles (spec 5.2.4) --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            {{-- Native <select> controls instead of <input type="month">, which Firefox does not support. --}}
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium">{{ __('Month:') }}</label>
                <select wire:model.live="selectedMonth"
                        class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                    @foreach($this->monthOptions as $value => $name)
                        <option value="{{ $value }}">{{ $name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="selectedYear"
                        class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                    @foreach($this->yearOptions as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-4 text-sm">
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
        </div>

        {{-- Event type legend --}}
        <div class="flex flex-wrap gap-3 text-xs">
            @foreach($this->eventTypes as $type)
                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700">
                    <span class="w-3 h-3 rounded-sm inline-block" style="background-color: {{ $type->color_code }}"></span>
                    {{ $type->name }}
                </span>
            @endforeach
            <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md border border-gray-200 dark:border-gray-700">
                <span class="w-3 h-3 rounded-sm inline-block" style="background-color: rgb(107 114 128); opacity: .4;"></span>
                {{ __('Past dates') }}
            </span>
        </div>

        {{-- Matrix --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            {{-- table-fixed + w-full: side columns are fixed, day columns stretch evenly to fill the full width.
                 min-width keeps horizontal scroll on narrow screens (~36px per day). --}}
            <table class="w-full table-fixed text-[11px] border-collapse"
                   style="min-width: {{ (150 + ($showStatsColumn ? 150 : 0)) + count($this->days) * 36 }}px">
                <thead>
                    <tr>
                        <th class="dm-vline-head sticky left-0 z-10 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-2 py-1.5 text-left w-[150px]">
                            {{ __('Employee') }}
                        </th>
                        @foreach($this->days as $day)
                            @php
                                $isWeekend = $day->isWeekend();
                                $deficitTooltip = $showHighlights['capacity'] ? $this->staffingDeficitTooltip($day) : '';
                                $headTooltip = trim(($this->isPastDay($day) ? __('Past date') : '')."\n".$deficitTooltip);
                            @endphp
                            <th class="dm-vline-head border-b border-gray-200 dark:border-gray-700 px-1 py-1 text-center
                                       {{ $showHighlights['weekends'] && $isWeekend ? 'dm-weekend-head' : '' }}
                                       {{ $deficitTooltip !== '' ? 'dm-deficit-head' : '' }}
                                       {{ $this->isPastDay($day) ? 'dm-past-head' : '' }}"
                                @if($headTooltip !== '') title="{{ $headTooltip }}" @endif
                            >
                                {{ $day->format('d') }}<br>
                                <span class="text-[9px] text-gray-400">{{ $day->translatedFormat('D') }}</span>
                            </th>
                        @endforeach
                        @if($showStatsColumn)
                            <th class="border-b border-l border-gray-200 dark:border-gray-700 px-2 py-1.5 text-center w-[150px]">
                                {{ __('Total / Statistics') }}
                            </th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->employees as $employee)
                        @php
                            $eventsIndex = $this->eventsIndexFor($employee);
                            $totalHours = 0;
                            $typeCounts = [];
                            $warningCount = 0;
                            $seenEventIds = [];
                            foreach ($eventsIndex as $dayEvents) {
                                foreach ($dayEvents as $ev) {
                                    if (isset($seenEventIds[$ev->id])) {
                                        continue;
                                    }

                                    $seenEventIds[$ev->id] = true;

                                    if ($ev->eventType->is_work_time) {
                                        $totalHours += $ev->durationHours();
                                    }
                                    $typeCounts[$ev->eventType->code] = ($typeCounts[$ev->eventType->code] ?? 0) + 1;
                                    if ($this->eventWarnings($ev) !== []) {
                                        $warningCount++;
                                    }
                                }
                            }
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="dm-vline-head sticky left-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-2 py-1 font-medium whitespace-nowrap"
                                @unless($employee->is_active) title="{{ __('Inactive employee: existing events are shown, new ones cannot be assigned.') }}" @endunless
                            >
                                {{-- Inactive employees are listed only while they still have events in the period. --}}
                                <span @unless($employee->is_active) style="color: rgb(156 163 175); text-decoration: line-through;" @endunless>{{ $employee->name }}</span>
                                @unless($employee->is_active)
                                    <span style="font-size: 9px; font-weight: 600; padding: 0 .3rem; border-radius: .25rem; color: rgb(107 114 128); background: rgb(229 231 235);">{{ __('Inactive') }}</span>
                                @endunless
                                <div class="text-[9px] text-gray-400 font-normal">{{ $employee->position }}</div>
                            </td>

                            @foreach($this->days as $day)
                                @php
                                    $dateKey = $day->format('Y-m-d');
                                    $dayEvents = $eventsIndex[$dateKey] ?? [];
                                    $isWeekend = $day->isWeekend();
                                @endphp
                                <td class="dm-vline border-b border-gray-100 dark:border-gray-800 p-0.5 text-center align-middle
                                           {{ $showHighlights['weekends'] && $isWeekend ? 'dm-weekend' : '' }}
                                           {{ $this->isPastDay($day) ? 'dm-past' : '' }}"
                                    :class="{
                                        'dm-rest-min': {{ $showHighlights['rest_zones'] ? 'true' : 'false' }} && inMinZone({{ $employee->id }}, '{{ $dateKey }}'),
                                        'dm-rest-preferred': {{ $showHighlights['rest_zones'] ? 'true' : 'false' }} && inPreferredZone({{ $employee->id }}, '{{ $dateKey }}')
                                    }"
                                >
                                    @if(count($dayEvents))
                                        @foreach($dayEvents as $event)
                                            @php
                                                $restZone = $this->restZone($event);
                                            @endphp
                                            @php
                                                $eventNote = $this->displayEventNote($event->note);
                                                $eventWarnings = $this->eventWarnings($event);
                                                $eventTitle = $eventWarnings !== []
                                                    ? '⚠️ ' . implode(' ', $eventWarnings)
                                                    : ($eventNote
                                                        ? __('Note: :note', ['note' => $eventNote])
                                                        : $event->eventType->name . ' (' . \App\Support\TimeUnit::formatPeriod($event->starts_at, $event->ends_at) . ')');
                                                @endphp
                                            <button type="button"
                                                wire:click="mountAction('assign', { userId: {{ $employee->id }}, date: '{{ $dateKey }}', eventId: {{ $event->id }} })"
                                                @mouseenter="setRest({{ $employee->id }}, '{{ $restZone['from'] }}', '{{ $restZone['minUntil'] }}', '{{ $restZone['preferredUntil'] }}')"
                                                @mouseleave="clearRest()"
                                                class="w-full rounded px-0.5 py-0.5 text-white text-[9px] leading-tight relative"
                                                style="background-color: {{ $event->eventType->color_code }};{{ $employee->is_active ? '' : ' opacity: .45;' }}"
                                                title="{{ $eventTitle }}"
                                            >
                                                {{ \Illuminate\Support\Str::limit($event->eventType->name, 6, '') }}
                                                @if($showHighlights['warnings'] && $eventWarnings !== [])
                                                    <span class="absolute -top-1 -right-1">⚠️</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    @elseif($employee->is_active)
                                        <button type="button"
                                            wire:click="mountAction('assign', { userId: {{ $employee->id }}, date: '{{ $dateKey }}' })"
                                            class="dm-add w-full h-5 rounded">
                                            +
                                        </button>
                                    @endif
                                </td>
                            @endforeach

                            @if($showStatsColumn)
                                <td class="border-b border-l border-gray-100 dark:border-gray-800 px-2 py-1 text-[10px] whitespace-nowrap">
                                    <div>{{ \App\Support\TimeUnit::format(round($totalHours)) }}</div>
                                    <div class="text-gray-400">
                                        @foreach($typeCounts as $code => $count)
                                            {{ $code }}: {{ $count }}@if(!$loop->last) | @endif
                                        @endforeach
                                    </div>
                                    @if($warningCount > 0)
                                        <div style="color: rgb(217 119 6);">{{ __('⚠️ exceptions: :count', ['count' => $warningCount]) }}</div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach

                    {{-- "Unassigned" summary row with staffing-deficit highlight (spec 5.2.3) --}}
                    <tr class="bg-gray-50 dark:bg-gray-800 font-medium">
                        <td class="dm-vline-head sticky left-0 z-10 bg-gray-50 dark:bg-gray-800 px-2 py-1">
                            {{ __('Unassigned ⚠️') }}
                        </td>
                        @foreach($this->days as $day)
                            @php
                                $deficit = $this->staffingDeficits($day) !== [];
                            @endphp
                            <td class="dm-vline px-1 py-1 text-center
                                {{ $showHighlights['capacity'] && $deficit ? 'dm-deficit' : '' }}
                                {{ $this->isPastDay($day) ? 'dm-past-head' : '' }}"
                                @if($deficit) title="{{ $this->staffingDeficitTooltip($day) }}" @endif
                            >
                                {{ $deficit ? '⚠️' : '' }}
                            </td>
                        @endforeach
                        @if($showStatsColumn)
                            <td class="border-l border-gray-200 dark:border-gray-700"></td>
                        @endif
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Filament modal actions (Quick Assign) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
