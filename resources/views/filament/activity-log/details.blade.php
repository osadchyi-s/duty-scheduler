{{-- Details of an activity log entry. Field names and values are shown as stored.
     Inline styles: the panel CSS is prebuilt and has only the utilities Filament uses. --}}
@php
    use App\Filament\Resources\ActivityLogResource;

    $properties = $activity->properties;
    $attributes = $properties->get('attributes', []);
    $old = $properties->get('old', []);
    $relations = $properties->get('relations', []);
    $other = collect($properties->all())->except(['attributes', 'old', 'relations']);
    $cell = 'padding: .375rem .5rem; border-bottom: 1px solid rgb(229 231 235 / .6); vertical-align: top; text-align: left;';
    $head = $cell.' font-weight: 600; color: rgb(107 114 128);';
@endphp

<div class="space-y-4 text-sm">
    <table style="width: 100%; border-collapse: collapse;">
        <tr><th style="{{ $head }} width: 30%;">{{ __('Time') }}</th><td style="{{ $cell }}">{{ $activity->created_at->format('d.m.Y H:i:s') }}</td></tr>
        <tr><th style="{{ $head }}">{{ __('User') }}</th><td style="{{ $cell }}">{{ $activity->causer?->name ?? __('System') }}</td></tr>
        <tr><th style="{{ $head }}">{{ __('Area') }}</th><td style="{{ $cell }}">{{ ActivityLogResource::logNameLabels()[$activity->log_name] ?? $activity->log_name }}</td></tr>
        <tr><th style="{{ $head }}">{{ __('Object') }}</th><td style="{{ $cell }}">{{ ActivityLogResource::subjectLabel($activity) ?? '—' }}</td></tr>
    </table>

    @if($attributes !== [] || $old !== [])
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <th style="{{ $head }}">{{ __('Field') }}</th>
                <th style="{{ $head }}">{{ __('Old value') }}</th>
                <th style="{{ $head }}">{{ __('New value') }}</th>
            </tr>
            @foreach(array_unique(array_merge(array_keys($old), array_keys($attributes))) as $field)
                <tr>
                    <td style="{{ $cell }} font-family: monospace;">{{ $field }}</td>
                    <td style="{{ $cell }}">{{ array_key_exists($field, $old) ? ActivityLogResource::formatValue($old[$field]) : '—' }}</td>
                    <td style="{{ $cell }}">{{ array_key_exists($field, $attributes) ? ActivityLogResource::formatValue($attributes[$field]) : '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if($relations !== [])
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <th style="{{ $head }}">{{ __('Relation') }}</th>
                <th style="{{ $head }}">{{ __('Old value') }}</th>
                <th style="{{ $head }}">{{ __('New value') }}</th>
            </tr>
            @foreach($relations as $relation => $change)
                <tr>
                    <td style="{{ $cell }} font-family: monospace;">{{ $relation }}</td>
                    <td style="{{ $cell }}">{{ implode(', ', $change['old'] ?? []) ?: '—' }}</td>
                    <td style="{{ $cell }}">{{ implode(', ', $change['new'] ?? []) ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if($other->isNotEmpty())
        <table style="width: 100%; border-collapse: collapse;">
            @foreach($other as $key => $value)
                <tr>
                    <th style="{{ $head }} width: 30%; font-family: monospace;">{{ $key }}</th>
                    <td style="{{ $cell }}">{{ ActivityLogResource::formatValue($value) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
