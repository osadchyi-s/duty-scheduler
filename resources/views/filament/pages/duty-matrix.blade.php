<x-filament-panels::page class="dm-page">
    {{-- Filament's precompiled CSS contains only the utilities Filament itself uses (the project
         has no Tailwind build step): no border-r and no red/yellow colors. Column separators and
         the highlight colors are therefore defined here, for light and dark themes. --}}
    <style>
        /* Compact page: smaller gaps than Filament's default page layout, on this page only. */
        .dm-page > section { row-gap: .5rem; padding-top: .75rem; padding-bottom: .5rem; }
        .fi-main:has(.dm-page) { padding-left: 1rem; padding-right: 1rem; }

        /* Toolbar (duty-matrix-header.blade.php). */
        .dm-toolbar         { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
        .dm-toolbar-group   { display: flex; align-items: center; gap: .375rem; }
        .dm-toolbar-actions { display: flex; align-items: center; gap: .5rem; margin-left: auto; }
        .dm-tabs            { padding: .25rem; }
        .dm-select          { padding-top: .3rem; padding-bottom: .3rem; }
        .dm-dropdown-panel  { display: flex; flex-direction: column; gap: .5rem; padding: .75rem; }
        .dm-swatch          { display: inline-block; width: .75rem; height: .75rem; border-radius: .2rem; flex-shrink: 0; }

        /* Matrix: small text and tight cells, so many employees fit on one screen. The sizes grow
           on wide monitors; adjust them here (laptops / >= 1600px / >= 2200px). */
        .dm-table { --dm-font: 11px; --dm-event-font: 10px; --dm-small-font: 9px; --dm-cell-pad: 1px; --dm-add-height: 14px; }
        @media (min-width: 1600px) {
            .dm-table { --dm-font: 11.9px; --dm-event-font: 10.9px; --dm-small-font: 9.5px; --dm-cell-pad: 2px; --dm-add-height: 16px; }
        }
        @media (min-width: 2200px) {
            .dm-table { --dm-font: 13.3px; --dm-event-font: 12.35px; --dm-small-font: 10.45px; --dm-cell-pad: 3px; --dm-add-height: 19px; }
        }
        .dm-scroller   { overflow: auto; border: 1px solid rgb(229 231 235); border-radius: .75rem; background-color: #fff; }
        .dark .dm-scroller { border-color: rgb(55 65 81); background-color: rgb(17 24 39); }
        .dm-table      { width: 100%; border-collapse: separate; border-spacing: 0; font-size: var(--dm-font); line-height: 1.25; }
        .dm-th         { padding: 2px 3px; font-weight: 600; text-align: center; }
        .dm-td-name    { padding: var(--dm-cell-pad) 6px; font-weight: 500; white-space: nowrap; }
        .dm-cell       { padding: var(--dm-cell-pad); text-align: center; vertical-align: middle; }
        .dm-dow        { display: block; font-size: var(--dm-small-font); font-weight: 400; color: rgb(156 163 175); }
        .dm-event      { position: relative; display: block; width: 100%; padding: 1px 2px; border-radius: .25rem;
                         font-size: var(--dm-event-font); line-height: 1.2; color: #fff; white-space: nowrap; }
        .dm-event-warn { position: absolute; top: -5px; right: -4px; font-size: var(--dm-small-font); }
        /* Cell borders (separate border model, so sticky cells keep their borders while scrolling). */
        .dm-table th, .dm-table td { border-bottom: 1px solid rgb(243 244 246); }
        .dark .dm-table th, .dark .dm-table td { border-bottom-color: rgb(31 41 55); }

        /* Dates stay visible at the top and the "Unassigned" row at the bottom while scrolling. */
        :where(.dm-table thead th) { background-color: #fff; }
        :where(.dark .dm-table thead th) { background-color: rgb(17 24 39); }
        .dm-table thead th { position: sticky; top: 0; z-index: 20; box-shadow: inset 0 -1px 0 rgb(229 231 235); }
        .dm-foot td        { position: sticky; bottom: 0; z-index: 20; box-shadow: inset 0 1px 0 rgb(229 231 235); }
        .dm-table thead th.dm-col-num, .dm-table thead th.dm-col-name,
        .dm-foot td.dm-col-num, .dm-foot td.dm-col-name { z-index: 30; }

        /* "Total / Statistics" column (hidden while $showStatsColumn is false). */
        .dm-stats      { border-left: 1px solid rgb(229 231 235); }
        .dark .dm-stats{ border-left-color: rgb(55 65 81); }
        .dm-vline      { border-right: 1px solid rgb(243 244 246); } /* gray-100 */
        .dark .dm-vline{ border-right-color: rgb(31 41 55); }        /* gray-800 */
        .dm-vline-head      { border-right: 1px solid rgb(229 231 235); } /* gray-200 */
        .dark .dm-vline-head{ border-right-color: rgb(55 65 81); }        /* gray-700 */
        /* Number and employee columns stay visible while scrolling the days horizontally. */
        .dm-col-num  { position: sticky; left: 0; z-index: 10; width: 2.25rem; min-width: 2.25rem; text-align: right;
                       color: rgb(156 163 175); font-variant-numeric: tabular-nums; }
        .dm-col-name { position: sticky; left: 2.25rem; z-index: 10; }
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
        .dm-add                { display: block; width: 100%; height: var(--dm-add-height); line-height: var(--dm-add-height);
                                 border-radius: .25rem; color: rgb(209 213 219); }
        .dm-add:hover          { background-color: rgb(var(--primary-100)); color: rgb(var(--primary-600)); }
        .dark .dm-add:hover    { background-color: rgb(var(--primary-900) / .3); color: rgb(var(--primary-400)); }
        /* Past dates: dimmed like inactive employees. Only the content is dimmed, so the
           backgrounds (weekends, rest zones, deficits) stay opaque in the sticky rows. */
        .dm-past-head > *   { opacity: .45; }
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
            },
            {{-- The matrix scrolls inside its own box that fills the rest of the window,
                 so the sticky dates and the 'Unassigned' row are always visible. --}}
            fitHeight() {
                const top = this.$refs.scroller.getBoundingClientRect().top;
                this.$refs.scroller.style.maxHeight = Math.max(240, window.innerHeight - top - 12) + 'px';
            }
         }"
         x-init="$nextTick(() => fitHeight()); Livewire.hook('commit', ({ succeed }) => succeed(() => $nextTick(() => fitHeight())))"
         x-on:resize.window.debounce.100ms="fitHeight()"
    >
        {{-- Matrix. wire:ignore.self keeps the height set by fitHeight() across Livewire updates.
             min-width keeps horizontal scroll on narrow screens (~36px per day). --}}
        <div x-ref="scroller" wire:ignore.self class="dm-scroller">
            <table class="dm-table"
                   style="min-width: {{ (36 + 150 + ($showStatsColumn ? 150 : 0)) + count($this->days) * 36 }}px">
                <thead>
                    <tr>
                        <th class="dm-vline-head dm-col-num dm-th bg-gray-50 dark:bg-gray-800">
                            {{ __('No.') }}
                        </th>
                        <th class="dm-vline-head dm-col-name dm-th bg-gray-50 dark:bg-gray-800 text-left">
                            {{ __('Employee') }}
                        </th>
                        @foreach($this->days as $day)
                            @php
                                $isWeekend = $day->isWeekend();
                                $deficitTooltip = $showHighlights['capacity'] ? $this->staffingDeficitTooltip($day) : '';
                                $headTooltip = trim(($this->isPastDay($day) ? __('Past date') : '')."\n".$deficitTooltip);
                            @endphp
                            <th class="dm-vline-head dm-th
                                       {{ $showHighlights['weekends'] && $isWeekend ? 'dm-weekend-head' : '' }}
                                       {{ $deficitTooltip !== '' ? 'dm-deficit-head' : '' }}
                                       {{ $this->isPastDay($day) ? 'dm-past-head' : '' }}"
                                @if($headTooltip !== '') title="{{ $headTooltip }}" @endif
                            >
                                <span>{{ $day->format('d') }}<span class="dm-dow">{{ $day->translatedFormat('D') }}</span></span>
                            </th>
                        @endforeach
                        @if($showStatsColumn)
                            <th class="dm-th dm-stats" style="width: 150px;">
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
                            <td class="dm-vline-head dm-col-num dm-td-name bg-white dark:bg-gray-900">
                                {{ $loop->iteration }}
                            </td>
                            <td class="dm-vline-head dm-col-name dm-td-name bg-white dark:bg-gray-900"
                                @unless($employee->is_active) title="{{ __('Inactive employee: existing events are shown, new ones cannot be assigned.') }}" @endunless
                            >
                                {{-- Inactive employees are listed only while they still have events in the period. --}}
                                <span @unless($employee->is_active) style="color: rgb(156 163 175); text-decoration: line-through;" @endunless>{{ $employee->name }}</span>@if(filled($employee->position))<span class="text-gray-400 font-normal"> ({{ $employee->position }})</span>@endif
                                @unless($employee->is_active)
                                    <span style="font-size: 9px; font-weight: 600; padding: 0 .3rem; border-radius: .25rem; color: rgb(107 114 128); background: rgb(229 231 235);">{{ __('Inactive') }}</span>
                                @endunless
                            </td>

                            @foreach($this->days as $day)
                                @php
                                    $dateKey = $day->format('Y-m-d');
                                    $dayEvents = $eventsIndex[$dateKey] ?? [];
                                    $isWeekend = $day->isWeekend();
                                @endphp
                                <td class="dm-vline dm-cell
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
                                                class="dm-event"
                                                style="background-color: {{ $event->eventType->color_code }};{{ $employee->is_active ? '' : ' opacity: .45;' }}"
                                                title="{{ $eventTitle }}"
                                            >
                                                {{ \Illuminate\Support\Str::limit($event->eventType->name, 6, '') }}
                                                @if($showHighlights['warnings'] && $eventWarnings !== [])
                                                    <span class="dm-event-warn">⚠️</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    @elseif($employee->is_active)
                                        <button type="button"
                                            wire:click="mountAction('assign', { userId: {{ $employee->id }}, date: '{{ $dateKey }}' })"
                                            class="dm-add">
                                            +
                                        </button>
                                    @endif
                                </td>
                            @endforeach

                            @if($showStatsColumn)
                                <td class="dm-td-name dm-stats" style="font-size: 10px;">
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
                    <tr class="dm-foot font-medium">
                        <td class="dm-vline-head dm-col-num dm-th bg-gray-50 dark:bg-gray-800"></td>
                        <td class="dm-vline-head dm-col-name dm-th bg-gray-50 dark:bg-gray-800 text-left">
                            {{ __('Unassigned ⚠️') }}
                        </td>
                        @foreach($this->days as $day)
                            @php
                                $deficit = $this->staffingDeficits($day) !== [];
                            @endphp
                            <td class="dm-vline dm-th bg-gray-50 dark:bg-gray-800
                                {{ $showHighlights['capacity'] && $deficit ? 'dm-deficit' : '' }}
                                {{ $this->isPastDay($day) ? 'dm-past-head' : '' }}"
                                @if($deficit) title="{{ $this->staffingDeficitTooltip($day) }}" @endif
                            >
                                <span>{{ $deficit ? '⚠️' : '' }}</span>
                            </td>
                        @endforeach
                        @if($showStatsColumn)
                            <td class="dm-th dm-stats bg-gray-50 dark:bg-gray-800"></td>
                        @endif
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Filament modal actions (Quick Assign) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
