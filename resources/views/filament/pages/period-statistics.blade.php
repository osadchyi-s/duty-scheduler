<x-filament-panels::page>
    <form wire:submit.prevent="$refresh" class="flex items-end gap-4 mb-6">
        {{ $this->form }}
        <x-filament::button type="submit">{{ __('Refresh report') }}</x-filament::button>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-400">{{ __('Total assignments in period') }}</div>
            <div class="text-2xl font-bold">{{ $this->exceptionRate['total'] }}</div>
        </div>
        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-400">{{ __('With warnings') }}</div>
            <div class="text-2xl font-bold text-amber-600">{{ $this->exceptionRate['with_warning'] }}</div>
        </div>
        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
            <div class="text-xs text-gray-400">{{ __('Exception rate') }}</div>
            <div class="text-2xl font-bold text-amber-600">{{ $this->exceptionRate['rate'] }}%</div>
        </div>
    </div>

    <div class="rounded-xl border bg-white dark:bg-gray-900 p-4 mb-6">
        <h3 class="font-semibold mb-3">{{ __('Workload by event type') }}</h3>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-400">
                    <th class="py-1">{{ __('Employee') }}</th>
                    <th class="py-1">{{ __('Event types (count)') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->workloadTable as $userName => $counts)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-2 font-medium">{{ $userName }}</td>
                        <td class="py-2 text-gray-500">
                            @foreach($counts as $type => $count)
                                <span class="inline-block mr-3">{{ $type }}: {{ $count }}</span>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="rounded-xl border bg-white dark:bg-gray-900 p-4">
        <h3 class="font-semibold mb-3">{{ __('Staffing deficit (below minimum capacity)') }}</h3>
        @if(count($this->deficitDates))
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400">
                        <th class="py-1">{{ __('Date') }}</th>
                        <th class="py-1">{{ __('Event type') }}</th>
                        <th class="py-1">{{ __('Assigned / Required') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->deficitDates as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800 text-red-600">
                            <td class="py-2">{{ $row['date'] }}</td>
                            <td class="py-2">{{ $row['event_type'] }}</td>
                            <td class="py-2">{{ $row['assigned'] }} / {{ $row['required'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-gray-400 text-sm">{{ __('No staffing deficits found for the selected period.') }}</p>
        @endif
    </div>
</x-filament-panels::page>
