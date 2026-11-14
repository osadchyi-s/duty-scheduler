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
        .dm-table { --dm-event-min-font: 8px; --dm-font: 11px; --dm-event-font: 10px; --dm-small-font: 9px; --dm-cell-pad: 1px; --dm-row-height: 18px; }
        @media (min-width: 1600px) {
            .dm-table { --dm-font: 11.9px; --dm-event-font: 10.9px; --dm-small-font: 9.5px; --dm-cell-pad: 2px; --dm-row-height: 21px; }
        }
        @media (min-width: 2200px) {
            .dm-table { --dm-font: 13.3px; --dm-event-font: 12.35px; --dm-small-font: 10.45px; --dm-cell-pad: 3px; --dm-row-height: 25px; }
        }
        .dm-scroller   { overflow: auto; border: 1px solid rgb(229 231 235); border-radius: .75rem; background-color: #fff; }
        .dark .dm-scroller { border-color: rgb(55 65 81); background-color: rgb(17 24 39); }
        .dm-table      { width: 100%; border-collapse: separate; border-spacing: 0; font-size: var(--dm-font); line-height: 1.25; }
        .dm-th         { padding: 2px 3px; font-weight: 600; text-align: center; }
        .dm-td-name    { padding: var(--dm-cell-pad) 6px; font-weight: 500; white-space: nowrap; }
        /* Day cells: the events (or the empty "add" button) fill the whole cell; several events
           of one day share its height. The row height is set here, not by the cell content. */
        .dm-cell       { position: relative; height: var(--dm-row-height); padding: 0; text-align: center; vertical-align: middle; }
        .dm-cell-fill  { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 1px; }
        .dm-dow        { display: block; font-size: var(--dm-small-font); font-weight: 400; color: rgb(156 163 175); }
        .dm-event      { position: relative; flex: 1 1 0; min-height: 0; display: flex; align-items: center; justify-content: center;
                         padding: 0 2px; overflow: hidden; font-size: var(--dm-event-font); line-height: 1.1; color: #fff; white-space: nowrap; }
        /* The label shrinks down to --dm-event-min-font to fit its cell (fitLabels() below), and
           only then is cut off with an ellipsis. */
        .dm-event-label { display: block; min-width: 0; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
        .dm-event-warn { position: absolute; top: 0; right: 1px; font-size: var(--dm-small-font); line-height: 1; }
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

        /* "Statistics" column: days per event type, pinned to the right edge while scrolling. */
        .dm-stats      { position: sticky; right: 0; z-index: 10; padding: var(--dm-cell-pad) 4px; white-space: nowrap;
                         border-left: 1px solid rgb(229 231 235); background-color: #fff; }
        .dark .dm-stats{ border-left-color: rgb(55 65 81); background-color: rgb(17 24 39); }
        .dm-table thead th.dm-stats, .dm-foot td.dm-stats { z-index: 30; }
        .dm-stat-list  { display: flex; gap: 2px; }
        .dm-stat       { display: inline-flex; justify-content: center; min-width: 1.6em; padding: 0 3px; border-radius: 3px;
                         font-size: var(--dm-small-font); font-weight: 600; line-height: 1.5; color: #fff; }
        /* Button that hides an employee row: shown on row hover, over the end of the name cell. */
        .dm-hide-row   { position: absolute; right: 2px; top: 50%; transform: translateY(-50%); display: flex; padding: 1px;
                         border-radius: .25rem; opacity: 0; color: rgb(156 163 175); background-color: inherit; }
        tr:hover .dm-hide-row { opacity: 1; }
        .dm-hide-row:hover    { color: rgb(var(--primary-600)); }
        .dm-show-rows  { display: inline-flex; align-items: center; gap: 2px; margin-left: .5rem; padding: 0 .3rem; border-radius: .25rem;
                         font-weight: 500; color: rgb(var(--primary-600)); background-color: rgb(var(--primary-50)); }
        .dark .dm-show-rows { color: rgb(var(--primary-400)); background-color: rgb(var(--primary-400) / .1); }
        .dm-icon-xs    { width: .875rem; height: .875rem; }
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
        /* White separators before Saturday and after Sunday, in both themes, so the weekend
           columns stand apart. The .dm-table prefix beats ".dark .dm-vline", whose right border
           they replace after Sunday. */
        .dm-table .dm-weekend-start { border-left: 1px solid #fff; }
        .dm-table .dm-weekend-end   { border-right: 1px solid #fff; }
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
        .dm-add                { flex: 1 1 0; width: 100%; }
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
            {{-- Employees hidden for this page view only; a page reload shows them again. --}}
            hiddenRows: [],
            hideRow(userId) { if (! this.hiddenRows.includes(userId)) this.hiddenRows.push(userId); },
            hiddenRowsCss() {
                return this.hiddenRows.map((id) => `.dm-table tr[data-employee='${id}'] { display: none; }`).join('\n');
            },
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
            },
            {{-- Event labels that do not fit their cell get a smaller font, down to --dm-event-min-font;
                 what still does not fit is cut off by CSS. Text width grows linearly with the font
                 size, so one measurement per label is enough. All resets, then all reads, then all
                 writes: one layout pass instead of one per label. --}}
            fitLabels() {
                const labels = [...this.$refs.scroller.querySelectorAll('.dm-event-label')];
                if (labels.length === 0) return;
                labels.forEach((label) => label.style.fontSize = '');
                const table = this.$refs.scroller.querySelector('.dm-table');
                const minSize = parseFloat(getComputedStyle(table).getPropertyValue('--dm-event-min-font')) || 8;
                const baseSize = parseFloat(getComputedStyle(labels[0]).fontSize);
                const sizes = labels.map((label) => label.scrollWidth > label.clientWidth
                    ? Math.max(minSize, Math.floor(baseSize * label.clientWidth / label.scrollWidth * 2) / 2)
                    : null);
                labels.forEach((label, i) => { if (sizes[i] !== null) label.style.fontSize = sizes[i] + 'px'; });
            },
            fitAll() {
                this.fitHeight();
                this.fitLabels();
            }
         }"
         x-init="$nextTick(() => fitAll());
                 document.fonts?.ready.then(() => fitLabels());
                 Livewire.hook('commit', ({ succeed }) => succeed(() => $nextTick(() => fitAll())))"
         x-on:resize.window.debounce.100ms="fitAll()"
    >
        {{-- Matrix. wire:ignore.self keeps the height set by fitHeight() across Livewire updates.
             min-width keeps horizontal scroll on narrow screens (~36px per day). --}}
        {{-- Hidden rows are hidden by a generated stylesheet rather than x-show on the rows: Livewire
             re-renders the rows on every update, and wire:ignore keeps this element untouched. --}}
        <style wire:ignore x-text="hiddenRowsCss()"></style>
        <div x-ref="scroller" wire:ignore.self class="dm-scroller">
            <table class="dm-table"
                   style="min-width: {{ (36 + 150 + ($showStatsColumn ? 120 : 0)) + count($this->days) * 36 }}px">
                <thead>
                    <tr>
                        <th class="dm-vline-head dm-col-num dm-th bg-gray-50 dark:bg-gray-800">
                            {{ __('No.') }}
                        </th>
                        <th class="dm-vline-head dm-col-name dm-th bg-gray-50 dark:bg-gray-800 text-left">
                            {{ __('Employee') }}
                            <span wire:ignore>
                                <button type="button" class="dm-show-rows" x-show="hiddenRows.length > 0" x-cloak
                                        x-on:click="hiddenRows = []" title="{{ __('Show hidden employees') }}">
                                    <x-filament::icon icon="heroicon-m-eye" class="dm-icon-xs" />
                                    {{ __('Show hidden') }} (<span x-text="hiddenRows.length"></span>)
                                </button>
                            </span>
                        </th>
                        @foreach($this->days as $day)
                            @php
                                $isWeekend = $day->isWeekend();
                                $deficitTooltip = $showHighlights['capacity'] ? $this->staffingDeficitTooltip($day) : '';
                                $headTooltip = trim(($this->isPastDay($day) ? __('Past date') : '')."\n".$deficitTooltip);
                            @endphp
                            <th class="dm-vline-head dm-th
                                       {{ $showHighlights['weekends'] && $isWeekend ? 'dm-weekend-head' : '' }}
                                       {{ $this->weekendEdgeClasses($day) }}
                                       {{ $deficitTooltip !== '' ? 'dm-deficit-head' : '' }}
                                       {{ $this->isPastDay($day) ? 'dm-past-head' : '' }}"
                                @if($headTooltip !== '') title="{{ $headTooltip }}" @endif
                            >
                                <span>{{ $day->format('d') }}<span class="dm-dow">{{ $day->translatedFormat('D') }}</span></span>
                            </th>
                        @endforeach
                        @if($showStatsColumn)
                            <th class="dm-th dm-stats" title="{{ __('Days of each event type in the month') }}">
                                {{ __('Statistics') }}
                            </th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @php
                        $days = $this->days;
                    @endphp
                    @foreach($this->employees as $employee)
                        @php
                            $eventsIndex = $this->eventsIndexFor($employee);
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50" data-employee="{{ $employee->id }}">
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
                                <button type="button" class="dm-hide-row" x-on:click="hideRow({{ $employee->id }})"
                                        title="{{ __('Hide the employee until the page is reloaded') }}" aria-label="{{ __('Hide employee') }}">
                                    <x-filament::icon icon="heroicon-m-eye-slash" class="dm-icon-xs" />
                                </button>
                            </td>

                            @foreach($this->rowCells($days, $eventsIndex) as $cell)
                                @php
                                    // A cell covers one day, or several days of one multi-day event.
                                    $day = $cell['days'][0];
                                    $lastDay = end($cell['days']);
                                    $span = count($cell['days']);
                                    $dateKey = $day->format('Y-m-d');
                                    $dayEvents = $cell['events'];
                                @endphp
                                <td class="dm-vline dm-cell
                                           {{ $showHighlights['weekends'] && $day->isWeekend() && $lastDay->isWeekend() ? 'dm-weekend' : '' }}
                                           {{ $this->weekendEdgeClasses($day, $lastDay) }}
                                           {{ $this->isPastDay($lastDay) ? 'dm-past' : '' }}"
                                    @if($span > 1) colspan="{{ $span }}" @else
                                    {{-- A merged cell is covered by its event, so a rest zone would not show there. --}}
                                    :class="{
                                        'dm-rest-min': {{ $showHighlights['rest_zones'] ? 'true' : 'false' }} && inMinZone({{ $employee->id }}, '{{ $dateKey }}'),
                                        'dm-rest-preferred': {{ $showHighlights['rest_zones'] ? 'true' : 'false' }} && inPreferredZone({{ $employee->id }}, '{{ $dateKey }}')
                                    }"
                                    @endif
                                >
                                    <div class="dm-cell-fill">
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
                                                <span class="dm-event-label">{{ $event->eventType->name }}</span>
                                                @if($showHighlights['warnings'] && $eventWarnings !== [])
                                                    <span class="dm-event-warn">⚠️</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    @elseif($employee->is_active)
                                        {{-- Empty cell: no "+" sign, but the whole cell adds an event. --}}
                                        <button type="button"
                                            wire:click="mountAction('assign', { userId: {{ $employee->id }}, date: '{{ $dateKey }}' })"
                                            class="dm-add"
                                            aria-label="{{ __('Assign event') }}"
                                        ></button>
                                    @endif
                                    </div>
                                </td>
                            @endforeach

                            @if($showStatsColumn)
                                <td class="dm-stats">
                                    <div class="dm-stat-list">
                                        @foreach($this->eventDaysByType($employee, $days) as $stat)
                                            <span class="dm-stat" style="background-color: {{ $stat['type']->color_code }};"
                                                  title="{{ $stat['type']->name }}: {{ $stat['days'] }}">{{ $stat['days'] }}</span>
                                        @endforeach
                                    </div>
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
                                {{ $this->weekendEdgeClasses($day) }}
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
