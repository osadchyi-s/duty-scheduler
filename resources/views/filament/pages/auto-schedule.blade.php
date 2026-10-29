<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 mb-6">
        <form
            wire:submit.prevent="generate"
            wire:confirm="{{ __('Run auto-generation? If a delete mode is selected, previous assignments will be permanently deleted.') }}"
        >
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-sparkles">
                    {{ __('Generate schedule') }}
                </x-filament::button>
                <span class="text-xs text-gray-400 ml-3">
                    {{ __('Creates drafts — they will not appear in the schedule until reviewed in the matrix.') }}
                </span>
            </div>
        </form>
    </div>

    @if($result)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400">{{ __('Assignments created') }}</div>
                <div class="text-2xl font-bold text-green-600">{{ $result['createdCount'] }}</div>
            </div>
            <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400">{{ __('With warnings ⚠️') }}</div>
                <div class="text-2xl font-bold text-amber-600">{{ $result['warningCount'] }}</div>
            </div>
            <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400">{{ __('Unfilled slots (deficit)') }}</div>
                <div class="text-2xl font-bold text-red-600">{{ $result['deficitCount'] }}</div>
            </div>
        </div>
        @if($result['deletedCount'] > 0)
            <div class="rounded-xl border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/30 p-4 mb-6 text-sm text-amber-800 dark:text-amber-200">
                {{ __('Previous assignments deleted: :count.', ['count' => $result['deletedCount']]) }}
            </div>
        @endif

        @if(count($result['deficits']))
            <div class="rounded-xl border bg-white dark:bg-gray-900 p-4 mb-6">
                <h3 class="font-semibold mb-3 text-red-600">{{ __('Unfilled slots — manual intervention required') }}</h3>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-400">
                            <th class="py-1">{{ __('Date') }}</th>
                            <th class="py-1">{{ __('Event type') }}</th>
                            <th class="py-1">{{ __('Filled / Required') }}</th>
                            <th class="py-1">{{ __('Reason') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($result['deficits'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-2">{{ $row['date'] }}</td>
                                <td class="py-2">{{ $row['eventType'] }}</td>
                                <td class="py-2 text-red-600">{{ $row['filled'] }} / {{ $row['needed'] }}</td>
                                <td class="py-2 text-gray-500 text-xs">{{ $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(count($result['created']))
            <div class="rounded-xl border bg-white dark:bg-gray-900 p-4">
                <h3 class="font-semibold mb-3">{{ __('Created assignments') }}</h3>
                <div class="max-h-96 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-white dark:bg-gray-900">
                            <tr class="text-left text-gray-400">
                                <th class="py-1">{{ __('Date') }}</th>
                                <th class="py-1">{{ __('Event type') }}</th>
                                <th class="py-1">{{ __('Employee') }}</th>
                                <th class="py-1">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($result['created'] as $row)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-2">{{ $row['date'] }}</td>
                                    <td class="py-2">{{ $row['eventType'] }}</td>
                                    <td class="py-2">{{ $row['user'] }}</td>
                                    <td class="py-2">
                                        @if($row['status'] === 'warning')
                                            <span class="text-amber-600">⚠️ {{ __('Warning') }}</span>
                                        @else
                                            <span class="text-green-600">{{ __('Optimal') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-xs text-gray-400">
                    {{ __('Drafts can be approved individually or all at once in the duty matrix.') }}
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
