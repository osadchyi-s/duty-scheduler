<x-filament-panels::page>
    <div x-data="{
            restZone: { userId: null, minUntil: null, preferredUntil: null },
            setRest(userId, minUntil, preferredUntil) {
                this.restZone = { userId: userId, minUntil: minUntil, preferredUntil: preferredUntil };
            },
            clearRest() { this.restZone = { userId: null, minUntil: null, preferredUntil: null }; },
            inMinZone(userId, dateIso) {
                return this.restZone.userId === userId && this.restZone.minUntil && dateIso < this.restZone.minUntil;
            },
            inPreferredZone(userId, dateIso) {
                return this.restZone.userId === userId && this.restZone.preferredUntil
                    && dateIso >= (this.restZone.minUntil ?? '') && dateIso < this.restZone.preferredUntil;
            }
         }"
         class="space-y-4"
    >
        {{-- Control panel: month + highlight toggles (spec 5.2.4) --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium">{{ __('Month:') }}</label>
                <input type="month" wire:model.live="month"
                       class="fi-input rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm" />
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
                <span class="w-3 h-3 rounded-sm border border-dashed border-gray-500 inline-block"
                      style="background-image: repeating-linear-gradient(135deg, rgba(100,100,100,.5) 0 2px, transparent 2px 4px)"></span>
                {{ __('Draft (D)') }}
            </span>
        </div>

        {{-- Matrix --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            {{-- table-fixed + w-full: side columns are fixed, day columns stretch evenly to fill the full width.
                 min-width keeps horizontal scroll on narrow screens (~36px per day). --}}
            <table class="w-full table-fixed text-[11px] border-collapse"
                   style="min-width: {{ 300 + count($this->days) * 36 }}px">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-gray-50 dark:bg-gray-800 border-r border-b border-gray-200 dark:border-gray-700 px-2 py-1.5 text-left w-[150px]">
                            {{ __('Employee') }}
                        </th>
                        @foreach($this->days as $day)
                            @php
                                $isWeekend = $day->isWeekend();
                            @endphp
                            <th class="border-b border-gray-200 dark:border-gray-700 px-1 py-1 text-center
                                       {{ $showHighlights['weekends'] && $isWeekend ? 'bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400' : '' }}">
                                {{ $day->format('d') }}<br>
                                <span class="text-[9px] text-gray-400">{{ $day->translatedFormat('D') }}</span>
                            </th>
                        @endforeach
                        <th class="border-b border-l border-gray-200 dark:border-gray-700 px-2 py-1.5 text-center w-[150px]">
                            {{ __('Total / Statistics') }}
                        </th>
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
                                    if ($ev->has_rest_warning) {
                                        $warningCount++;
                                    }
                                }
                            }
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="sticky left-0 z-10 bg-white dark:bg-gray-900 border-r border-b border-gray-200 dark:border-gray-700 px-2 py-1 font-medium whitespace-nowrap">
                                {{ $employee->name }}
                                <div class="text-[9px] text-gray-400 font-normal">{{ $employee->position }}</div>
                            </td>

                            @foreach($this->days as $day)
                                @php
                                    $dateKey = $day->format('Y-m-d');
                                    $dayEvents = $eventsIndex[$dateKey] ?? [];
                                    $isWeekend = $day->isWeekend();
                                @endphp
                                <td class="border-b border-gray-100 dark:border-gray-800 p-0.5 text-center align-middle
                                           {{ $showHighlights['weekends'] && $isWeekend ? 'bg-red-50/50 dark:bg-red-950/20' : '' }}"
                                    :class="{
                                        'bg-red-100 dark:bg-red-900/40': {{ $showHighlights['rest_zones'] ? 'true' : 'false' }} && inMinZone({{ $employee->id }}, '{{ $dateKey }}'),
                                        'bg-yellow-100 dark:bg-yellow-900/30': {{ $showHighlights['rest_zones'] ? 'true' : 'false' }} && inPreferredZone({{ $employee->id }}, '{{ $dateKey }}')
                                    }"
                                >
                                    @if(count($dayEvents))
                                        @foreach($dayEvents as $event)
                                            @php
                                                $minUntil = $event->ends_at->copy()->addHours($event->eventType->min_rest_hours)->format('Y-m-d');
                                                $prefUntil = $event->ends_at->copy()->addHours($event->eventType->preferred_rest_hours)->format('Y-m-d');
                                            @endphp
                                            @php
                                                $eventNote = $this->displayEventNote($event->note);
                                                $eventTitle = $event->warning_reason
                                                    ? '⚠️ ' . $event->warning_reason
                                                    : ($eventNote
                                                        ? __('Note: :note', ['note' => $eventNote])
                                                        : $event->eventType->name . ' (' . $event->starts_at->format('H:i') . '–' . $event->ends_at->format('H:i') . ')');
                                                    $eventTitle = ($event->status === 'draft' ? __('Draft — ') : __('Approved — ')) . $eventTitle;
                                                @endphp
                                            <button type="button"
                                                wire:click="mountAction('assign', { userId: {{ $employee->id }}, date: '{{ $dateKey }}', eventId: {{ $event->id }} })"
                                                @mouseenter="setRest({{ $employee->id }}, '{{ $minUntil }}', '{{ $prefUntil }}')"
                                                @mouseleave="clearRest()"
                                                class="w-full rounded px-0.5 py-0.5 text-white text-[9px] leading-tight relative {{ $event->status === 'draft' ? 'border border-dashed border-white/80' : '' }}"
                                                style="background-color: {{ $event->eventType->color_code }}; {{ $event->status === 'draft' ? 'background-image: repeating-linear-gradient(135deg, rgba(255,255,255,.35) 0 3px, transparent 3px 7px);' : '' }}"
                                                title="{{ $eventTitle }}"
                                            >
                                                {{ \Illuminate\Support\Str::limit($event->eventType->name, 6, '') }}
                                                @if($event->status === 'draft')
                                                    <span class="absolute bottom-0 right-0 rounded-tl bg-black/30 px-0.5 text-[8px] leading-none">D</span>
                                                @endif
                                                @if($showHighlights['warnings'] && $event->has_rest_warning)
                                                    <span class="absolute -top-1 -right-1">⚠️</span>
                                                @endif
                                            </button>
                                        @endforeach
                                    @else
                                        <button type="button"
                                            wire:click="mountAction('assign', { userId: {{ $employee->id }}, date: '{{ $dateKey }}' })"
                                            class="w-full h-5 rounded hover:bg-primary-100 dark:hover:bg-primary-900/30 text-gray-300">
                                            +
                                        </button>
                                    @endif
                                </td>
                            @endforeach

                            <td class="border-b border-l border-gray-100 dark:border-gray-800 px-2 py-1 text-[10px] whitespace-nowrap">
                                <div>{{ __(':hours hours', ['hours' => number_format($totalHours, 0)]) }}</div>
                                <div class="text-gray-400">
                                    @foreach($typeCounts as $code => $count)
                                        {{ $code }}: {{ $count }}@if(!$loop->last) | @endif
                                    @endforeach
                                </div>
                                @if($warningCount > 0)
                                    <div class="text-amber-600">{{ __('⚠️ exceptions: :count', ['count' => $warningCount]) }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    {{-- "Unassigned" summary row with staffing-deficit highlight (spec 5.2.3) --}}
                    <tr class="bg-gray-50 dark:bg-gray-800 font-medium">
                        <td class="sticky left-0 z-10 bg-gray-50 dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 px-2 py-1">
                            {{ __('Unassigned ⚠️') }}
                        </td>
                        @foreach($this->days as $day)
                            @php
                                $dateKey = $day->format('Y-m-d');
                                $deficit = false;
                                foreach ($this->eventTypes as $type) {
                                    if ($this->isUnderStaffed($day, $type)) {
                                        $deficit = true;
                                        break;
                                    }
                                }
                            @endphp
                            <td class="px-1 py-1 text-center
                                {{ $showHighlights['capacity'] && $deficit ? 'bg-red-200 dark:bg-red-900 text-red-700 dark:text-red-300' : '' }}">
                                {{ $deficit ? '⚠️' : '' }}
                            </td>
                        @endforeach
                        <td class="border-l border-gray-200 dark:border-gray-700"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Filament modal actions (Quick Assign) --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
