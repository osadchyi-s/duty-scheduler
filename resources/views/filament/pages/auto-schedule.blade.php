<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 mb-6">
        <form wire:submit.prevent="generate">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-sparkles">
                    Згенерувати графік
                </x-filament::button>
                <span class="text-xs text-gray-400 ml-3">
                    Створює події зі статусом «draft» — нічого не потрапляє в графік без перегляду в Матриці.
                </span>
            </div>
        </form>
    </div>

    @if($result)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400">Створено призначень</div>
                <div class="text-2xl font-bold text-green-600">{{ $result['createdCount'] }}</div>
            </div>
            <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400">З попередженням про відпочинок ⚠️</div>
                <div class="text-2xl font-bold text-amber-600">{{ $result['warningCount'] }}</div>
            </div>
            <div class="rounded-xl border p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400">Незаповнені слоти (дефіцит)</div>
                <div class="text-2xl font-bold text-red-600">{{ $result['deficitCount'] }}</div>
            </div>
        </div>

        @if(count($result['deficits']))
            <div class="rounded-xl border bg-white dark:bg-gray-900 p-4 mb-6">
                <h3 class="font-semibold mb-3 text-red-600">Незаповнені слоти — потребують ручного втручання</h3>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-400">
                            <th class="py-1">Дата</th>
                            <th class="py-1">Тип події</th>
                            <th class="py-1">Заповнено / Потрібно</th>
                            <th class="py-1">Причина</th>
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
                <h3 class="font-semibold mb-3">Створені призначення</h3>
                <div class="max-h-96 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-white dark:bg-gray-900">
                            <tr class="text-left text-gray-400">
                                <th class="py-1">Дата</th>
                                <th class="py-1">Тип події</th>
                                <th class="py-1">Співробітник</th>
                                <th class="py-1">Статус</th>
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
                                            <span class="text-amber-600">⚠️ warning</span>
                                        @else
                                            <span class="text-green-600">optimal</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-xs text-gray-400">
                    Перегляньте та затвердіть результат у
                    <a href="{{ \App\Filament\Pages\DutyMatrix::getUrl() }}" class="text-primary-600 underline">Матриці чергувань</a>.
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
